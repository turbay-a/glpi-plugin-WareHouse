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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Assetmove\Engine\PrintM11;
use GlpiPlugin\Assetmove\Movement;

$id = $_POST['id'] ?? $_GET['id'] ?? null;

$movement = new Movement();
if ($id === null || !$movement->getFromDB($id)) {
    throw new NotFoundHttpException();
}

$saved = false;

if (isset($_POST['save'])) {
    // check() throws AccessDeniedHttpException itself on a right failure --
    // same idiom as every other front controller in this plugin.
    $movement->check($movement->getID(), UPDATE);

    PrintM11::save($movement, $_POST);
    $movement->getFromDB($movement->getID());

    $data = PrintM11::build($movement);
    PrintM11::saveAsDocument($movement, $data);

    $saved = true;
} else {
    $movement->check($movement->getID(), READ);
}

$data = PrintM11::build($movement);

Html::popHeader(sprintf('%s - %s', Movement::getTypeName(1), $movement->fields['name']));
TemplateRenderer::getInstance()->display('@assetmove/movement_print_m11.html.twig', $data + ['saved' => $saved]);
Html::popFooter();
