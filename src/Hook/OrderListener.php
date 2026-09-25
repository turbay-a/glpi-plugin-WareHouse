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

namespace GlpiPlugin\Assetmove\Hook;

use CommonDBTM;
use GlpiPlugin\Assetmove\Config;
use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\DocType;
use GlpiPlugin\Assetmove\Engine\AssetTypes;
use GlpiPlugin\Assetmove\Integration\OrderAdapter;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;
use GlpiPlugin\Assetmove\Warehouse;
use Log;

/**
 * Reacts to the Order plugin receiving items, auto-creating (or extending)
 * a reception Movement (TZ 10.3). Registered from setup.php only when
 * `Plugin::isPluginActive('order')` and the class actually exists; every
 * further Order read goes through OrderAdapter, never this plugin's own
 * `use PluginOrder...` at file scope, so disabling Order can never fatal
 * assetmove (TZ 16.3).
 */
final class OrderListener
{
    private function __construct()
    {
        // Static utility class, never instantiated.
    }

    /**
     * @param CommonDBTM $order_item A PluginOrderOrder_Item instance.
     */
    public static function onOrderItemUpdate(CommonDBTM $order_item): void
    {
        if (!Config::getConfig()->fields['is_order_integration_active']) {
            return;
        }

        $states_changed = in_array('states_id', $order_item->updates, true);
        $link_created    = in_array('items_id', $order_item->updates, true);

        if ($states_changed && !OrderAdapter::isReceived($order_item)) {
            // Reception was reverted (states_id no longer DELIVRED):
            // handled the same way as the order_item being purged outright.
            self::cancelLinkedMovement($order_item, __('Reception was reverted on the Order side.', 'assetmove'));

            return;
        }

        if (!$link_created && !$states_changed) {
            return;
        }

        $itemtype = (string) $order_item->fields['itemtype'];
        $items_id = (int) $order_item->fields['items_id'];

        if (
            $items_id <= 0
            || !OrderAdapter::isReceived($order_item)
            || !OrderAdapter::isEligibleForReception($itemtype)
        ) {
            return;
        }

        if (self::findMovementForAsset($itemtype, $items_id) !== null) {
            // Already linked (this hook firing again for the same
            // already-processed order_item, or reprocessing after a
            // partial failure): nothing more to do.
            return;
        }

        $doctype = DocType::getDefaultForReception();
        if ($doctype === null) {
            return;
        }

        $context = OrderAdapter::getReceptionContext($order_item);
        if ($context === null) {
            return;
        }

        $movements_id = self::findOrCreateBatchMovement($doctype, $context, $order_item->getID());
        if ($movements_id === null) {
            return;
        }

        (new Document_Item())->add([
            'plugin_assetmove_movements_id' => $movements_id,
            'itemtype'                       => $itemtype,
            'items_id'                       => $items_id,
        ]);
    }

    public static function onOrderItemPurge(CommonDBTM $order_item): void
    {
        self::cancelLinkedMovement($order_item, __('The linked Order item was deleted.', 'assetmove'));
    }

    /**
     * TZ 10.7: a fallback reception detector for sites that record a
     * supplier on Infocom directly instead of using the Order plugin.
     * Off by default (Config::is_infocom_fallback_active); always skips
     * items that are themselves an Order line, so an Order-driven
     * reception is never double-booked through this path too.
     *
     * @param CommonDBTM $infocom A core Infocom instance.
     */
    public static function onInfocomChange(CommonDBTM $infocom): void
    {
        if (!Config::getConfig()->fields['is_infocom_fallback_active']) {
            return;
        }

        $itemtype = (string) $infocom->fields['itemtype'];
        $items_id = (int) $infocom->fields['items_id'];

        if (
            (int) $infocom->fields['suppliers_id'] <= 0
            || $items_id <= 0
            || !OrderAdapter::isEligibleForReception($itemtype)
            || self::isFedByOrder($itemtype, $items_id)
            || self::findMovementForAsset($itemtype, $items_id) !== null
        ) {
            return;
        }

        $doctype = DocType::getDefaultForReception();
        if ($doctype === null) {
            return;
        }

        $input = [
            'plugin_assetmove_doctypes_id' => $doctype->getID(),
            'entities_id'                   => (int) $infocom->fields['entities_id'],
            'is_auto_generated'             => 1,
            'status'                        => Config::getConfig()->fields['reception_initial_status'],
        ];
        if ($doctype->fields['source_itemtype'] === 'Supplier') {
            $input['source_items_id'] = (int) $infocom->fields['suppliers_id'];
        }

        $movement     = new Movement();
        $movements_id = $movement->add($input);
        if (!$movements_id) {
            return;
        }

        (new Document_Item())->add([
            'plugin_assetmove_movements_id' => $movements_id,
            'itemtype'                       => $itemtype,
            'items_id'                       => $items_id,
        ]);
    }

    private static function isFedByOrder(string $itemtype, int $items_id): bool
    {
        if (!OrderAdapter::isAvailable()) {
            return false;
        }

        return countElementsInTable('glpi_plugin_order_orders_items', [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ]) > 0;
    }

