<?php
/**
 * Statusengine UI
 * Copyright (C) 2016-2017  Daniel Ziegler
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Statusengine\Loader\Crate;

use Crate\PDO\PDO as PDO;
use Statusengine\Backend\Crate\Crate;
use Statusengine\Backend\StorageBackend;
use Statusengine\Loader\ServiceLoaderInterface;
use Statusengine\PerfdataParser;
use Statusengine\ValueObjects\ServiceQueryOptions;

class ServiceLoader implements ServiceLoaderInterface {

    /**
     * @var StorageBackend
     */
    private $StorageBackend;

    /**
     * @var Crate
     */
    private $Backend;

    /**
     * ServiceLoader constructor.
     * @param StorageBackend $StorageBackend
     */
    public function __construct(StorageBackend $StorageBackend) {
        $this->Backend = $StorageBackend->getBackend();
    }

    /**
     * @param ServiceQueryOptions $ServiceQueryOptions
     * @return array
     */
    public function getServiceList(ServiceQueryOptions $ServiceQueryOptions) {
        $baseQuery = 'SELECT * FROM statusengine_servicestatus';
        $useStateFilter = false;
        if ($ServiceQueryOptions->sizeOfStateFilter() > 0 && $ServiceQueryOptions->sizeOfStateFilter() < 4) {
            $useStateFilter = true;
            $baseQuery = sprintf('%s WHERE current_state IN(%s)', $baseQuery, implode(',', $ServiceQueryOptions->getStateFilter()));
        }

        if ($ServiceQueryOptions->getHostnameLike() != '') {
            $sql = ($useStateFilter) ? 'AND' : 'WHERE';
            $useStateFilter = true;
            $baseQuery = sprintf(' %s %s hostname ~* ? ', $baseQuery, $sql);
        }

        if ($ServiceQueryOptions->getServicedescriptionLike() != '') {
            $sql = ($useStateFilter) ? 'AND' : 'WHERE';
            $useStateFilter = true;
            $baseQuery = sprintf(' %s %s service_description ~* ? ', $baseQuery, $sql);
        }

        if ($ServiceQueryOptions->getIsAcknowledged() === true) {
            $sql = ($useStateFilter) ? 'AND' : 'WHERE';
            $useStateFilter = true;
            $baseQuery = sprintf(' %s %s problem_has_been_acknowledged=true ', $baseQuery, $sql);
        }

        if ($ServiceQueryOptions->getIsAcknowledged() === false) {
            $sql = ($useStateFilter) ? 'AND' : 'WHERE';
            $useStateFilter = true;
            $baseQuery = sprintf(' %s %s problem_has_been_acknowledged=false ', $baseQuery, $sql);
        }

        if ($ServiceQueryOptions->getIsInDowntime() === true) {
            $sql = ($useStateFilter) ? 'AND' : 'WHERE';
            $useStateFilter = true;
            $baseQuery = sprintf(' %s %s scheduled_downtime_depth > 0 ', $baseQuery, $sql);
        }

        if ($ServiceQueryOptions->getIsInDowntime() === false) {
            $sql = ($useStateFilter) ? 'AND' : 'WHERE';
            $useStateFilter = true;
            $baseQuery = sprintf(' %s %s scheduled_downtime_depth=0 ', $baseQuery, $sql);
        }

        $baseQuery .= $this->getClusterNameQuery($ServiceQueryOptions, !$useStateFilter);

        $baseQuery = sprintf(
            '%s ORDER BY %s %s LIMIT ? OFFSET ?',
            $baseQuery,
            $ServiceQueryOptions->getOrder(),
            $ServiceQueryOptions->getDirection()
        );

        $query = $this->Backend->prepare($baseQuery);

        $i = 1;
        if ($ServiceQueryOptions->getHostnameLike() != '') {
            //Pitfall! bindParam gets the value as reference, so dont touch $_like after it was set!
            $_like = sprintf('.*%s.*', $ServiceQueryOptions->getHostnameLike());
            $query->bindParam($i++, $_like); //
        }

        if ($ServiceQueryOptions->getServicedescriptionLike() != '') {
            //Pitfall! bindParam gets the value as reference, so dont touch $like after it was set!
            $like = sprintf('.*%s.*', $ServiceQueryOptions->getServicedescriptionLike());
            $query->bindParam($i++, $like);
        }

        foreach ($ServiceQueryOptions->getClusterName() as $clusterName) {
            $query->bindValue($i++, $clusterName);
        }

        $query->bindValue($i++, $ServiceQueryOptions->getLimit(), PDO::PARAM_INT);
        $query->bindValue($i++, $ServiceQueryOptions->getOffset(), PDO::PARAM_INT);
        $servicestatusResult = [];
        foreach ($this->Backend->fetchAll($query) as $record) {
            $servicestatusResult[$record['hostname']][] = $record;
        }

        $hostNames = array_keys($servicestatusResult);
        $hoststatus = $this->getHostStateByHostnames($hostNames);
        $result = [];

        foreach ($servicestatusResult as $hostname => $record) {
            $result[$hostname]['hoststatus'] = $hoststatus[$hostname];
            $result[$hostname]['servicestatus'] = $record;

        }
        return $result;

    }

    /**
     * @param array $hostNames
     * @return array
     */
    public function getHostStateByHostnames($hostNames) {
        if (empty($hostNames)) {
            return [];
        }

        $placeholders = [];
        for ($i = 1; $i <= sizeof($hostNames); $i++) {
            $placeholders[] = '?';
        }

        $query = $this->Backend->prepare(sprintf('SELECT hostname, current_state, problem_has_been_acknowledged, scheduled_downtime_depth, is_flapping
        FROM statusengine_hoststatus WHERE hostname IN(%s)', implode(',', $placeholders)));

        $i = 1;
        foreach ($hostNames as $hostName) {
            $query->bindValue($i++, $hostName);
        }

        $result = [];
        foreach ($this->Backend->fetchAll($query) as $record) {
            $result[$record['hostname']] = $record;
        }

        return $result;
    }

    /**
     * @param string $field
     * @param array $resultData
     * @return array
     */
    public function extractField($field, $resultData) {
        $return = [];
        foreach ($resultData as $record) {
            $return[] = $record[$field];
        }
        return $return;
    }

    /**
     * @param ServiceQueryOptions $QueryOptions
     * @param bool $useWhere
     * @return string
     */
    private function getClusterNameQuery(ServiceQueryOptions $QueryOptions, $useWhere = true) {
        $operator = 'WHERE';
        if (!$useWhere) {
            $operator = 'AND';
        }
        $placeholders = [];
        foreach ($QueryOptions->getClusterName() as $clusterName) {
            $placeholders[] = '?';
        }
        if (!empty($placeholders)) {
            return sprintf(' %s node_name IN(%s)', $operator, implode(',', $placeholders));
        }
        return '';
    }

    /**
     * @param ServiceQueryOptions $ServiceQueryOptions
     * @return array
     */
    public function getServiceDetails(ServiceQueryOptions $ServiceQueryOptions) {
        $fields = [
            'notifications_enabled',
            'active_checks_enabled',
            'passive_checks_enabled',
            'flap_detection_enabled',
            'event_handler_enabled',
            'is_flapping',
            'is_hardstate',
            'problem_has_been_acknowledged',
            'last_check',
            'next_check',
            'last_state_change',
            'status_update_time',
            'hostname',
            'service_description',
            'node_name',
            'current_check_attempt',
            'max_check_attempts',
            'current_state',
            'output',
            'long_output',
            'perfdata',
            'check_timeperiod',
            'normal_check_interval',
            'retry_check_interval',
            'scheduled_downtime_depth'
        ];

        $query = $this->Backend->prepare(sprintf(
                'SELECT %s FROM statusengine_servicestatus WHERE hostname = ? AND service_description = ?',
                implode(',', $fields))
        );
        $query->bindValue(1, $ServiceQueryOptions->getHostname());
        $query->bindValue(2, $ServiceQueryOptions->getServicedescription());

        $result = $this->Backend->fetchAll($query);
        if (!isset($result[0])) {
            return ['servicestatus' => [], 'perfdata' => []];
        }

        $perfdata = [];
        if ($result['0']['perfdata'] != '') {
            $perfdata = new PerfdataParser($result['0']['perfdata']);
            $perfdata = $perfdata->parse();
        }

        return [
            'servicestatus' => $result[0],
            'perfdata' => $perfdata
        ];

    }

    /**
     * @param ServiceQueryOptions $QueryOptions
     * @return array
     */
    public function getProblems(ServiceQueryOptions $QueryOptions) {
        $countQuery = 'SELECT count(*) as problems
                        FROM statusengine_servicestatus
                        WHERE problem_has_been_acknowledged=false AND scheduled_downtime_depth=0 AND current_state > 0';
        $countQuery = $this->Backend->prepare($countQuery);
        $result = $this->Backend->fetchAll($countQuery);

        $problemCount = $result[0]['problems'];

        $query = 'SELECT hostname, service_description, current_state
                        FROM statusengine_servicestatus
                        WHERE problem_has_been_acknowledged=false AND scheduled_downtime_depth=0 AND current_state > 0
                        ORDER BY last_state_change DESC LIMIT ? OFFSET ?';
        $query = $this->Backend->prepare($query);
        $i = 1;
        $query->bindValue($i++, $QueryOptions->getLimit(), PDO::PARAM_INT);
        $query->bindValue($i++, $QueryOptions->getOffset(), PDO::PARAM_INT);
        $result = $this->Backend->fetchAll($query);

        return [
            'problemCount' => $problemCount,
            'services' => $result
        ];
    }

}