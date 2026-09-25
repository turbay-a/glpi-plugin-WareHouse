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
use GlpiPlugin\Assetmove\Engine\Mover;
use GlpiPlugin\Assetmove\Engine\StateMachine;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;

$movements_id = (int) ($_POST['plugin_assetmove_movements_id'] ?? 0);

$movement = new Movement();
if (!$movement->getFromDB($movements_id)) {
    throw new NotFoundHttpException();
}

if ((int) $movement->fields['status'] !== Status::SHIPPED) {
    throw new AccessDeniedHttpException();
}

if (StateMachine::canReceive($movement, (int) Session::getLoginUserID()) !== true) {
    throw new AccessDeniedHttpException();
}

if (isset($_POST['receive']) && is_array($_POST['reception'] ?? null)) {
    Mover::processReception($movement, $_POST['reception']);
}

Html::redirect(Movement::getFormURLWithID($movements_id));
