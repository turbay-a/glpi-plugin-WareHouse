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
 * One resolved approver's row for one step of a document's approval route
 * (expanded from ApprovalStep by Engine\ApprovalEngine::submit() -- see
 * that class for how approver_type becomes a concrete users_id_validate).
 *
 * Rights are inherited from the parent document ($rightname intentionally
 * left empty, same reasoning as ApprovalStep on DocType): whether a given
 * user may actually answer a specific row is a narrower question than any
 * static right, and is checked by canAnswer() instead.
 */
class Validation extends CommonDBChild
{
    public static $itemtype = Movement::class;
    public static $items_id = 'plugin_assetmove_movements_id';

    public const STATUS_NOT_STARTED = 1;
    public const STATUS_WAITING     = 2;
    public const STATUS_ACCEPTED    = 3;
    public const STATUS_REFUSED     = 4;

    public const MODE_ALL = 1;
    public const MODE_ANY = 2;

    public static function getTypeName($nb = 0)
    {
        return _n('Approval', 'Approvals', $nb, 'assetmove');
    }

    /**
     * @return array<int, string>
     */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_NOT_STARTED => __('Not started', 'assetmove'),
            self::STATUS_WAITING     => __('Waiting', 'assetmove'),
            self::STATUS_ACCEPTED    => __('Accepted', 'assetmove'),
            self::STATUS_REFUSED     => __('Refused', 'assetmove'),
        ];
    }

    /**
     * Whether $users_id may answer this row right now: it must be WAITING,
     * and $users_id must be the assigned approver (directly, or a member
     * of the assigned group).
     */
    public function canAnswer(int $users_id): bool
    {
        if ((int) $this->fields['status'] !== self::STATUS_WAITING) {
            return false;
        }

        if ((int) $this->fields['users_id_validate'] === $users_id) {
            return true;
        }

        if ((int) $this->fields['groups_id_validate'] > 0) {
            return countElementsInTable('glpi_groups_users', [
                'groups_id' => $this->fields['groups_id_validate'],
                'users_id'  => $users_id,
            ]) > 0;
        }

        return false;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof AbstractDocument) || $item->isNewItem()) {
            return '';
        }

        $nb = countElementsInTable(self::getTable(), ['plugin_assetmove_movements_id' => $item->getID()]);

        return self::createTabEntry(__('Approvals', 'assetmove'), $nb);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof AbstractDocument) {
            self::showForDocument($item);
        }

        return true;
    }

    public static function showForDocument(AbstractDocument $document): void
    {
        $rows = (new self())->find(
            ['plugin_assetmove_movements_id' => $document->getID()],
            ['step_order ASC', 'id ASC']
        );

        $users_id = Session::getLoginUserID();
        foreach ($rows as &$row) {
            $validation = new self();
            $validation->fields = $row;
            $row['_can_answer'] = $validation->canAnswer((int) $users_id);
        }
        unset($row);

        TemplateRenderer::getInstance()->display('@assetmove/validations_tab.html.twig', [
            'document'      => $document,
            'rows'          => $rows,
            'status_labels' => self::getStatusLabels(),
        ]);
    }
}
