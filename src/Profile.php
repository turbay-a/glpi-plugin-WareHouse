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

use CommonGLPI;
use Html;
use ProfileRight;
use Session;

/**
 * Adds the plugin's two right groups (Movement, Writeoff) as a tab on the
 * core Profile form.
 */
class Profile extends \Profile
{
    public static function getIcon()
    {
        return 'ti ti-truck-delivery';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && $item->getField('interface') !== 'helpdesk') {
            return self::createTabEntry(__('Asset movements', 'assetmove'));
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile) {
            $profile = new self();
            $profile->showForm($item->getID());
        }

        return true;
    }

    public function showForm($ID, array $options = [])
    {
        $profile = new \Profile();
        $profile->getFromDB($ID);

        $canedit = false;
        foreach (self::getRightnames() as $rightname) {
            if (Session::haveRightsOr($rightname, [UPDATE])) {
                $canedit = true;
                break;
            }
        }

        echo "<form method='post' action='" . $profile->getFormURL() . "'>";

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('Asset movements', 'assetmove'),
        ]);

        echo "<div class='center'>";
        echo Html::hidden('id', ['value' => $ID]);
        if ($canedit) {
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        }
        echo '</div>';
        Html::closeForm();

        return true;
    }

    /**
     * @return array
     */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => Movement::class,
                'label'    => Movement::getTypeName(2),
                'field'    => Movement::$rightname,
            ],
            [
                'itemtype' => Writeoff::class,
                'label'    => Writeoff::getTypeName(2),
                'field'    => Writeoff::$rightname,
            ],
            [
                'itemtype' => DocType::class,
                'label'    => DocType::getTypeName(2),
                'field'    => DocType::$rightname,
            ],
            [
                'itemtype' => Warehouse::class,
                'label'    => Warehouse::getTypeName(2),
                'field'    => Warehouse::$rightname,
            ],
            [
                'itemtype' => Config::class,
                'label'    => Config::getTypeName(),
                'field'    => Config::$rightname,
            ],
        ];
    }

    /**
     * @return string[]
     */
    private static function getRightnames(): array
    {
        return array_column(self::getAllRights(), 'field');
    }

    /**
     * Push this plugin's rights into the session on profile switch.
     *
     * @return void
     */
    public static function changeProfile(): void
    {
        if (!isset($_SESSION['glpiactiveprofile']['id'])) {
            return;
        }

        $rights = ProfileRight::getProfileRights(
            (int) $_SESSION['glpiactiveprofile']['id'],
            self::getRightnames()
        );

        foreach ($rights as $name => $value) {
            $_SESSION['glpiactiveprofile'][$name] = $value;
        }
    }
}
