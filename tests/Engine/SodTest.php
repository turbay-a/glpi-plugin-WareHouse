<?php

namespace GlpiPlugin\Assetmove\Tests\Engine;

use GlpiPlugin\Assetmove\Engine\StateMachine;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Warehouse;
use PHPUnit\Framework\TestCase;

/**
 * Separation-of-duties checks (TZ 7.1): warehouse attachment (manager or
 * group membership) for ship/receive, and "the same person cannot both
 * ship and receive" for self-reception. The logged-in test user (`glpi`,
 * super-admin) always has every Movement right, so these tests exercise
 * the SoD logic itself, not the right check that happens to sit next to it.
 */
final class SodTest extends TestCase
{
    private int $glpi_users_id;

    private array $cleanup_locations = [];
    private array $cleanup_warehouses = [];
    private array $cleanup_groups = [];

    protected function setUp(): void
    {
        $this->glpi_users_id = (int) $_SESSION['glpiID'];
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup_warehouses as $id) {
            (new Warehouse())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_groups as $id) {
            (new \Group())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_locations as $id) {
            (new \Location())->delete(['id' => $id], true);
        }
    }

    public function testShipBlockedWhenNotTheWarehouseManager(): void
    {
        // manager is some other, unrelated user (id 0 is the "no manager"
        // sentinel everywhere else in this plugin, so pick a real, different
        // id: the very first non-glpi user in the table).
        $other_users_id = $this->anyOtherUserId();
        $warehouse_id   = $this->makeWarehouse(['users_id_manager' => $other_users_id]);

        $movement = $this->makeMovement([
            'source_itemtype'  => Warehouse::class,
            'source_items_id'  => $warehouse_id,
        ]);

        $result = StateMachine::canShip($movement, $this->glpi_users_id);

        $this->assertIsString($result);
    }

    public function testShipAllowedWhenIsTheWarehouseManager(): void
    {
        $warehouse_id = $this->makeWarehouse(['users_id_manager' => $this->glpi_users_id]);

        $movement = $this->makeMovement([
            'source_itemtype' => Warehouse::class,
            'source_items_id' => $warehouse_id,
        ]);

        $this->assertTrue(StateMachine::canShip($movement, $this->glpi_users_id));
    }

    public function testShipAllowedViaGroupMembershipWithoutBeingManager(): void
    {
        $group = new \Group();
        $groups_id = $group->add(['name' => 'M8 SoD Test Group', 'entities_id' => 0]);
        $this->cleanup_groups[] = $groups_id;

        (new \Group_User())->add(['groups_id' => $groups_id, 'users_id' => $this->glpi_users_id]);

        $warehouse_id = $this->makeWarehouse(['groups_id' => $groups_id]);

        $movement = $this->makeMovement([
            'source_itemtype' => Warehouse::class,
            'source_items_id' => $warehouse_id,
        ]);

        $this->assertTrue(StateMachine::canShip($movement, $this->glpi_users_id));
    }

    public function testShipAllowedWhenWarehouseHasNoManagerAndNoGroup(): void
    {
        $warehouse_id = $this->makeWarehouse([]);

        $movement = $this->makeMovement([
            'source_itemtype' => Warehouse::class,
            'source_items_id' => $warehouse_id,
        ]);

        $this->assertTrue(StateMachine::canShip($movement, $this->glpi_users_id));
    }

    public function testSelfReceptionIsBlockedByDefault(): void
    {
        $movement = $this->makeMovement([
            'users_id_sender'          => $this->glpi_users_id,
            'is_selfreception_allowed' => 0,
        ]);

        $result = StateMachine::canReceive($movement, $this->glpi_users_id);

        $this->assertIsString($result);
    }

    public function testSelfReceptionAllowedWhenDoctypeOptsIn(): void
    {
        $movement = $this->makeMovement([
            'users_id_sender'          => $this->glpi_users_id,
            'is_selfreception_allowed' => 1,
        ]);

        $this->assertTrue(StateMachine::canReceive($movement, $this->glpi_users_id));
    }

    public function testSelfReceptionAllowedWithForce(): void
    {
        $movement = $this->makeMovement([
            'users_id_sender'          => $this->glpi_users_id,
            'is_selfreception_allowed' => 0,
        ]);

        $this->assertTrue(StateMachine::canReceive($movement, $this->glpi_users_id, true));
    }

    public function testReceptionByADifferentUserThanTheSenderNeedsNoForce(): void
    {
        $other_users_id = $this->anyOtherUserId();

        $movement = $this->makeMovement([
            'users_id_sender'          => $other_users_id,
            'is_selfreception_allowed' => 0,
        ]);

        $this->assertTrue(StateMachine::canReceive($movement, $this->glpi_users_id));
    }

    private function makeMovement(array $overrides): Movement
    {
        $movement = new Movement();
        $movement->fields = array_merge([
            'id'                       => 0,
            'source_itemtype'          => null,
            'source_items_id'          => 0,
            'dest_itemtype'            => null,
            'dest_items_id'            => 0,
            'users_id_sender'          => 0,
            'is_selfreception_allowed' => 0,
        ], $overrides);

        return $movement;
    }

    private function makeWarehouse(array $overrides): int
    {
        $location = new \Location();
        $locations_id = $location->add(['name' => 'M8 SoD Location ' . uniqid(), 'entities_id' => 0]);
        $this->cleanup_locations[] = $locations_id;

        $warehouse = new Warehouse();
        $warehouses_id = $warehouse->add(array_merge([
            'name'         => 'M8 SoD Warehouse ' . uniqid(),
            'entities_id'  => 0,
            'locations_id' => $locations_id,
        ], $overrides));
        $this->cleanup_warehouses[] = $warehouses_id;

        return $warehouses_id;
    }

    private function anyOtherUserId(): int
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => ['NOT' => ['id' => $this->glpi_users_id]],
            'LIMIT' => 1,
        ])->current();

        $this->assertNotNull($row, 'Fixture assumption: at least one other user must exist in glpi_users.');

        return (int) $row['id'];
    }
}
