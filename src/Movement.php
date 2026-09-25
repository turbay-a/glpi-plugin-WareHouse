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

use Glpi\Application\View\TemplateRenderer;

/**
 * Physical movement of assets between two locations/users/warehouses.
 * See AbstractDocument for the shared-table rationale.
 */
class Movement extends AbstractDocument
{
    public static $rightname = 'plugin_assetmove_movement';

    public const KIND = 1;

    public const RIGHT_APPROVE = 1024;
    public const RIGHT_SHIP    = 2048;
    public const RIGHT_RECEIVE = 4096;
    public const RIGHT_CANCEL  = 8192;
    public const RIGHT_FORCE   = 16384;

    public static function getKind(): int
    {
        return self::KIND;
    }

    protected static function getNumberFormatConfigField(): string
    {
        return 'number_format_move';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Movement', 'Movements', $nb, 'assetmove');
    }

    public static function getIcon()
    {
        return 'ti ti-transfer';
    }

    public function rawSearchOptions()
    {
        $tab = $this->getCommonSearchOptions();

        $tab[] = [
            'id'               => '20',
            'table'            => static::getTable(),
            'field'            => 'source_items_id',
            'name'             => __('Source', 'assetmove'),
            'datatype'         => 'specific',
            'nosearch'         => true,
            'massiveaction'    => false,
            'additionalfields' => ['source_itemtype'],
        ];

        $tab[] = [
            'id'               => '21',
            'table'            => static::getTable(),
            'field'            => 'dest_items_id',
            'name'             => __('Destination', 'assetmove'),
            'datatype'         => 'specific',
            'nosearch'         => true,
            'massiveaction'    => false,
            'additionalfields' => ['dest_itemtype'],
        ];

        $tab[] = [
            'id'       => '22',
            'table'    => static::getTable(),
            'field'    => 'is_two_phase',
            'name'     => __('Two-phase (ship then receive)', 'assetmove'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => '23',
            'table'    => static::getTable(),
            'field'    => 'date_shipped',
            'name'     => __('Shipping date', 'assetmove'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => '24',
            'table'    => static::getTable(),
            'field'    => 'date_received',
            'name'     => __('Reception date', 'assetmove'),
            'datatype' => 'datetime',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'source_items_id') {
            return self::formatPolymorphicRef($values['source_itemtype'] ?? null, (int) $values[$field]);
        }
        if ($field === 'dest_items_id') {
            return self::formatPolymorphicRef($values['dest_itemtype'] ?? null, (int) $values[$field]);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@assetmove/movement_form.html.twig', [
            'item'            => $this,
            'params'          => $options,
            'route_field_map' => self::getRouteFieldMap(),
            'status_labels'   => Status::getLabels(),
        ]);

        return true;
    }

    /**
     * The route (source_itemtype/dest_itemtype) is fixed by the chosen
     * DocType, not picked freely per document (see prepareInputForAdd()):
     * the form only lets the user pick the specific item for whichever
     * type the DocType requires, through one plain input per possible
     * type rather than a JS-driven dynamic picker (kept simple on purpose,
     * see PROGRESS.md).
     *
     * @return array<string, string> itemtype => form field name
     */
    public static function getRouteFieldMap(): array
    {
        $map = [];
        foreach (DocType::ROUTE_ITEMTYPES as $itemtype) {
            $map[$itemtype] = '_ref_' . strtolower(str_replace('\\', '_', $itemtype)) . '_id';
        }

        return $map;
    }

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);

        $doctype = new DocType();
        if (empty($input['plugin_assetmove_doctypes_id']) || !$doctype->getFromDB($input['plugin_assetmove_doctypes_id'])) {
            return $input;
        }

        $input['source_itemtype']      = $doctype->fields['source_itemtype'];
        $input['dest_itemtype']        = $doctype->fields['dest_itemtype'];
        $input['is_two_phase']         = $doctype->fields['is_two_phase'];
        $input['states_id_target']     = $doctype->fields['states_id_target'];
        $input['is_apply_location']    = $doctype->fields['is_apply_location'];
        $input['is_apply_user']        = $doctype->fields['is_apply_user'];
        $input['locations_id_transit'] = $doctype->fields['locations_id_transit'];
        $input['is_selfreception_allowed'] = $doctype->fields['is_selfreception_allowed'];

        // A caller that already knows the exact source/destination (e.g.
        // Hook\OrderListener auto-creating a reception movement) can pass
        // source_items_id/dest_items_id directly; otherwise they are
        // resolved from the form's per-type fields as usual.
        if (!isset($input['source_items_id'])) {
            $field_map    = self::getRouteFieldMap();
            $source_field = $field_map[$doctype->fields['source_itemtype']] ?? null;
            $input['source_items_id'] = $source_field !== null ? (int) ($input[$source_field] ?? 0) : 0;
        }
        if (!isset($input['dest_items_id'])) {
            $field_map  ??= self::getRouteFieldMap();
            $dest_field = $field_map[$doctype->fields['dest_itemtype']] ?? null;
            $input['dest_items_id'] = $dest_field !== null ? (int) ($input['_dest' . $dest_field] ?? 0) : 0;
        }

        return $input;
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);

        $values[self::RIGHT_APPROVE] = __('Approve', 'assetmove');
        $values[self::RIGHT_SHIP]    = __('Ship', 'assetmove');
        $values[self::RIGHT_RECEIVE] = __('Receive', 'assetmove');
        $values[self::RIGHT_CANCEL]  = __('Cancel', 'assetmove');
        $values[self::RIGHT_FORCE]   = [
            'short' => __('Force', 'assetmove'),
            'long'  => __('Bypass segregation-of-duties checks', 'assetmove'),
        ];

        return $values;
    }
}