    /**
     * TZ 10.5: an order_item being purged (or its reception reverted)
     * cancels the movement it fed, unless that movement is already Done --
     * a completed reception is never silently undone.
     */
    private static function cancelLinkedMovement(CommonDBTM $order_item, string $reason): void
    {
        $itemtype = (string) $order_item->fields['itemtype'];
        $items_id = (int) $order_item->fields['items_id'];

        $movements_id = self::findMovementForAsset($itemtype, $items_id);
        if ($movements_id === null) {
            return;
        }

        $movement = new Movement();
        if (!$movement->getFromDB($movements_id)) {
            return;
        }

        if ((int) $movement->fields['status'] === Status::DONE) {
            Log::history(
                $movements_id,
                Movement::class,
                [0, '', sprintf(__('Order-side change ignored (movement already Done): %s', 'assetmove'), $reason)],
                '',
                Log::HISTORY_LOG_SIMPLE_MESSAGE
            );
            // No dedicated event for this narrow edge case in TZ 13's list
            // of ten; the Log entry above is the audit trail an admin
            // reviewing the movement will see either way.

            return;
        }

        $movement->update([
            'id'                    => $movements_id,
            'status'                => Status::CANCELLED,
            '_internal_transition'  => true,
            'comment'               => trim(($movement->fields['comment'] ?? '') . "\n" . $reason),
        ]);
    }

    /**
     * @return int|null Movement id already carrying a Document_Item row
     *                   for this exact asset, if any.
     */
    private static function findMovementForAsset(string $itemtype, int $items_id): ?int
    {
        if ($items_id <= 0) {
            return null;
        }

        $rows = (new Document_Item())->find(
            ['itemtype' => $itemtype, 'items_id' => $items_id],
            ['id DESC'],
            1
        );
        $row = reset($rows);

        return $row ? (int) $row['plugin_assetmove_movements_id'] : null;
    }

    /**
     * Groups every order_item delivered together (same order + delivery
     * slip + date) onto one Movement (TZ 10.3's "40 laptops, one document"
     * requirement) by looking up a still-NEW movement whose deterministic
     * name (see buildReceptionName()) already matches this batch, creating
     * one if none exists yet.
     *
     * @param array $context OrderAdapter::getReceptionContext() result
     *
     * @return int|null
     */
    private static function findOrCreateBatchMovement(DocType $doctype, array $context, int $order_item_id): ?int
    {
        $name = self::buildReceptionName($context);

        $existing = (new Movement())->find([
            'name'                          => $name,
            'plugin_assetmove_doctypes_id'  => $doctype->getID(),
            'status'                        => Status::NEW,
        ], [], 1);
        $row = reset($existing);
        if ($row) {
            return (int) $row['id'];
        }

        [$dest_itemtype, $dest_items_id] = self::resolveDestination($doctype, $context);

        $input = [
            'plugin_assetmove_doctypes_id'  => $doctype->getID(),
            'entities_id'                    => $context['entities_id'],
            'name'                           => $name,
            'users_id_responsible'           => $context['users_id_delivery'],
            'groups_id_responsible'          => $context['groups_id_delivery'],
            'date_execution'                 => $context['delivery_date'],
            'source_origin_itemtype'         => 'PluginOrderOrder_Item',
            'source_origin_items_id'         => $order_item_id,
            'is_auto_generated'              => 1,
            'status'                         => Config::getConfig()->fields['reception_initial_status'],
        ];

        if ($doctype->fields['source_itemtype'] === 'Supplier' && $context['suppliers_id'] > 0) {
            $input['source_items_id'] = $context['suppliers_id'];
        }
        if ($dest_items_id > 0) {
            $input['dest_items_id'] = $dest_items_id;
        }

        $movement = new Movement();
        $movements_id = $movement->add($input);

        return $movements_id ? (int) $movements_id : null;
    }

    /**
     * @param array $context
     *
     * @return array{0: ?string, 1: int} [dest_itemtype, dest_items_id]
     */
    private static function resolveDestination(DocType $doctype, array $context): array
    {
        if ($doctype->fields['dest_itemtype'] === 'Location' && $context['locations_id'] > 0) {
            return ['Location', $context['locations_id']];
        }

        if ($doctype->fields['dest_itemtype'] === Warehouse::class) {
            $warehouse = new Warehouse();
            if (
                $warehouse->getFromDBByCrit(['is_default' => 1, 'entities_id' => $context['entities_id']])
                || $warehouse->getFromDBByCrit(['is_default' => 1])
            ) {
                return [Warehouse::class, (int) $warehouse->getID()];
            }
        }

        return [$doctype->fields['dest_itemtype'], 0];
    }

    private static function buildReceptionName(array $context): string
    {
        return trim(sprintf(
            '%s %s / %s',
            __('Reception', 'assetmove'),
            $context['num_order'] ?? '',
            $context['delivery_number'] ?? ''
        ));
    }
}
