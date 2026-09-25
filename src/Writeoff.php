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
 * Final decommissioning of an asset (scrapped, sold, lost, donated, ...).
 * See AbstractDocument for the shared-table rationale.
 */
class Writeoff extends AbstractDocument
{
    public static $rightname = 'plugin_assetmove_writeoff';

    public const KIND = 2;

    public const RIGHT_APPROVE = 1024;
    public const RIGHT_EXECUTE = 2048;
    public const RIGHT_CANCEL  = 8192;
    public const RIGHT_FORCE   = 16384;

    public static function getKind(): int
    {
        return self::KIND;
    }

    protected static function getNumberFormatConfigField(): string
    {
        return 'number_format_writeoff';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Write-off', 'Write-offs', $nb, 'assetmove');
    }

    public static function getIcon()
    {
        return 'ti ti-trash-x';
    }

    public function rawSearchOptions()
    {
        return $this->getCommonSearchOptions();
    }

    /**
     * Snapshot the doctype's write-off-specific fields onto the document at
     * creation time (TZ 16.2: never re-read the type's config while a
     * document executes). Mirrors Movement::prepareInputForAdd()'s
     * snapshot of the route fields.
     */
    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);

        $doctype = new DocType();
        if (empty($input['plugin_assetmove_doctypes_id']) || !$doctype->getFromDB($input['plugin_assetmove_doctypes_id'])) {
            return $input;
        }

        $input['states_id_target']  = $doctype->fields['states_id_target'];
        $input['is_clear_location'] = $doctype->fields['is_clear_location'];
        $input['is_clear_user']     = $doctype->fields['is_clear_user'];

        return $input;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@assetmove/writeoff_form.html.twig', [
            'item'          => $this,
            'params'        => $options,
            'status_labels' => Status::getLabels(),
        ]);

        return true;
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);

        $values[self::RIGHT_APPROVE] = __('Approve', 'assetmove');
        $values[self::RIGHT_EXECUTE] = __('Execute', 'assetmove');
        $values[self::RIGHT_CANCEL]  = __('Cancel', 'assetmove');
        $values[self::RIGHT_FORCE]   = [
            'short' => __('Force', 'assetmove'),
            'long'  => __('Bypass segregation-of-duties checks', 'assetmove'),
        ];

        return $values;
    }
}
