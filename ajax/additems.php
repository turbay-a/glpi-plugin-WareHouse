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

use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Writeoff;

$movements_id = (int) ($_POST['plugin_assetmove_movements_id'] ?? 0);

if (isset($_POST['add'])) {
    $input = [
        'plugin_assetmove_movements_id' => $movements_id,
        'itemtype'                      => $_POST['itemtype'] ?? '',
        'items_id'                      => $_POST['items_id'] ?? 0,
    ];
    $link = new Document_Item();
    $link->check(-1, CREATE, $input);
    $link->add($input);
} elseif (isset($_POST['delete'])) {
    $link = new Document_Item();
    $link->check($_POST['id'], DELETE);
    $link->delete($_POST, true);
}

// The row lives in a table shared by Movement and Writeoff: redirect to
// whichever front page actually matches this document's kind.
$movement = new Movement();
$document = ($movement->getFromDB($movements_id) && (int) $movement->fields['kind'] === Writeoff::KIND)
    ? new Writeoff()
    : $movement;

Html::redirect($document->getFormURLWithID($movements_id));
