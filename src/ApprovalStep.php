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

use CommonDBChild;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * One step of a DocType's approval route. Rights are inherited from the
 * parent DocType ($rightname is intentionally left empty, the default on
 * CommonGLPI, so CommonDBChild::canCreate()/canView()/... fall through to
 * checking the same right on the parent DocType instead of a right of
 * their own -- there is no independent "manage approval steps" right).
 */
class ApprovalStep extends CommonDBChild
{
    public static $itemtype = DocType::class;
    public static $items_id = 'plugin_assetmove_doctypes_id';

    public const APPROVER_USER                  = 'USER';
    public const APPROVER_GROUP                 = 'GROUP';
    public const APPROVER_PROFILE                = 'PROFILE';
    public const APPROVER_AUTHOR_MANAGER         = 'AUTHOR_MANAGER';
    public const APPROVER_WAREHOUSE_MANAGER_SRC  = 'WAREHOUSE_MANAGER_SRC';
    public const APPROVER_WAREHOUSE_MANAGER_DST  = 'WAREHOUSE_MANAGER_DST';
    public const APPROVER_ITEM_OWNER             = 'ITEM_OWNER';

    public const MODE_ALL = 1;
    public const MODE_ANY = 2;

    public static function getTypeName($nb = 0)
    {
        return _n('Approval step', 'Approval steps', $nb, 'assetmove');
    }

    /**
     * @return array<string, string>
     */
    public static function getApproverTypesDropdownValues(): array
    {
        return [
            self::APPROVER_USER                 => __('Specific user', 'assetmove'),
            self::APPROVER_GROUP                => __('Specific group', 'assetmove'),
            self::APPROVER_PROFILE               => __('Any user with this profile (in the document entity)', 'assetmove'),
            self::APPROVER_AUTHOR_MANAGER        => __("Author's manager", 'assetmove'),
            self::APPROVER_WAREHOUSE_MANAGER_SRC => __('Source warehouse manager', 'assetmove'),
            self::APPROVER_WAREHOUSE_MANAGER_DST => __('Destination warehouse manager', 'assetmove'),
            self::APPROVER_ITEM_OWNER            => __('Owner(s) of the items in the document', 'assetmove'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getModesDropdownValues(): array
    {
        return [
            self::MODE_ALL => __('All must approve', 'assetmove'),
            self::MODE_ANY => __('Any one approval is enough', 'assetmove'),
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof DocType) || !DocType::canView()) {
            return '';
        }

        $nb = self::countForDocType((int) $item->getID());

        return self::createTabEntry(self::getTypeName(2), $nb);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof DocType) {
            self::showForDocType($item);
        }

        return true;
    }

    public static function countForDocType(int $doctypes_id): int
    {
        return countElementsInTable(self::getTable(), ['plugin_assetmove_doctypes_id' => $doctypes_id]);
    }

    /**
     * Render the approval route (existing steps + an add-step mini form)
     * for a given document type.
     *
     * @param DocType $doctype
     *
     * @return void
     */
    public static function showForDocType(DocType $doctype): void
    {
        $steps = (new self())->find(
            ['plugin_assetmove_doctypes_id' => $doctype->getID()],
            ['step_order ASC', 'id ASC']
        );

        TemplateRenderer::getInstance()->display('@assetmove/approvalsteps_tab.html.twig', [
            'doctype'        => $doctype,
            'steps'          => $steps,
            'approver_types' => self::getApproverTypesDropdownValues(),
            'modes'          => self::getModesDropdownValues(),
            'canedit'        => Session::haveRight(DocType::$rightname, UPDATE),
        ]);
    }
}
