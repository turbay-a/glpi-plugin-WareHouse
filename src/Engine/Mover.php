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

namespace GlpiPlugin\Assetmove\Engine;

use CommonITILObject;
use GlpiPlugin\Assetmove\AbstractDocument;
use GlpiPlugin\Assetmove\Config;
use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;
use GlpiPlugin\Assetmove\Warehouse;
use GlpiPlugin\Assetmove\Writeoff;
use Infocom;
use Item_Ticket;
use NotificationEvent;
use Session;
use Ticket;

/**
 * Applies a document's effects to the assets it lists, once StateMachine
 * has approved a status change (called from AbstractDocument::post_updateItem()).
 *
 * Every read here comes from the document's own fields -- snapshotted at
 * creation time by Movement::prepareInputForAdd()/DocType -- never from
 * DocType/Config directly (TZ 16.2: no reading a type's live configuration
 * while executing a document).
 */
final class Mover
{
    private function __construct()
    {
        // Static utility class, never instantiated.
    }

    public static function execute(AbstractDocument $doc, int $from_status): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $to = (int) $doc->fields['status'];

        $DB->beginTransaction();

        try {
            if ($doc instanceof Writeoff && $to === Status::DONE) {
                self::executeWriteoff($doc);
            } elseif ($doc instanceof Movement && $to === Status::SHIPPED) {
                self::shipMovement($doc);
            } elseif ($doc instanceof Movement && $to === Status::DONE && $from_status !== Status::SHIPPED) {
                // Single-phase completion. A two-phase movement reaches
                // DONE through processReception() instead, which already
                // applies every row's outcome (and sets the status itself)
                // before this method ever runs for it -- see that method.
                self::finishMovement($doc);
            }

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    /**
     * @return array Document_Item rows for this document that are not
     *               already applied.
     */
    private static function getPendingRows(AbstractDocument $doc): array
    {
        return (new Document_Item())->find([
            'plugin_assetmove_movements_id' => $doc->getID(),
            'is_moved'                       => 0,
        ]);
    }

    /**
     * Load the asset for a row, checking it is still there before Mover
     * touches it ("perevirka pozycii" -- an item deleted/changed out from
     * under a pending document should not silently corrupt data).
     *
     * @return \CommonDBTM|null
     */
    private static function loadRowItem(array $row): ?\CommonDBTM
    {
        if (!class_exists($row['itemtype'])) {
            return null;
        }

        $item = new $row['itemtype']();
        if (!$item->getFromDB($row['items_id']) || $item->isDeleted()) {
            return null;
        }

        return $item;
    }

    /**
     * APPROVED -> SHIPPED: move items to the transit location/state (TZ 7.2).
     * The asset is never left without a location: if no transit location is
     * configured on the document, it simply stays put until reception.
     */
    private static function shipMovement(Movement $doc): void
    {
        $config = Config::getConfig();

        foreach (self::getPendingRows($doc) as $row) {
            $item = self::loadRowItem($row);
            if ($item === null) {
                self::flagDiscrepancy($doc, $row, __('Item not found while shipping.', 'assetmove'));
                continue;
            }

            $update = ['id' => $item->getID()];
            if ((int) $doc->fields['locations_id_transit'] > 0 && $item->isField('locations_id')) {
                $update['locations_id'] = $doc->fields['locations_id_transit'];
            }
            if ((int) $config->fields['states_id_intransit'] > 0 && $item->isField('states_id')) {
                $update['states_id'] = $config->fields['states_id_intransit'];
            }
            if (count($update) > 1) {
                $item->update($update);
            }

            (new Document_Item())->update([
                'id'           => $row['id'],
                'is_shipped'   => 1,
                'date_shipped' => $_SESSION['glpi_currenttime'],
            ]);
        }

        $doc->update([
            'id'              => $doc->getID(),
            'date_shipped'    => $_SESSION['glpi_currenttime'],
            'users_id_sender' => Session::getLoginUserID(),
        ]);
    }

    /**
     * Single-phase APPROVED -> DONE: apply the document's destination to
     * every pending item and treat every row as accepted -- there is no
     * separate reception step to record a discrepancy against. Two-phase
     * reception (with per-row accepted/missing/damaged) is
     * processReception() instead, see TZ 7.3.
     */
    private static function finishMovement(Movement $doc): void
    {
        $destination = self::resolveDestinationFields($doc);

        foreach (self::getPendingRows($doc) as $row) {
            $item = self::loadRowItem($row);
            if ($item === null) {
                self::flagDiscrepancy($doc, $row, __('Item not found at reception.', 'assetmove'));
                continue;
            }

            $old = self::snapshotOldFields($item);

            $update = ['id' => $item->getID()] + $destination;
            if ((int) $doc->fields['states_id_target'] > 0 && $item->isField('states_id')) {
                $update['states_id'] = $doc->fields['states_id_target'];
            }
            if (count($update) > 1) {
                $item->update($update);
            }

            (new Document_Item())->update($old + [
                'id'               => $row['id'],
                'reception_status' => 1,
                'is_moved'         => 1,
                'date_moved'       => $_SESSION['glpi_currenttime'],
                'new_states_id'    => (int) $doc->fields['states_id_target'],
            ]);
        }

        $doc->update(['id' => $doc->getID(), 'date_received' => $_SESSION['glpi_currenttime']]);
    }

    /**
     * Two-phase reception (TZ 7.3): record each submitted row's outcome
     * (1=accepted, 2=missing, 3=damaged) and apply it to the asset --
     * missing items are left wherever they physically are (the transit
     * location) and just flagged, accepted/damaged ones get the
     * destination applied (damaged additionally gets Config's "damaged"
     * state instead of the document's target state). Once every row on
     * the document has a non-zero reception_status, the document itself
     * moves to DONE; until then it stays SHIPPED (partial reception).
     *
     * @param array<int, int> $row_statuses Document_Item id => reception_status
     */
    public static function processReception(Movement $doc, array $row_statuses): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->beginTransaction();

        try {
            $destination         = self::resolveDestinationFields($doc);
            $config              = Config::getConfig();
            $has_new_discrepancy = false;

            foreach ($row_statuses as $row_id => $reception_status) {
                $reception_status = (int) $reception_status;
                if (!in_array($reception_status, [1, 2, 3], true)) {
                    continue;
                }

                $row_item = new Document_Item();
                if (
                    !$row_item->getFromDB((int) $row_id)
                    || (int) $row_item->fields['plugin_assetmove_movements_id'] !== $doc->getID()
                    || (int) $row_item->fields['reception_status'] !== 0
                ) {
                    continue;
                }

                $item = self::loadRowItem($row_item->fields);
                if ($item === null) {
                    self::flagDiscrepancy($doc, $row_item->fields, __('Item not found at reception.', 'assetmove'));
                    continue;
                }

                $old    = self::snapshotOldFields($item);
                $update = ['id' => $item->getID()];

                if ($reception_status === 2) {
                    // Missing: stays in transit, only the state changes.
                    if ((int) $config->fields['states_id_lost'] > 0 && $item->isField('states_id')) {
                        $update['states_id'] = $config->fields['states_id_lost'];
                    }
                    $has_new_discrepancy = true;
                } else {
                    $update += $destination;
                    if ($reception_status === 3 && (int) $config->fields['states_id_damaged'] > 0 && $item->isField('states_id')) {
                        $update['states_id'] = $config->fields['states_id_damaged'];
                        $has_new_discrepancy = true;
                    } elseif ((int) $doc->fields['states_id_target'] > 0 && $item->isField('states_id')) {
                        $update['states_id'] = $doc->fields['states_id_target'];
                    }
                }

                if (count($update) > 1) {
                    $item->update($update);
                }

                $row_item->update($old + [
                    'id'               => $row_id,
                    'is_received'      => 1,
                    'date_received'    => $_SESSION['glpi_currenttime'],
                    'reception_status' => $reception_status,
                    'is_moved'         => 1,
                    'date_moved'       => $_SESSION['glpi_currenttime'],
                    'new_states_id'    => (int) ($update['states_id'] ?? 0),
                ]);
            }

            if ($has_new_discrepancy && !$doc->fields['has_discrepancy']) {
                $doc->update(['id' => $doc->getID(), 'has_discrepancy' => 1]);
            }

            $remaining = countElementsInTable(Document_Item::getTable(), [
                'plugin_assetmove_movements_id' => $doc->getID(),
                'reception_status'               => 0,
            ]);

            if ($remaining === 0) {
                $doc->update([
                    'id'                 => $doc->getID(),
                    'status'             => Status::DONE,
                    'users_id_receiver'  => Session::getLoginUserID(),
                    'date_received'      => $_SESSION['glpi_currenttime'],
                ]);
                // 'discrepancy'/'done' are raised by the update() above,
                // through the normal post_updateItem() path.
            }

            NotificationEvent::raiseEvent('received', $doc);

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    /**
     * @return array{old_locations_id:int, old_users_id:int, old_groups_id:int, old_states_id:int, old_entities_id:int}
     */
    private static function snapshotOldFields(\CommonDBTM $item): array
    {
        return [
            'old_locations_id' => $item->isField('locations_id') ? (int) $item->fields['locations_id'] : 0,
            'old_users_id'     => $item->isField('users_id') ? (int) $item->fields['users_id'] : 0,
            'old_groups_id'    => $item->isField('groups_id') ? (int) $item->fields['groups_id'] : 0,
            'old_states_id'    => $item->isField('states_id') ? (int) $item->fields['states_id'] : 0,
            'old_entities_id'  => (int) $item->fields['entities_id'],
        ];
    }

    /**
     * The asset fields a Movement's destination maps to, depending on what
     * kind of place/person it points at. Never touches entities_id (TZ
     * 16.1): an "Entity" destination is record-only, same as a Supplier
     * one (no core asset field represents "currently at this supplier").
     *
     * @return array<string, int>
     */
    private static function resolveDestinationFields(Movement $doc): array
    {
        $fields = [];

        if (!$doc->fields['is_apply_location'] && !$doc->fields['is_apply_user']) {
            return $fields;
        }

        $dest_itemtype = $doc->fields['dest_itemtype'];
        $dest_items_id = (int) $doc->fields['dest_items_id'];

        if ($dest_items_id <= 0) {
            return $fields;
        }

        if ($doc->fields['is_apply_location']) {
            if ($dest_itemtype === 'Location') {
                $fields['locations_id'] = $dest_items_id;
            } elseif ($dest_itemtype === Warehouse::class) {
                $warehouse = new Warehouse();
                if ($warehouse->getFromDB($dest_items_id) && (int) $warehouse->fields['locations_id'] > 0) {
                    $fields['locations_id'] = (int) $warehouse->fields['locations_id'];
                }
            }
        }

        if ($doc->fields['is_apply_user']) {
            if ($dest_itemtype === 'User') {
                $fields['users_id'] = $dest_items_id;
            } elseif ($dest_itemtype === 'Group') {
                $fields['groups_id'] = $dest_items_id;
            }
        }

        return $fields;
    }

    private static function flagDiscrepancy(AbstractDocument $doc, array $row, string $reason): void
    {
        (new Document_Item())->update([
            'id'      => $row['id'],
            'comment' => trim(($row['comment'] ?? '') . "\n" . $reason),
        ]);
        if (!$doc->fields['has_discrepancy']) {
            $doc->update(['id' => $doc->getID(), 'has_discrepancy' => 1]);
        }
    }

    /**
     * Writeoff execution (TZ 8.3): stamp the target state, optionally clear
     * location/user, fill Infocom's decommission date, close open tickets,
     * and (opt-in, defaults to off per TZ 16.6 -- GLPI's own trash bin
     * semantics are not "written off") move the item to the trash bin.
     */
    private static function executeWriteoff(Writeoff $doc): void
    {
        $config = Config::getConfig();

        foreach (self::getPendingRows($doc) as $row) {
            $item = self::loadRowItem($row);
            if ($item === null) {
                self::flagDiscrepancy($doc, $row, __('Item not found while executing the write-off.', 'assetmove'));
                continue;
            }

            $old = self::snapshotOldFields($item);

            $update = ['id' => $item->getID()];
            if ((int) $doc->fields['states_id_target'] > 0 && $item->isField('states_id')) {
                $update['states_id'] = $doc->fields['states_id_target'];
            }
            if ($doc->fields['is_clear_user']) {
                if ($item->isField('users_id')) {
                    $update['users_id'] = 0;
                }
                if ($item->isField('groups_id')) {
                    $update['groups_id'] = 0;
                }
            }
            if ($doc->fields['is_clear_location'] && $item->isField('locations_id')) {
                $update['locations_id'] = 0;
            }
            if (count($update) > 1) {
                $item->update($update);
            }

            if ($config->fields['is_fill_infocom_date_on_writeoff']) {
                self::fillInfocomDecommissionDate($item);
            }
            if ($config->fields['is_close_tickets_on_writeoff']) {
                self::closeOpenTickets($item, $doc);
            }

            (new Document_Item())->update($old + [
                'id'            => $row['id'],
                'is_moved'      => 1,
                'date_moved'    => $_SESSION['glpi_currenttime'],
                'new_states_id' => (int) $doc->fields['states_id_target'],
            ]);

            if ($config->fields['is_move_to_trash_on_writeoff']) {
                $item->delete(['id' => $item->getID()]);
            }
        }
    }

    private static function fillInfocomDecommissionDate(\CommonDBTM $item): void
    {
        $infocom = new Infocom();
        if (!$infocom->getFromDBforDevice($item->getType(), $item->getID())) {
            return;
        }
        if (!empty($infocom->fields['decommission_date'])) {
            return;
        }

        $infocom->update([
            'id'                 => $infocom->getID(),
            'decommission_date'  => $_SESSION['glpi_currenttime'],
        ]);
    }

    private static function closeOpenTickets(\CommonDBTM $item, Writeoff $doc): void
    {
        $links = (new Item_Ticket())->find([
            'itemtype' => $item->getType(),
            'items_id' => $item->getID(),
        ]);

        foreach ($links as $link) {
            $ticket = new Ticket();
            if (
                $ticket->getFromDB($link['tickets_id'])
                && !in_array((int) $ticket->fields['status'], Ticket::getClosedStatusArray(), true)
            ) {
                $ticket->update([
                    'id'                 => $ticket->getID(),
                    'status'             => CommonITILObject::CLOSED,
                    '_accepted'          => 1,
                    'closedate'          => $_SESSION['glpi_currenttime'],
                ]);
            }
        }
    }
}
