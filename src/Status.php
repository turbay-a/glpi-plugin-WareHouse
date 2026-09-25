<?php

/**
 * -------------------------------------------------------------------------
 * Assetmove plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Assetmove.
 *
 * Assetmove is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Assetmove is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Assetmove. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetmove;

/**
 * Document workflow statuses, shared by Movement and Writeoff.
 * Allowed transitions live in Engine\StateMachine (milestone M4).
 */
final class Status
{
    public const NEW        = 1;
    public const TOVALIDATE = 2;
    public const APPROVED   = 3;
    public const SHIPPED    = 4;
    public const DONE       = 5;
    public const CANCELLED  = 6;
    public const REFUSED    = 7;

    private function __construct()
    {
        // Constants-only holder, never instantiated.
    }

    /**
     * @return array<int, string>
     */
    public static function getLabels(): array
    {
        return [
            self::NEW        => __('New', 'assetmove'),
            self::TOVALIDATE => __('Pending approval', 'assetmove'),
            self::APPROVED   => __('Approved', 'assetmove'),
            self::SHIPPED    => __('Shipped', 'assetmove'),
            self::DONE       => __('Done', 'assetmove'),
            self::CANCELLED  => __('Cancelled', 'assetmove'),
            self::REFUSED    => __('Refused', 'assetmove'),
        ];
    }
}
