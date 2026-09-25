<?php

namespace GlpiPlugin\Assetmove\Tests\Engine;

use Computer;
use GlpiPlugin\Assetmove\DocType as AssetmoveDocType;
use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;
use GlpiPlugin\Assetmove\Writeoff;
use Infocom;
use Location;
use PHPUnit\Framework\TestCase;
use State;

/**
 * Mover::execute() applying a document's effects to the assets it lists
 * (TZ 8.3 for write-offs, TZ 7.2/16.1 for a single-phase movement's
 * destination). Two-phase shipping/reception is covered separately by
 * TwoPhaseTest.
 */
final class MoverTest extends TestCase
{
    private array $cleanup_computers = [];
    private array $cleanup_locations = [];
    private array $cleanup_states = [];
    private array $cleanup_doctypes = [];
    private array $cleanup_movements = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup_movements as $id) {
            (new Document_Item())->deleteByCriteria(['plugin_assetmove_movements_id' => $id], true);
            (new Movement())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_doctypes as $id) {
            (new AssetmoveDocType())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_computers as $id) {
            (new Infocom())->deleteByCriteria(['itemtype' => Computer::class, 'items_id' => $id], true);
            (new Computer())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_states as $id) {
            (new State())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_locations as $id) {
            (new Location())->delete(['id' => $id], true);
        }
    }

    public function testWriteoffAppliesTargetStateClearsLocationAndFillsInfocomDate(): void
    {
        $locations_id     = $this->makeLocation();
        $target_states_id = $this->makeState();

        $computers_id = $this->makeComputer($locations_id, 0);

        // This GLPI instance auto-creates an Infocom row when a Computer is
        // added (an entity-level "auto financial info" setting) -- fetch
        // that row rather than adding a second one, which Infocom::add()
        // refuses (one Infocom per device).
        $infocom = new Infocom();
        $this->assertTrue($infocom->getFromDBforDevice(Computer::class, $computers_id));
        $infocoms_id = $infocom->getID();
        $this->assertEmpty($infocom->fields['decommission_date']);

        $doctypes_id = $this->makeDocType([
            'kind'              => Writeoff::KIND,
            'states_id_target'  => $target_states_id,
            'is_clear_location' => 1,
            'is_clear_user'     => 0,
        ]);

        $writeoff = new Writeoff();
        $writeoffs_id = $writeoff->add([
            'entities_id'                  => 0,
            'plugin_assetmove_doctypes_id' => $doctypes_id,
        ]);
        $this->assertGreaterThan(0, $writeoffs_id);
        $this->cleanup_movements[] = $writeoffs_id;

        $links_id = (new Document_Item())->add([
            'plugin_assetmove_movements_id' => $writeoffs_id,
            'itemtype'                        => Computer::class,
            'items_id'                        => $computers_id,
        ]);
        $this->assertGreaterThan(0, $links_id);

        $this->assertTrue((bool) $writeoff->update(['id' => $writeoffs_id, 'status' => Status::APPROVED]));
        $this->assertTrue((bool) $writeoff->update(['id' => $writeoffs_id, 'status' => Status::DONE]));

        $computer = new Computer();
        $computer->getFromDB($computers_id);
        $this->assertSame($target_states_id, (int) $computer->fields['states_id']);
        $this->assertSame(0, (int) $computer->fields['locations_id']);

        $infocom->getFromDB($infocoms_id);
        $this->assertNotEmpty($infocom->fields['decommission_date']);

        $link = new Document_Item();
        $link->getFromDB($links_id);
        $this->assertSame(1, (int) $link->fields['is_moved']);
    }

    public function testSinglePhaseMovementAppliesTheDestinationLocation(): void
    {
        $source_locations_id = $this->makeLocation();
        $dest_locations_id   = $this->makeLocation();

        $computers_id = $this->makeComputer($source_locations_id, 0);

        $doctypes_id = $this->makeDocType([
            'kind'              => Movement::KIND,
            'source_itemtype'   => 'Location',
            'dest_itemtype'     => 'Location',
            'is_two_phase'      => 0,
            'is_apply_location' => 1,
        ]);

        $movement = new Movement();
        $movements_id = $movement->add([
            'entities_id'                  => 0,
            'plugin_assetmove_doctypes_id' => $doctypes_id,
            'source_items_id'              => $source_locations_id,
            'dest_items_id'                => $dest_locations_id,
        ]);
        $this->assertGreaterThan(0, $movements_id);
        $this->cleanup_movements[] = $movements_id;

        (new Document_Item())->add([
            'plugin_assetmove_movements_id' => $movements_id,
            'itemtype'                        => Computer::class,
            'items_id'                        => $computers_id,
        ]);

        $movement->update(['id' => $movements_id, 'status' => Status::APPROVED]);
        $movement->update(['id' => $movements_id, 'status' => Status::DONE]);

        $computer = new Computer();
        $computer->getFromDB($computers_id);
        $this->assertSame($dest_locations_id, (int) $computer->fields['locations_id']);
    }

    private function makeLocation(): int
    {
        $location = new Location();
        $id = $location->add(['name' => 'M8 Mover Location ' . uniqid(), 'entities_id' => 0]);
        $this->assertGreaterThan(0, $id);
        $this->cleanup_locations[] = $id;

        return $id;
    }

    private function makeState(): int
    {
        $state = new State();
        $id = $state->add(['name' => 'M8 Mover State ' . uniqid()]);
        $this->assertGreaterThan(0, $id);
        $this->cleanup_states[] = $id;

        return $id;
    }

    private function makeComputer(int $locations_id, int $states_id): int
    {
        $computer = new Computer();
        $id = $computer->add([
            'name'         => 'M8 Mover Computer ' . uniqid(),
            'entities_id'  => 0,
            'locations_id' => $locations_id,
            'states_id'    => $states_id,
        ]);
        $this->assertGreaterThan(0, $id);
        $this->cleanup_computers[] = $id;

        return $id;
    }

    private function makeDocType(array $overrides): int
    {
        $doctype = new AssetmoveDocType();
        $id = $doctype->add(array_merge([
            'name'        => 'M8 Mover DocType ' . uniqid(),
            'entities_id' => 0,
            'is_active'   => 1,
        ], $overrides));
        $this->assertGreaterThan(0, $id);
        $this->cleanup_doctypes[] = $id;

        return $id;
    }
}
