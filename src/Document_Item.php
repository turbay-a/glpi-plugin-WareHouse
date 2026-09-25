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

use CommonDBRelation;
use CommonDBTM;
use CommonGLPI;
use Dropdown;
use GlpiPlugin\Assetmove\Engine\AssetTypes;
use Html;
use MassiveAction;
use Session;

/**
 * One row of a Movement/Writeoff's table part: links the document (fixed
 * `plugin_assetmove_movements_id`, resolved as a Movement regardless of the
 * document's actual `kind` -- Writeoff shares the same table/row shape) to
 * one polymorphic asset (`itemtype` + `items_id`).
 *
 * Not to be confused with core's own `\Document_Item` (file attachments),
 * which is a separate, unrelated tab also shown on Movement/Writeoff (see
 * AbstractDocument::defineTabs()).
 */
class Document_Item extends CommonDBRelation
{
    public static $itemtype_1 = Movement::class;
    public static $items_id_1 = 'plugin_assetmove_movements_id';

    public static $itemtype_2 = 'itemtype';
    public static $items_id_2 = 'items_id';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_assetmove_movements_items';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Item', 'Items', $nb, 'assetmove');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof AbstractDocument) {
            $nb = self::countForDocument((int) $item->getID());

            return self::createTabEntry(self::getTypeName(2), $nb);
        }

        if ($item instanceof CommonDBTM && AssetTypes::isMovable($item->getType())) {
            $nb = self::countForAsset($item->getType(), (int) $item->getID());

            return self::createTabEntry(__('Asset movements', 'assetmove'), $nb);
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof AbstractDocument) {
            self::showForDocument($item);
        } elseif ($item instanceof CommonDBTM && AssetTypes::isMovable($item->getType())) {
            self::showForAsset($item);
        }

        return true;
    }

    public static function countForDocument(int $movements_id): int
    {
        return countElementsInTable(self::getTable(), ['plugin_assetmove_movements_id' => $movements_id]);
    }

    public static function countForAsset(string $itemtype, int $items_id): int
    {
        return countElementsInTable(self::getTable(), ['itemtype' => $itemtype, 'items_id' => $items_id]);
    }

    public function prepareInputForAdd($input)
    {
        if (self::hasActiveDocument($input['itemtype'] ?? '', (int) ($input['items_id'] ?? 0))) {
            Session::addMessageAfterRedirect(
                __('This item is already on another active movement or write-off document.', 'assetmove'),
                false,
                ERROR
            );

            return false;
        }

        return $input;
    }

    /**
     * TZ 16.9 / M4 "antirecidive": with Config::is_block_parallel_movements
     * on, an item cannot be added to a new document while it is still
     * pending (not yet DONE/CANCELLED/REFUSED) on another one.
     */
    private static function hasActiveDocument(string $itemtype, int $items_id): bool
    {
        if ($itemtype === '' || $items_id <= 0 || !Config::getConfig()->fields['is_block_parallel_movements']) {
            return false;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT'     => ['mi.id'],
            'FROM'       => self::getTable() . ' AS mi',
            'INNER JOIN' => [
                Movement::getTable() . ' AS m' => [
                    'ON' => ['mi' => 'plugin_assetmove_movements_id', 'm' => 'id'],
                ],
            ],
            'WHERE' => [
                'mi.itemtype' => $itemtype,
                'mi.items_id' => $items_id,
                'mi.is_moved' => 0,
                'm.status'    => [Status::NEW, Status::TOVALIDATE, Status::APPROVED, Status::SHIPPED],
            ],
        ]);

        return count($iterator) > 0;
    }

    /**
     * @param AbstractDocument $document
     *
     * @return void
     */
    public static function showForDocument(AbstractDocument $document): void
    {
        $rows = (new self())->find(
            ['plugin_assetmove_movements_id' => $document->getID()],
            ['id ASC']
        );

        if (
            $document instanceof Movement
            && (int) $document->fields['status'] === Status::SHIPPED
        ) {
            $can_receive = \GlpiPlugin\Assetmove\Engine\StateMachine::canReceive($document, (int) Session::getLoginUserID()) === true;

            $pending  = [];
            $resolved = [];
            foreach ($rows as $row) {
                if ((int) $row['reception_status'] === 0) {
                    $pending[] = $row;
                } else {
                    $resolved[] = $row;
                }
            }

            \Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetmove/reception_form.html.twig', [
                'document'    => $document,
                'pending'     => $pending,
                'resolved'    => $resolved,
                'can_receive' => $can_receive,
            ]);

            return;
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetmove/items_tab.html.twig', [
            'document'      => $document,
            'rows'          => $rows,
            'movable_types' => AssetTypes::getMovableTypesDropdownValues(),
            'canedit'       => $document->canEdit($document->getID()) && (int) $document->fields['status'] === Status::NEW,
        ]);
    }

    /**
     * TZ 12.4 / M8: reverse direction of showForDocument() -- shown as a tab
     * on the asset itself (Computer, Monitor, a MovableCapacity-enabled
     * custom asset, ...), listing every Movement/Writeoff that has
     * referenced it, most recent first. Read-only: editing happens from the
     * document's own "Items" tab, not from here.
     */
    public static function showForAsset(CommonDBTM $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['mi.id AS link_id', 'm.*'],
            'FROM'   => self::getTable() . ' AS mi',
            'INNER JOIN' => [
                Movement::getTable() . ' AS m' => [
                    'ON' => ['mi' => 'plugin_assetmove_movements_id', 'm' => 'id'],
                ],
            ],
            'WHERE' => [
                'mi.itemtype' => $item->getType(),
                'mi.items_id' => $item->getID(),
            ],
            'ORDER' => ['m.date_creation DESC'],
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = [
                'id'     => (int) $row['id'],
                'kind'   => (int) $row['kind'],
                'name'   => $row['name'],
                'status' => (int) $row['status'],
            ];
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetmove/asset_documents_tab.html.twig', [
            'rows'          => $rows,
            'status_labels' => Status::getLabels(),
        ]);
    }

    /**
     * The "add to a new movement/write-off" actions are exposed on asset
     * lists (Computer, Monitor, ...), not on a Document_Item list of our
     * own -- registration happens via the global `MassiveActions` plugin
     * hook (see plugin_assetmove_MassiveActions() in hook.php), which is
     * the mechanism GLPI provides for a plugin to add an action to an
     * itemtype it does not own.
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        switch ($ma->getAction()) {
            case 'add_to_movement':
            case 'add_to_writeoff':
                $kind = $ma->getAction() === 'add_to_movement' ? Movement::KIND : Writeoff::KIND;

                echo '<label>' . DocType::getTypeName(1) . '</label>&nbsp;';
                Dropdown::show(DocType::class, [
                    'name'      => 'plugin_assetmove_doctypes_id',
                    'condition' => ['kind' => $kind, 'is_active' => 1],
                ]);
                echo '<br><br>';
                echo Html::submit(_x('button', 'Post'), ['name' => 'massiveaction']);

                return true;
        }

        return parent::showMassiveActionsSubForm($ma);
    }

    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ) {
        switch ($ma->getAction()) {
            case 'add_to_movement':
            case 'add_to_writeoff':
                $input       = $ma->getInput();
                $doctypes_id = (int) ($input['plugin_assetmove_doctypes_id'] ?? 0);
                $doctype     = new DocType();

                if ($doctypes_id <= 0 || !$doctype->getFromDB($doctypes_id)) {
                    foreach ($ids as $id) {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                    }
                    $ma->addMessage(__('Please select a document type.', 'assetmove'));
                    return;
                }

                // $item is the itemtype's shared/empty check instance, not
                // loaded to any particular id: derive the entity from the
                // first selected item instead.
                $entities_id = 0;
                $first_id    = $ids[0] ?? null;
                if ($first_id !== null && $item->getFromDB($first_id) && $item->isField('entities_id')) {
                    $entities_id = (int) $item->fields['entities_id'];
                }

                $document = $doctype->fields['kind'] == Writeoff::KIND ? new Writeoff() : new Movement();
                $documents_id = $document->add([
                    'plugin_assetmove_doctypes_id' => $doctypes_id,
                    'entities_id'                  => $entities_id,
                ]);

                if (!$documents_id) {
                    foreach ($ids as $id) {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                    }
                    $ma->addMessage(__('The document could not be created.', 'assetmove'));
                    return;
                }

                foreach ($ids as $id) {
                    $link = new self();
                    if ($link->add([
                        'plugin_assetmove_movements_id' => $documents_id,
                        'itemtype'                       => $item->getType(),
                        'items_id'                       => $id,
                    ])) {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
                    } else {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                    }
                }

                $ma->addMessage(sprintf(
                    __('Created %1$s: %2$s', 'assetmove'),
                    $document->getTypeName(1),
                    $document->fields['name'] ?? "#$documents_id"
                ));

                return;
        }

        parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
    }
}
