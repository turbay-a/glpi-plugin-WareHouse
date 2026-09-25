<?php

namespace GlpiPlugin\Assetmove\Tests\Engine;

use Computer;
use GlpiPlugin\Assetmove\Config;
use GlpiPlugin\Assetmove\DocType;
use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\Engine\Mover;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;
use Location;
use PHPUnit\Framework\TestCase;

/**
 * The two-phase ship -> (partial) reception flow (TZ 7.2/7.3): shipping
 * moves items to the transit location/state, and Mover::processReception()
 * applies each row's own accepted/missing/damaged outcome, only closing the
 * document to DONE once every row has been resolved. Authorization
 * (StateMachine::canShip()/canReceive()) is covered separately by SodTest --
 * processReception() itself performs no rights check by design (that is the
 * front controller's job), so it is exercised directly here.
 */
final class TwoPhaseTest extends TestCase
{
    private array $cleanup_computers = [];
    private array $cleanup_locations = [];
    private array $cleanup_doctypes = [];
    private array $cleanup_movements = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup_movements as $id) {
            (new Document_Item())->deleteByCriteria(['plugin_assetmove_movements_id' => $id], true);
            (new Movement())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_doctypes as $id) {
            (new DocType())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_computers as $id) {
            (new Computer())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_locations as $id) {
            (new Location())->delete(['id' => $id], true);
        }
    }

    public function testShippingMovesItemsToTheTransitLocationAndState(): void
    {
        [$movement, $movements_id, , $link_id] = $this->makeShippableMovement();

        $this->assertTrue((bool) $movement->update(['id' => $movements_id, 'status' => Status::APPROVED]));
        $this->assertTrue((bool) $movement->update(['id' => $movements_id, 'status' => Status::SHIPPED]));

        $movement->getFromDB($movements_id);
        $this->assertSame(Status::SHIPPED, (int) $movement->fields['status']);
        $this->assertNotEmpty($movement->fields['date_shipped']);

        $transit_states_id = (int) Config::getConfig()->fields['states_id_intransit'];
        $this->assertGreaterThan(0, $transit_states_id, 'Fixture assumption: the plugin config must have a transit state configured (set at plugin:install time).');

        $computer = new Computer();
        $computer->getFromDB($this->cleanup_computers[0]);
        $this->assertSame($transit_states_id, (int) $computer->fields['states_id']);

        $link = new Document_Item();
        $link->getFromDB($link_id);
        $this->assertSame(1, (int) $link->fields['is_shipped']);
        $this->assertSame(0, (int) $link->fields['is_moved'], 'Shipping alone must not apply the destination yet.');
    }

    public function testReceptionAcceptedRowGetsTheDestinationAndClosesTheDocument(): void
    {
        [$movement, $movements_id, $dest_locations_id, $link_id] = $this->makeShippableMovement();
        $movement->update(['id' => $movements_id, 'status' => Status::APPROVED]);
        $movement->update(['id' => $movements_id, 'status' => Status::SHIPPED]);
        $movement->getFromDB($movements_id);

        Mover::processReception($movement, [$link_id => 1]);

        $movement->getFromDB($movements_id);
        $this->assertSame(Status::DONE, (int) $movement->fields['status'], 'The only row was resolved: the document must close.');
        $this->assertSame(0, (int) $movement->fields['has_discrepancy']);

        $computer = new Computer();
        $computer->getFromDB($this->cleanup_computers[0]);
        $this->assertSame($dest_locations_id, (int) $computer->fields['locations_id']);

        $link = new Document_Item();
        $link->getFromDB($link_id);
        $this->assertSame(1, (int) $link->fields['is_moved']);
        $this->assertSame(1, (int) $link->fields['reception_status']);
    }

    public function testMissingRowFlagsDiscrepancyAndLeavesTheItemInPlace(): void
    {
        [$movement, $movements_id, $dest_locations_id, $link_id] = $this->makeShippableMovement();
        $movement->update(['id' => $movements_id, 'status' => Status::APPROVED]);
        $movement->update(['id' => $movements_id, 'status' => Status::SHIPPED]);
        $movement->getFromDB($movements_id);

        Mover::processReception($movement, [$link_id => 2]); // 2 = missing

        $movement->getFromDB($movements_id);
        $this->assertSame(1, (int) $movement->fields['has_discrepancy']);
        $this->assertSame(Status::DONE, (int) $movement->fields['status'], 'The only row was resolved (as missing): the document still closes.');

        $computer = new Computer();
        $computer->getFromDB($this->cleanup_computers[0]);
        $this->assertNotSame($dest_locations_id, (int) $computer->fields['locations_id'], 'A missing item must not be moved to the destination.');
    }

    public function testPartialReceptionKeepsTheDocumentShippedUntilEveryRowIsResolved(): void
    {
        $computers_id_2 = $this->makeComputer($this->makeLocation());

        [$movement, $movements_id, , $link_id_1] = $this->makeShippableMovement();
        $link_id_2 = (new Document_Item())->add([
            'plugin_assetmove_movements_id' => $movements_id,
            'itemtype'                        => Computer::class,
            'items_id'                        => $computers_id_2,
        ]);
        $this->assertGreaterThan(0, $link_id_2);

        $movement->update(['id' => $movements_id, 'status' => Status::APPROVED]);
        $movement->update(['id' => $movements_id, 'status' => Status::SHIPPED]);
        $movement->getFromDB($movements_id);

        Mover::processReception($movement, [$link_id_1 => 1]);

        $movement->getFromDB($movements_id);
        $this->assertSame(Status::SHIPPED, (int) $movement->fields['status'], 'One row still unresolved: the document must stay SHIPPED.');

        Mover::processReception($movement, [$link_id_2 => 1]);

        $movement->getFromDB($movements_id);
        $this->assertSame(Status::DONE, (int) $movement->fields['status']);
    }

    /**
     * @return array{0: Movement, 1: int, 2: int, 3: int} movement, movements_id, dest_locations_id, link_id
     */
    private function makeShippableMovement(): array
    {
        $source_locations_id = $this->makeLocation();
        $transit_locations_id = $this->makeLocation();
        $dest_locations_id   = $this->makeLocation();
        $computers_id        = $this->makeComputer($source_locations_id);

        $doctype = new DocType();
        $doctypes_id = $doctype->add([
            'name'                  => 'M8 TwoPhase DocType ' . uniqid(),
            'entities_id'           => 0,
            'is_active'             => 1,
            'kind'                  => Movement::KIND,
            'source_itemtype'       => 'Location',
            'dest_itemtype'         => 'Location',
            'is_two_phase'          => 1,
            'is_apply_location'     => 1,
            'locations_id_transit'  => $transit_locations_id,
            // The test session ships and receives as the same user;
            // without this the SoD check in StateMachine::canReceive()
            // (TZ 7.1: same person cannot both ship and receive) would
            // correctly block the SHIPPED -> DONE transition below.
            'is_selfreception_allowed' => 1,
        ]);
        $this->assertGreaterThan(0, $doctypes_id);
        $this->cleanup_doctypes[] = $doctypes_id;

        $movement = new Movement();
        $movements_id = $movement->add([
            'entities_id'                  => 0,
            'plugin_assetmove_doctypes_id' => $doctypes_id,
            'source_items_id'              => $source_locations_id,
            'dest_items_id'                => $dest_locations_id,
        ]);
        $this->assertGreaterThan(0, $movements_id);
        $this->cleanup_movements[] = $movements_id;

        $link_id = (new Document_Item())->add([
            'plugin_assetmove_movements_id' => $movements_id,
            'itemtype'                        => Computer::class,
            'items_id'                        => $computers_id,
        ]);
        $this->assertGreaterThan(0, $link_id);

        return [$movement, $movements_id, $dest_locations_id, $link_id];
    }

    private function makeLocation(): int
    {
        $location = new Location();
        $id = $location->add(['name' => 'M8 TwoPhase Location ' . uniqid(), 'entities_id' => 0]);
        $this->assertGreaterThan(0, $id);
        $this->cleanup_locations[] = $id;

        return $id;
    }

    private function makeComputer(int $locations_id): int
    {
        $computer = new Computer();
        $id = $computer->add([
            'name'         => 'M8 TwoPhase Computer ' . uniqid(),
            'entities_id'  => 0,
            'locations_id' => $locations_id,
        ]);
        $this->assertGreaterThan(0, $id);
        $this->cleanup_computers[] = $id;

        return $id;
    }
}
