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

use GlpiPlugin\Assetmove\ApprovalStep;
use GlpiPlugin\Assetmove\Config;
use GlpiPlugin\Assetmove\DocType;
use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\Engine\AssetTypes;
use GlpiPlugin\Assetmove\Hook\OrderListener;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Profile;
use GlpiPlugin\Assetmove\Validation;
use GlpiPlugin\Assetmove\Warehouse;
use GlpiPlugin\Assetmove\Writeoff;

define('PLUGIN_ASSETMOVE_VERSION', '0.1.0');

// Minimal GLPI version, inclusive
define('PLUGIN_ASSETMOVE_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_ASSETMOVE_MAX_GLPI', '11.0.99');

/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * @return void
 */
function plugin_init_assetmove()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;
    /** @var array $CFG_GLPI */
    global $CFG_GLPI;

    $PLUGIN_HOOKS['csrf_compliant']['assetmove'] = true;

    if (!Plugin::isPluginActive('assetmove')) {
        return;
    }

    // DbUtils::getItemTypeForTable() guesses the itemtype back from a table
    // name by singularizing it with a fixed set of regex rules that has no
    // notion of "warehouse" (its `uses$ -> us` rule, meant for
    // status/es-like words, wrongly strips "warehouses" down to "warehous")
    // -- the same class of problem the core `releases` marketplace plugin
    // hits and fixes the same way (see its own setup.php). Without this,
    // Search::show() on Warehouse's list (front/warehouse.php) fatals in
    // Glpi\Search\Provider\SQLProvider::giveItem() trying to call a method
    // on a null itemtype for the "itemlink" name column.
    // `movements_items` (Document_Item, a relation table, not a plain
    // pluralized class name) hits the same gap for the same reason.
    $CFG_GLPI['glpiitemtypetables']['glpi_plugin_assetmove_warehouses']      = Warehouse::class;
    $CFG_GLPI['glpiitemtypetables']['glpi_plugin_assetmove_movements_items'] = Document_Item::class;

    $PLUGIN_HOOKS['change_profile']['assetmove'] = [Profile::class, 'changeProfile'];
    $PLUGIN_HOOKS['config_page']['assetmove']    = 'front/config.form.php';

    Plugin::registerClass(Profile::class, ['addtabon' => ['Profile']]);
    Plugin::registerClass(ApprovalStep::class, ['addtabon' => [DocType::class]]);
    // TZ 12.3 (items tab on the document itself) and TZ 12.4 (history tab on
    // the asset it references) are the same tab class, branching on which
    // side it is shown on -- see Document_Item::getTabNameForItem().
    Plugin::registerClass(Document_Item::class, [
        'addtabon' => array_merge([Movement::class, Writeoff::class], AssetTypes::getMovableTypes()),
    ]);
    Plugin::registerClass(Validation::class, ['addtabon' => [Movement::class, Writeoff::class]]);

    $PLUGIN_HOOKS['menu_toadd']['assetmove'] = [
        'config'     => [DocType::class, Warehouse::class, Config::class],
        'management' => [Movement::class, Writeoff::class],
    ];

    $PLUGIN_HOOKS['use_massive_action']['assetmove'] = 1;

    // TZ 10.2: only wired when Order is actually active, and every further
    // read of Order's data goes through Integration\OrderAdapter -- never
    // a `use PluginOrder...;` here or in OrderListener -- so disabling
    // Order can never fatal this plugin.
    if (Plugin::isPluginActive('order') && class_exists('PluginOrderOrder_Item')) {
        $PLUGIN_HOOKS['item_update']['assetmove']['PluginOrderOrder_Item']      = [OrderListener::class, 'onOrderItemUpdate'];
        $PLUGIN_HOOKS['pre_item_purge']['assetmove']['PluginOrderOrder_Item']   = [OrderListener::class, 'onOrderItemPurge'];
    }

    // TZ 10.7: opt-in fallback (Config::is_infocom_fallback_active, off by
    // default) for sites that record a supplier on Infocom without using
    // Order at all. Registered unconditionally -- Infocom is core -- the
    // config flag is what actually gates it.
    $PLUGIN_HOOKS['item_add']['assetmove']['Infocom']    = [OrderListener::class, 'onInfocomChange'];
    $PLUGIN_HOOKS['item_update']['assetmove']['Infocom'] = [OrderListener::class, 'onInfocomChange'];

    // No registration needed for plugin_assetmove_addDefaultWhere(): core
    // calls it automatically for any itemtype belonging to this plugin
    // (Glpi\Search\Provider\SQLProvider::getDefaultWhereCriteria() ->
    // Plugin::doOneHook($plugin, Hooks::AUTO_ADD_DEFAULT_WHERE, $itemtype),
    // unconditionally, not gated by a $PLUGIN_HOOKS entry).
}

/**
 * Get the name and the version of the plugin.
 * REQUIRED
 *
 * @return array
 */
function plugin_version_assetmove()
{
    return [
        'name'           => __('Asset movements', 'assetmove'),
        'version'        => PLUGIN_ASSETMOVE_VERSION,
        'author'         => 'Assetmove authors',
        'license'        => 'GPL-3.0-only',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_ASSETMOVE_MIN_GLPI,
                'max' => PLUGIN_ASSETMOVE_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install.
 * OPTIONAL
 *
 * @return boolean
 */
function plugin_assetmove_check_prerequisites()
{
    if (version_compare(PHP_VERSION, '8.2', '<')) {
        echo 'This plugin requires PHP >= 8.2';
        return false;
    }

    return true;
}

/**
 * Check configuration process.
 * OPTIONAL
 *
 * @param boolean $verbose Whether to display message on failure
 *
 * @return boolean
 */
function plugin_assetmove_check_config($verbose = false)
{
    return true;
}
