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
use Glpi\Application\View\TemplateRenderer;

/**
 * A document type is a template for Movement/Writeoff documents: it fixes
 * the route between two kinds of place/person (for movements) or the
 * target state (for write-offs), and carries the approval route
 * (ApprovalStep children) that new documents of this type will snapshot.
 */
class DocType extends CommonDBTM
{
    public static $rightname = 'plugin_assetmove_doctype';

    /** @var string[] itemtypes a movement route can point a document's source/destination to */
    public const ROUTE_ITEMTYPES = [
        'Entity',
        'Location',
        'User',
        'Supplier',
        'Group',
        Warehouse::class,
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Document type', 'Document types', $nb, 'assetmove');
    }

    public static function getIcon()
    {
        return 'ti ti-route';
    }

    /**
     * @return array<string, string> itemtype => label
     */
    public static function getRouteItemtypesDropdownValues(): array
    {
        $values = [];
        foreach (self::ROUTE_ITEMTYPES as $itemtype) {
            $values[$itemtype] = $itemtype::getTypeName(1);
        }

        return $values;
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input);
    }

    /**
     * Write-off document types do not have a source/destination route nor a
     * two-phase transit: clear those fields so stale values from a type
     * change (move -> writeoff) never leak into Mover (milestone M4).
     *
     * @param array $input
     *
     * @return array
     */
    private function prepareInput($input)
    {
        $kind = (int) ($input['kind'] ?? $this->fields['kind'] ?? Movement::KIND);

        if ($kind === Writeoff::KIND) {
            $input['source_itemtype']      = null;
            $input['dest_itemtype']        = null;
            $input['is_two_phase']         = 0;
            $input['locations_id_transit'] = 0;
        }

        if (!empty($input['is_default_for_reception'])) {
            /** @var \DBmysql $DB */
            global $DB;

            $DB->update(static::getTable(), ['is_default_for_reception' => 0], [
                'id'   => ['<>', $input['id'] ?? 0],
                'kind' => Movement::KIND,
            ]);
        }

        return $input;
    }

    /**
     * The document type the Order integration (Engine\OrderListener,
     * milestone M7) uses to auto-create a reception movement. Null if the
     * admin has not designated one yet -- the integration then simply
     * does nothing rather than guessing.
     */
    public static function getDefaultForReception(): ?self
    {
        $doctype = new self();
        if ($doctype->getFromDBByCrit([
            'kind'                      => Movement::KIND,
            'is_default_for_reception'  => 1,
            'is_active'                 => 1,
        ])) {
            return $doctype;
        }

        return null;
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
            'id'            => '3',
            'table'         => static::getTable(),
            'field'         => 'kind',
            'name'          => __('Kind', 'assetmove'),
            'datatype'      => 'specific',
            'searchtype'    => 'equals',
        ];

        $tab[] = [
            'id'       => '4',
            'table'    => static::getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => '5',
            'table'    => static::getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'kind') {
            $value = is_array($values) ? $values['kind'] : $values;

            return match ((int) $value) {
                Movement::KIND => Movement::getTypeName(1),
                Writeoff::KIND => Writeoff::getTypeName(1),
                default        => '',
            };
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@assetmove/doctype_form.html.twig', [
            'item'            => $this,
            'params'          => $options,
            'route_itemtypes' => self::getRouteItemtypesDropdownValues(),
            'kind_labels'     => [
                Movement::KIND => Movement::getTypeName(1),
                Writeoff::KIND => Writeoff::getTypeName(1),
            ],
        ]);

        return true;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(ApprovalStep::class, $tabs, $options);
        $this->addStandardTab(\Log::class, $tabs, $options);

        return $tabs;
    }
}
