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

use Crate\PDO\PDO;
use Statusengine\Backend\StorageBackend;
use Statusengine\Loader\HostAcknowledgementLoaderInterface;
use Statusengine\ValueObjects\HostAcknowledgementQueryOptions;

class HostAcknowledgementLoader implements HostAcknowledgementLoaderInterface {

    /**
     * @var \Statusengine\Backend\Crate\Crate
     */
    private $Backend;

    /**
     * HostAcknowledgementLoader constructor.
     * @param StorageBackend $StorageBackend
     */
    public function __construct(StorageBackend $StorageBackend) {
        $this->Backend = $StorageBackend->getBackend();
    }

    /**
     * @param HostAcknowledgementQueryOptions $HostAcknowledgementQueryOptions
     * @return array
     */
    public function getAcknowledgements(HostAcknowledgementQueryOptions $HostAcknowledgementQueryOptions) {
        $fields = [
            'acknowledgement_type',
            'author_name',
            'comment_data',
            'entry_time',
            'is_sticky',
            'notify_contacts',
            'persistent_comment',
            'state'
        ];
        $baseQuery = sprintf('SELECT %s FROM statusengine_host_acknowledgements WHERE hostname=?', implode(',', $fields));

        if ($HostAcknowledgementQueryOptions->sizeOfStateFilter() > 0 && $HostAcknowledgementQueryOptions->sizeOfStateFilter() < 3) {
            $baseQuery = sprintf('%s AND state IN(%s)', $baseQuery, implode(',', $HostAcknowledgementQueryOptions->getStateFilter()));
        }

        if ($HostAcknowledgementQueryOptions->getCommentDataLike() != '') {
            $baseQuery = sprintf(' %s AND comment_data ~* ? ', $baseQuery);
        }

        if ($HostAcknowledgementQueryOptions->getEntryTimeLt() > 0){
            $baseQuery = sprintf(' %s AND entry_time < ? ', $baseQuery);
        }

        if ($HostAcknowledgementQueryOptions->getEntryTimeGt() > 0){
            $baseQuery = sprintf(' %s AND entry_time > ? ', $baseQuery);
        }

        $baseQuery = sprintf(
            '%s ORDER BY %s %s LIMIT ? OFFSET ?',
            $baseQuery,
            $HostAcknowledgementQueryOptions->getOrder(),
            $HostAcknowledgementQueryOptions->getDirection()
        );


        $query = $this->Backend->prepare($baseQuery);

        $i = 1;
        $query->bindValue($i++, $HostAcknowledgementQueryOptions->getHostname());

        if ($HostAcknowledgementQueryOptions->getCommentDataLike() != '') {
            $like = sprintf('.*%s.*', $HostAcknowledgementQueryOptions->getCommentDataLike());
            $query->bindValue($i++, $like);
        }

        if ($HostAcknowledgementQueryOptions->getEntryTimeLt() > 0){
            $query->bindValue($i++, $HostAcknowledgementQueryOptions->getEntryTimeLt());
        }

        if ($HostAcknowledgementQueryOptions->getEntryTimeGt() > 0){
            $query->bindValue($i++, $HostAcknowledgementQueryOptions->getEntryTimeGt());
        }

        $query->bindValue($i++, $HostAcknowledgementQueryOptions->getLimit(), PDO::PARAM_INT);
        $query->bindValue($i++, $HostAcknowledgementQueryOptions->getOffset(), PDO::PARAM_INT);

        return $this->Backend->fetchAll($query);

    }

}
