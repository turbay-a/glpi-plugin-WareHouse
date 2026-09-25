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

namespace GlpiPlugin\Assetmove\Integration;

use CommonDBTM;
use GlpiPlugin\Assetmove\Engine\AssetTypes;
use Plugin;

/**
 * The ONLY place in this plugin allowed to know about the Order plugin's
 * classes (TZ 10.2/16.3): every other class reaches Order data only
 * through here, and only after checking isAvailable() -- disabling Order
 * must never break assetmove.
 *
 * Verified against the Order version actually installed on this stand
 * (2.12.6; the TZ was written against 2.12.9) by reading
 * plugins/order/inc/{reception,link,order_item,config}.class.php directly:
 * the reception flow (receptionOneItem -> generateAsset -> createLinkWithItem
 * -> the second PluginOrderOrder_Item::update() writing items_id/itemtype)
 * and every field name below match both versions.
 */
final class OrderAdapter
{
    /**
     * Itemtypes Order can generate that are never "assets" this plugin
     * moves/writes off (TZ 10.1): free-text lines, consumables/cartridges
     * (their own dedicated stock models), licenses and contracts.
     */
    private const EXCLUDED_ITEMTYPES = [
        'PluginOrderOther',
        'PluginOrderReferenceFree',
        'ConsumableItem',
        'CartridgeItem',
        'SoftwareLicense',
        'Contract',
    ];

    private function __construct()
    {
        // Static utility class, never instantiated.
    }

    public static function isAvailable(): bool
    {
        return Plugin::isPluginActive('order') && class_exists(\PluginOrderOrder_Item::class);
    }

    public static function isEligibleForReception(string $itemtype): bool
    {
        return !in_array($itemtype, self::EXCLUDED_ITEMTYPES, true) && AssetTypes::isMovable($itemtype);
    }

    public static function isReceived(CommonDBTM $order_item): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        return (int) $order_item->fields['states_id'] === \PluginOrderOrder::ORDER_DEVICE_DELIVRED;
    }

    /**
     * Everything OrderListener needs from Order for one order_item, read
     * once and handed back as a plain array so the rest of the plugin
     * never has to know Order's class names.
     *
     * @return array{plugin_order_orders_id:int, entities_id:int, delivery_date:?string,
     *               delivery_number:?string, num_order:?string, suppliers_id:int,
     *               locations_id:int, users_id_delivery:int, groups_id_delivery:int}|null
     */
    public static function getReceptionContext(CommonDBTM $order_item): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }

        $order = new \PluginOrderOrder();
        if (!$order->getFromDB($order_item->fields['plugin_order_orders_id'])) {
            return null;
        }

        return [
            'plugin_order_orders_id' => (int) $order_item->fields['plugin_order_orders_id'],
            'entities_id'            => (int) $order_item->fields['entities_id'],
            'delivery_date'          => $order_item->fields['delivery_date'],
            'delivery_number'        => $order_item->fields['delivery_number'],
            'num_order'              => $order->fields['num_order'],
            'suppliers_id'           => (int) $order->fields['suppliers_id'],
            'locations_id'           => (int) $order->fields['locations_id'],
            'users_id_delivery'      => (int) $order->fields['users_id_delivery'],
            'groups_id_delivery'     => (int) $order->fields['groups_id_delivery'],
        ];
    }

    /**
     * TZ 10.6: Order can itself set a state on the asset it just generated
     * (`PluginOrderConfig::getGeneratedAssetState()`). Used by Config's
     * form to warn the admin if both plugins are configured to fight over
     * `states_id`.
     */
    public static function getOrderGeneratedAssetState(): int
    {
        if (!Plugin::isPluginActive('order') || !class_exists(\PluginOrderConfig::class)) {
            return 0;
        }

        $config = \PluginOrderConfig::getConfig();

        return method_exists($config, 'getGeneratedAssetState') ? (int) $config->getGeneratedAssetState() : 0;
    }
}
