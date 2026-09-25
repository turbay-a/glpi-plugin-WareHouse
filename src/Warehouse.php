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

use CommonDBTM;
use Session;

/**
 * A physical warehouse: a Location plus the person/group in charge of it.
 * Used as a source/destination for two-phase movements (see
 * Engine\StateMachine's segregation-of-duties checks in milestone M4).
 */
class Warehouse extends CommonDBTM
{
    public static $rightname = 'plugin_assetmove_warehouse';

    public static function getTypeName($nb = 0)
    {
        return _n('Warehouse', 'Warehouses', $nb, 'assetmove');
    }

    public static function getIcon()
    {
        return 'ti ti-building-warehouse';
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => __('Characteristics'),
        ];

        $tab[] = [
            'id'            => '1',
            'table'         => static::getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '2',
            'table'    => static::getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id'       => '3',
            'table'    => 'glpi_locations',
            'field'    => 'completename',
            'name'     => \Location::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id'       => '4',
            'table'    => 'glpi_users',
            'field'    => 'name',
            'linkfield' => 'users_id_manager',
            'name'     => __('Manager', 'assetmove'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => '5',
            'table'    => 'glpi_groups',
            'field'    => 'completename',
            'linkfield' => 'groups_id',
            'name'     => \Group::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id'       => '6',
            'table'    => static::getTable(),
            'field'    => 'is_default',
            'name'     => __('Default reception warehouse', 'assetmove'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => '7',
            'table'    => static::getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => '8',
            'table'    => static::getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => '9',
            'table'    => static::getTable(),
            'field'    => 'print_requisites',
            'name'     => __('Print requisites', 'assetmove'),
            'datatype' => 'text',
        ];

        return $tab;
    }

    public function prepareInputForAdd($input)
    {
        // Unlike on update, a location is mandatory on add regardless of
        // whether the caller even included the key (the real HTML form
        // always submits it, empty-selected or not; only a programmatic
        // caller could omit it outright) -- a brand new warehouse without
        // one makes no sense per TZ 5, and would collide with any other
        // one left likewise empty (`locations_id` defaults to 0).
        $input += ['locations_id' => 0];

        $input = $this->checkLocationConflict($input, 0);
        if ($input === false) {
            return false;
        }

        return $this->ensureSingleDefault($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->checkLocationConflict($input, (int) $this->getID());
        if ($input === false) {
            return false;
        }

        return $this->ensureSingleDefault($input);
    }

    /**
     * Only one warehouse can be the default reception warehouse (used by
     * the Order integration, milestone M7, to auto-generate movements).
     *
     * @param array $input
     *
     * @return array
     */
    private function ensureSingleDefault($input)
    {
        if (!empty($input['is_default'])) {
            /** @var \DBmysql $DB */
            global $DB;

            $DB->update(static::getTable(), ['is_default' => 0], [
                'id' => ['<>', $input['id'] ?? 0],
            ]);
        }

        return $input;
    }

    /**
     * `locations_id` carries a DB-level `UNIQUE KEY` (one warehouse per
     * location) that, left unchecked here, surfaces to the user as a raw
     * MySQL duplicate-entry exception instead of a message under the field
     * (a known gap since M2, see PROGRESS.md). A location is also required:
     * a warehouse without one would collide with any other one left
     * likewise empty (`locations_id` defaults to 0), and a warehouse is not
     * meaningful without a physical location per TZ 5 anyway.
     *
     * @param array $input
     * @param int   $exclude_id Current record's id on update (0 on add).
     *
     * @return array|false
     */
    private function checkLocationConflict($input, int $exclude_id)
    {
        if (!array_key_exists('locations_id', $input)) {
            return $input;
        }

        $locations_id = (int) $input['locations_id'];

        if ($locations_id <= 0) {
            Session::addMessageAfterRedirect(
                __('Please select a location.', 'assetmove'),
                false,
                ERROR
            );

            return false;
        }

        $conflict = countElementsInTable(static::getTable(), [
            'locations_id' => $locations_id,
            'NOT'          => ['id' => $exclude_id],
        ]) > 0;

        if ($conflict) {
            Session::addMessageAfterRedirect(
                __('Another warehouse already uses this location.', 'assetmove'),
                false,
                ERROR
            );

            return false;
        }

        return $input;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        \Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetmove/warehouse_form.html.twig', [
            'item'   => $this,
            'params' => $options,
        ]);

        return true;
    }
}
