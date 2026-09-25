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

use GlpiPlugin\Assetmove\Config;

$config = Config::getConfig();

if (isset($_POST['update'])) {
    $config->check($config->getID(), UPDATE, $_POST);
    $config->update($_POST + ['id' => $config->getID()]);
    Html::back();
} else {
    Html::header(Config::getTypeName(), '', 'config', Config::class);
    $config->showForm($config->getID());
    Html::footer();
}
