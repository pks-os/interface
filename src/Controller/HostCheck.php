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

namespace Statusengine\Controller;


use Statusengine\Loader\HostCheckLoaderInterface;
use Statusengine\ValueObjects\HostCheckQueryOptions;

class HostCheck extends Controller {

    /**
     * @var HostCheckLoaderInterface
     */
    private $HostCheckLoader;

    /**
     * HostCheck constructor.
     * @param HostCheckLoaderInterface $HostCheckLoader
     */
    public function __construct(HostCheckLoaderInterface $HostCheckLoader) {
        $this->HostCheckLoader = $HostCheckLoader;
    }

    /**
     * @return array
     */
    public function index(HostCheckQueryOptions $queryOptions) {
        return $this->HostCheckLoader->getHostchecks($queryOptions);
    }


}