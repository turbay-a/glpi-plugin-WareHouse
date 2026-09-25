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

use GlpiPlugin\Assetmove\DocType;

if (!isset($_GET['id'])) {
    $_GET['id'] = '';
}

$doctype = new DocType();

if (isset($_POST['add'])) {
    $doctype->check(-1, CREATE, $_POST);
    $newID = $doctype->add($_POST);
    Html::redirect(DocType::getFormURLWithID($newID));
} elseif (isset($_POST['delete'])) {
    $doctype->check($_POST['id'], DELETE);
    $doctype->delete($_POST);
    $doctype->redirectToList();
} elseif (isset($_POST['restore'])) {
    $doctype->check($_POST['id'], PURGE);
    $doctype->restore($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $doctype->check($_POST['id'], PURGE);
    $doctype->delete($_POST, true);
    $doctype->redirectToList();
} elseif (isset($_POST['update'])) {
    $doctype->check($_POST['id'], UPDATE);
    $doctype->update($_POST);
    Html::back();
} else {
    Html::header(DocType::getTypeName(1), '', 'config', DocType::class);
    $doctype->display($_GET);
    Html::footer();
}
