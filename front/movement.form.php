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

use GlpiPlugin\Assetmove\Movement;

if (!isset($_GET['id'])) {
    $_GET['id'] = '';
}

$movement = new Movement();

if (isset($_POST['add'])) {
    $movement->check(-1, CREATE, $_POST);
    $newID = $movement->add($_POST);
    Html::redirect(Movement::getFormURLWithID($newID));
} elseif (isset($_POST['delete'])) {
    $movement->check($_POST['id'], DELETE);
    $movement->delete($_POST);
    $movement->redirectToList();
} elseif (isset($_POST['restore'])) {
    $movement->check($_POST['id'], PURGE);
    $movement->restore($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $movement->check($_POST['id'], PURGE);
    $movement->delete($_POST, true);
    $movement->redirectToList();
} elseif (isset($_POST['update'])) {
    $movement->check($_POST['id'], UPDATE);
    $movement->update($_POST);
    Html::back();
} else {
    Html::header(Movement::getTypeName(1), '', 'management', Movement::class);
    $movement->display($_GET);
    Html::footer();
}
