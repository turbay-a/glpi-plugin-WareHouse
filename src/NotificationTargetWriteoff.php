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

/**
 * GLPI resolves the notification target class from the itemtype's own
 * short name (`NotificationTarget::getInstanceClass()`): a
 * `GlpiPlugin\Assetmove\Writeoff` event needs a
 * `GlpiPlugin\Assetmove\NotificationTargetWriteoff` class to actually fire,
 * even though the logic (NotificationTargetMovement, despite its name) is
 * entirely shared between both document kinds.
 */
class NotificationTargetWriteoff extends NotificationTargetMovement
{
}
