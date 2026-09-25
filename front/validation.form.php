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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Assetmove\Engine\ApprovalEngine;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Validation;
use GlpiPlugin\Assetmove\Writeoff;

$validation = new Validation();
if (!$validation->getFromDB((int) ($_POST['id'] ?? 0))) {
    throw new NotFoundHttpException();
}

$movements_id = (int) $validation->fields['plugin_assetmove_movements_id'];
$movement     = new Movement();
$movement->getFromDB($movements_id);

$is_writeoff = (int) $movement->fields['kind'] === Writeoff::KIND;
$document    = $is_writeoff ? new Writeoff() : $movement;
if ($is_writeoff) {
    $document->getFromDB($movements_id);
}

$needed_right = $is_writeoff ? Writeoff::RIGHT_APPROVE : Movement::RIGHT_APPROVE;
if (!Session::haveRight($document::$rightname, $needed_right)) {
    throw new AccessDeniedHttpException();
}

$comment = $_POST['comment'] ?? '';

if (isset($_POST['accept'])) {
    ApprovalEngine::answer($validation, Validation::STATUS_ACCEPTED, $comment);
} elseif (isset($_POST['refuse'])) {
    ApprovalEngine::answer($validation, Validation::STATUS_REFUSED, $comment);
}

Html::redirect($document->getFormURLWithID($movements_id));
