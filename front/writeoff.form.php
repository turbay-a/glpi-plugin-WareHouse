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

use GlpiPlugin\Assetmove\Writeoff;

if (!isset($_GET['id'])) {
    $_GET['id'] = '';
}

$writeoff = new Writeoff();

if (isset($_POST['add'])) {
    $writeoff->check(-1, CREATE, $_POST);
    $newID = $writeoff->add($_POST);
    Html::redirect(Writeoff::getFormURLWithID($newID));
} elseif (isset($_POST['delete'])) {
    $writeoff->check($_POST['id'], DELETE);
    $writeoff->delete($_POST);
    $writeoff->redirectToList();
} elseif (isset($_POST['restore'])) {
    $writeoff->check($_POST['id'], PURGE);
    $writeoff->restore($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $writeoff->check($_POST['id'], PURGE);
    $writeoff->delete($_POST, true);
    $writeoff->redirectToList();
} elseif (isset($_POST['update'])) {
    $writeoff->check($_POST['id'], UPDATE);
    $writeoff->update($_POST);
    Html::back();
} else {
    Html::header(Writeoff::getTypeName(1), '', 'management', Writeoff::class);
    $writeoff->display($_GET);
    Html::footer();
}
