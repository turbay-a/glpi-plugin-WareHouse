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
 * Singleton settings row (always id=1, created by hook.php's install()).
 */
class Config extends CommonDBTM
{
    public static $rightname = 'plugin_assetmove_config';

    public static function getTypeName($nb = 0)
    {
        return __('Asset movements configuration', 'assetmove');
    }

    public static function getIcon()
    {
        return 'ti ti-settings';
    }

    /**
     * @return self
     */
    public static function getConfig(): self
    {
        $config = new self();
        $config->getFromDB(1);

        return $config;
    }

    public function canViewItem(): bool
    {
        return static::canView();
    }

    public function canUpdateItem(): bool
    {
        return static::canUpdate();
    }

    /**
     * Config is a configuration singleton, not a listable itemtype: point
     * the menu entry straight at its (only) form instead of a search page.
     */
    public static function getMenuContent()
    {
        if (!static::canView()) {
            return false;
        }

        return [
            'title' => static::getTypeName(),
            'page'  => static::getFormURLWithID(1, false),
            'icon'  => static::getIcon(),
            'links' => [
                'search' => static::getFormURLWithID(1, false),
            ],
        ];
    }

    public function showForm($ID, array $options = [])
    {
        $this->getFromDB(1);

        TemplateRenderer::getInstance()->display('@assetmove/config.html.twig', [
            'item'                        => $this,
            'params'                      => $options,
            'order_generated_asset_state' => Integration\OrderAdapter::getOrderGeneratedAssetState(),
        ]);

        return true;
    }
}
