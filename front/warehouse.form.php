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

use GlpiPlugin\Assetmove\Warehouse;

if (!isset($_GET['id'])) {
    $_GET['id'] = '';
}

$warehouse = new Warehouse();

if (isset($_POST['add'])) {
    $warehouse->check(-1, CREATE, $_POST);
    $newID = $warehouse->add($_POST);
    Html::redirect(Warehouse::getFormURLWithID($newID));
} elseif (isset($_POST['delete'])) {
    $warehouse->check($_POST['id'], DELETE);
    $warehouse->delete($_POST);
    $warehouse->redirectToList();
} elseif (isset($_POST['restore'])) {
    $warehouse->check($_POST['id'], PURGE);
    $warehouse->restore($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $warehouse->check($_POST['id'], PURGE);
    $warehouse->delete($_POST, true);
    $warehouse->redirectToList();
} elseif (isset($_POST['update'])) {
    $warehouse->check($_POST['id'], UPDATE);
    $warehouse->update($_POST);
    Html::back();
} else {
    Html::header(Warehouse::getTypeName(1), '', 'config', Warehouse::class);
    $warehouse->display($_GET);
    Html::footer();
}
