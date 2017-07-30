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

namespace Statusengine\Loader\Mysql;

use Statusengine\Backend\StorageBackend;
use Statusengine\Loader\HostStateHistoryLoaderInterface;
use Statusengine\ValueObjects\HostStateHistoryQueryOptions;

class HostStateHistoryLoader implements HostStateHistoryLoaderInterface {

    /**
     * @var StorageBackend
     */
    private $StorageBackend;

    /**
     * @var \Statusengine\Backend\Mysql\MySQL
     */
    private $Backend;

    /**
     * HostStateHistoryLoader constructor.
     * @param StorageBackend $StorageBackend
     */
    public function __construct(StorageBackend $StorageBackend) {
        $this->Backend = $StorageBackend->getBackend();
    }

    /**
     * @param HostStateHistoryQueryOptions $HostStateHistoryQueryOptions
     * @return array
     */
    public function getHostStateHistory(HostStateHistoryQueryOptions $HostStateHistoryQueryOptions) {
        $fields = [
            'booleans' => [
                'is_hardstate'
            ],
            'strings' => [
                'hostname',
                'output',
                'state',
                'current_check_attempt',
                'max_check_attempts',
                'state_time'
            ]
        ];

        $sql = [];

        foreach ($fields['booleans'] as $field) {
            $sql[] = $field;
        }
        foreach ($fields['strings'] as $field) {
            $sql[] = $field;
        }


        $baseQuery = sprintf('SELECT %s FROM statusengine_host_statehistory WHERE hostname=?', implode(',', $sql));

        if ($HostStateHistoryQueryOptions->sizeOfStateFilter() > 0 && $HostStateHistoryQueryOptions->sizeOfStateFilter() < 3) {
            $baseQuery = sprintf('%s AND state IN(%s)', $baseQuery, implode(',', $HostStateHistoryQueryOptions->getStateFilter()));
        }

        if ($HostStateHistoryQueryOptions->getOutputLike() != '') {
            $baseQuery = sprintf(' %s AND output LIKE ? ', $baseQuery);
        }

        $baseQuery = sprintf(
            '%s ORDER BY %s %s LIMIT ? OFFSET ?',
            $baseQuery,
            $HostStateHistoryQueryOptions->getOrder(),
            $HostStateHistoryQueryOptions->getDirection()
        );

        $query = $this->Backend->prepare($baseQuery);

        $i = 1;
        $query->bindValue($i++, $HostStateHistoryQueryOptions->getHostname());

        if ($HostStateHistoryQueryOptions->getOutputLike() != '') {
            $like = sprintf('%%%s%%', $HostStateHistoryQueryOptions->getOutputLike());
            $query->bindValue($i++, $like);
        }

        $query->bindValue($i++, $HostStateHistoryQueryOptions->getLimit(), \PDO::PARAM_INT);
        $query->bindValue($i++, $HostStateHistoryQueryOptions->getOffset(), \PDO::PARAM_INT);

        $results = $this->Backend->fetchAll($query);

        foreach($results as $key => $result){
            foreach ($fields['booleans'] as $field) {
                $results[$key][$field] = (bool)$results[$key][$field];
            }
        }

        return $results;
    }

}
