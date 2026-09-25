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

namespace GlpiPlugin\Assetmove\Capacity;

use CommonGLPI;
use Glpi\Asset\Capacity\AbstractCapacity;
use Glpi\Asset\CapacityConfig;
use GlpiPlugin\Assetmove\Document_Item;

/**
 * Opts a GLPI 11 custom asset definition into Assetmove: once enabled,
 * Engine\AssetTypes::getMovableTypes() picks the class up (TZ 9) and it
 * gets the same "Items" tab history that core hardware types show via
 * Document_Item (TZ 12.4/M8 acceptance).
 *
 * No real, working example of a capacity-registering plugin existed on
 * this stand to copy from (see NOTES.md/PROGRESS.md): written directly
 * against CapacityInterface/AbstractCapacity/AssetDefinitionManager plus
 * core's own HasNotepadCapacity as a model for onClassBootstrap()'s
 * registerStandardTab() usage.
 */
final class MovableCapacity extends AbstractCapacity
{
    public function getLabel(): string
    {
        return __('Movable (Assetmove)', 'assetmove');
    }

    public function getDescription(): string
    {
        return __('Lets this asset be listed on Assetmove movement/write-off documents.', 'assetmove');
    }

    public function getIcon(): string
    {
        return 'ti ti-transfer';
    }

    public function getCapacityUsageDescription(string $classname): string
    {
        return sprintf(
            __('%1$s assets referenced on an Assetmove document', 'assetmove'),
            countElementsInTable(Document_Item::getTable(), ['itemtype' => $classname])
        );
    }

    public function onClassBootstrap(string $classname, CapacityConfig $config): void
    {
        CommonGLPI::registerStandardTab($classname, Document_Item::class, 55);
    }

    /**
     * Historical Assetmove documents referencing this type are kept (TZ
     * 16.7): disabling the capacity only removes the tab, never the data.
     */
    public function onCapacityDisabled(string $classname, CapacityConfig $config): void
    {
    }
}
