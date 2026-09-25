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

namespace GlpiPlugin\Assetmove\Engine;

use Glpi\Asset\AssetDefinitionManager;
use GlpiPlugin\Assetmove\Capacity\MovableCapacity;

/**
 * Single place resolving which itemtypes can be moved/written off: the
 * core hardware types from `$CFG_GLPI['asset_types']`, plus any GLPI 11
 * custom asset definition that opted into the `MovableCapacity` capacity
 * (TZ 9).
 */
final class AssetTypes
{
    /**
     * Entries of `$CFG_GLPI['asset_types']` that are not physical items an
     * organization actually owns and moves around (licenses, certificates,
     * discovered-but-unclaimed network devices) and are therefore never
     * offered as movable/write-off-able, matching the exclusions already
     * made by the Order integration (see NOTES.md / TZ 10.1).
     */
    private const EXCLUDED_CORE_TYPES = ['SoftwareLicense', 'Certificate', 'Unmanaged'];

    private function __construct()
    {
        // Static utility class, never instantiated.
    }

    /**
     * @return class-string[] itemtypes selectable as document items
     */
    public static function getMovableTypes(): array
    {
        /** @var array<string, mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $types = [];
        foreach ($CFG_GLPI['asset_types'] ?? [] as $itemtype) {
            if (
                in_array($itemtype, self::EXCLUDED_CORE_TYPES, true)
                || !class_exists($itemtype)
                || !$itemtype::canView()
            ) {
                continue;
            }
            $types[] = $itemtype;
        }

        foreach (self::getCustomMovableTypes() as $itemtype) {
            if (!in_array($itemtype, $types, true)) {
                $types[] = $itemtype;
            }
        }

        return $types;
    }

    /**
     * GLPI 11 custom asset definitions with the MovableCapacity capacity
     * enabled (TZ 9's code sketch, adapted -- no live example plugin
     * registering a capacity existed on this stand to copy verbatim from,
     * see NOTES.md).
     *
     * @return class-string[]
     */
    private static function getCustomMovableTypes(): array
    {
        if (!class_exists(AssetDefinitionManager::class)) {
            return [];
        }

        $manager  = AssetDefinitionManager::getInstance();
        $capacity = new MovableCapacity();
        $manager->registerCapacity($capacity);
        $manager->bootDefinitions();

        $types = [];
        foreach ($manager->getDefinitions(true) as $definition) {
            if (!$definition->hasCapacityEnabled($capacity)) {
                continue;
            }

            $class = $definition->getAssetClassName();
            if (class_exists($class) && $class::canView()) {
                $types[] = $class;
            }
        }

        return $types;
    }

    /**
     * @return array<string, string> itemtype => label, for dropdowns
     */
    public static function getMovableTypesDropdownValues(): array
    {
        $values = [];
        foreach (self::getMovableTypes() as $itemtype) {
            $values[$itemtype] = $itemtype::getTypeName(1);
        }

        return $values;
    }

    public static function isMovable(string $itemtype): bool
    {
        return in_array($itemtype, self::getMovableTypes(), true);
    }
}
