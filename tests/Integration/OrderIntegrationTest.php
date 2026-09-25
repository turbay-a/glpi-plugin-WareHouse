<?php

namespace GlpiPlugin\Assetmove\Tests\Integration;

use Computer;
use GlpiPlugin\Assetmove\DocType;
use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\Hook\OrderListener;
use GlpiPlugin\Assetmove\Integration\OrderAdapter;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;
use Location;
use PHPUnit\Framework\TestCase;

/**
 * TZ 10: reacting to the Order plugin receiving an item. Requires the real
 * Order plugin active on this stand (verified true in PROGRESS.md's M7
 * section) -- OrderAdapter::isAvailable() is asserted first so this suite
 * fails loudly, not silently, if that stops being the case.
 */
final class OrderIntegrationTest extends TestCase
{
    private array $cleanup_computers = [];
    private array $cleanup_locations = [];
    private array $cleanup_doctypes = [];
    private array $cleanup_movements = [];
    private array $cleanup_order_items = [];
    private array $cleanup_orders = [];

    protected function setUp(): void
    {
        $this->assertTrue(OrderAdapter::isAvailable(), 'These tests require the Order plugin active on this stand.');
    }

    protected function tearDown(): void
    {
        global $DB;

        foreach ($this->cleanup_order_items as $id) {
            $DB->delete('glpi_plugin_order_orders_items', ['id' => $id]);
        }
        foreach ($this->cleanup_orders as $id) {
            $DB->delete('glpi_plugin_order_orders', ['id' => $id]);
        }
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

    public function testExcludedItemtypesAreNeverEligibleForReception(): void
    {
        $this->assertFalse(OrderAdapter::isEligibleForReception('SoftwareLicense'));
        $this->assertFalse(OrderAdapter::isEligibleForReception('ConsumableItem'));
        $this->assertTrue(OrderAdapter::isEligibleForReception('Computer'));
    }

    public function testReceivingAnOrderItemCreatesAMovementAndLinksTheAsset(): void
    {
        $this->makeDefaultReceptionDocType();
        $locations_id = $this->makeLocation();
        $computers_id = $this->makeComputer();

        $order_item = $this->deliverOrderItem($locations_id, $computers_id, 'Computer');

        OrderListener::onOrderItemUpdate($order_item);

        $links = (new Document_Item())->find(['itemtype' => Computer::class, 'items_id' => $computers_id]);
        $this->assertCount(1, $links);

        $link = reset($links);
        $this->cleanup_movements[] = (int) $link['plugin_assetmove_movements_id'];

        $movement = new Movement();
        $movement->getFromDB((int) $link['plugin_assetmove_movements_id']);
        $this->assertSame($locations_id, (int) $movement->fields['dest_items_id']);
        $this->assertSame(1, (int) $movement->fields['is_auto_generated']);
    }

    public function testReprocessingTheSameOrderItemDoesNotCreateADuplicateLink(): void
    {
        $this->makeDefaultReceptionDocType();
        $locations_id = $this->makeLocation();
        $computers_id = $this->makeComputer();

        $order_item = $this->deliverOrderItem($locations_id, $computers_id, 'Computer');

        OrderListener::onOrderItemUpdate($order_item);
        OrderListener::onOrderItemUpdate($order_item); // fires again, e.g. an unrelated field changing

        $links = (new Document_Item())->find(['itemtype' => Computer::class, 'items_id' => $computers_id]);
        $this->assertCount(1, $links, 'A second hook firing for the same asset must not duplicate the link.');

        $link = reset($links);
        $this->cleanup_movements[] = (int) $link['plugin_assetmove_movements_id'];
    }

    public function testTwoItemsFromTheSameDeliveryBatchShareOneMovement(): void
    {
        $this->makeDefaultReceptionDocType();
        $locations_id = $this->makeLocation();
        $computers_id_1 = $this->makeComputer();
        $computers_id_2 = $this->makeComputer();

        $orders_id = $this->makeOrder($locations_id);
        $order_item_1 = $this->deliverOrderItemForOrder($orders_id, $computers_id_1, 'Computer', 'DN-BATCH');
        $order_item_2 = $this->deliverOrderItemForOrder($orders_id, $computers_id_2, 'Computer', 'DN-BATCH');

        OrderListener::onOrderItemUpdate($order_item_1);
        OrderListener::onOrderItemUpdate($order_item_2);

        $rows_1 = (new Document_Item())->find(['itemtype' => Computer::class, 'items_id' => $computers_id_1]);
        $rows_2 = (new Document_Item())->find(['itemtype' => Computer::class, 'items_id' => $computers_id_2]);
        $link_1 = reset($rows_1);
        $link_2 = reset($rows_2);

        $this->assertNotFalse($link_1);
        $this->assertNotFalse($link_2);
        $this->assertSame(
            (int) $link_1['plugin_assetmove_movements_id'],
            (int) $link_2['plugin_assetmove_movements_id'],
            'Same order + same delivery number: one movement, not two.'
        );

        $this->cleanup_movements[] = (int) $link_1['plugin_assetmove_movements_id'];
    }

    public function testPurgingTheOrderItemCancelsAStillNewMovement(): void
    {
        $this->makeDefaultReceptionDocType();
        $locations_id = $this->makeLocation();
        $computers_id = $this->makeComputer();

        $order_item = $this->deliverOrderItem($locations_id, $computers_id, 'Computer');
        OrderListener::onOrderItemUpdate($order_item);

        $rows = (new Document_Item())->find(['itemtype' => Computer::class, 'items_id' => $computers_id]);
        $link = reset($rows);
        $movements_id = (int) $link['plugin_assetmove_movements_id'];
        $this->cleanup_movements[] = $movements_id;

        OrderListener::onOrderItemPurge($order_item);

        $movement = new Movement();
        $movement->getFromDB($movements_id);
        $this->assertSame(Status::CANCELLED, (int) $movement->fields['status']);
    }

    private function makeDefaultReceptionDocType(): int
    {
        $doctype = new DocType();
        $id = $doctype->add([
            'name'                      => 'M8 Order Reception DocType ' . uniqid(),
            'entities_id'               => 0,
            'is_active'                 => 1,
            'kind'                      => Movement::KIND,
            'source_itemtype'           => 'Supplier',
            'dest_itemtype'             => 'Location',
            'is_default_for_reception'  => 1,
        ]);
        $this->assertGreaterThan(0, $id);
        $this->cleanup_doctypes[] = $id;

        return $id;
    }

    private function makeLocation(): int
    {
        $location = new Location();
        $id = $location->add(['name' => 'M8 Order Location ' . uniqid(), 'entities_id' => 0]);
        $this->assertGreaterThan(0, $id);
        $this->cleanup_locations[] = $id;

        return $id;
    }

    private function makeComputer(): int
    {
        $computer = new Computer();
        $id = $computer->add(['name' => 'M8 Order Computer ' . uniqid(), 'entities_id' => 0]);
        $this->assertGreaterThan(0, $id);
        $this->cleanup_computers[] = $id;

        return $id;
    }

    private function makeOrder(int $locations_id): int
    {
        global $DB;

        $DB->insert('glpi_plugin_order_orders', [
            'entities_id'        => 0,
            'name'               => 'M8 Order ' . uniqid(),
            'num_order'          => 'ORD-' . uniqid(),
            'locations_id'       => $locations_id,
            'suppliers_id'       => 0,
            'users_id_delivery'  => 0,
            'groups_id_delivery' => 0,
        ]);
        $orders_id = (int) $DB->insertId();
        $this->cleanup_orders[] = $orders_id;

        return $orders_id;
    }

    /**
     * Mirrors the real Order sequence (insert not-yet-delivered, then a
     * second update carrying `states_id`+`items_id`+`itemtype`) using
     * direct `$DB` calls -- going through `PluginOrderOrder_Item::add()`/
     * `update()` hits Order's own business-rule validation ("a reference is
     * mandatory", "an analytic nature is mandatory"), which is Order
     * validating itself, not something this plugin's tests are exercising
     * (see PROGRESS.md's M7 section for the same reasoning).
     */
    private function deliverOrderItem(int $locations_id, int $items_id, string $itemtype): \PluginOrderOrder_Item
    {
        $orders_id = $this->makeOrder($locations_id);

        return $this->deliverOrderItemForOrder($orders_id, $items_id, $itemtype, 'DN-' . uniqid());
    }

    private function deliverOrderItemForOrder(int $orders_id, int $items_id, string $itemtype, string $delivery_number): \PluginOrderOrder_Item
    {
        global $DB;

        $DB->insert('glpi_plugin_order_orders_items', [
            'entities_id'             => 0,
            'plugin_order_orders_id'  => $orders_id,
            'itemtype'                => $itemtype,
            'items_id'                => 0,
            'states_id'               => 0,
            'delivery_number'         => $delivery_number,
        ]);
        $order_items_id = (int) $DB->insertId();
        $this->cleanup_order_items[] = $order_items_id;

        $DB->update('glpi_plugin_order_orders_items', [
            'items_id'  => $items_id,
            'states_id' => \PluginOrderOrder::ORDER_DEVICE_DELIVRED,
        ], ['id' => $order_items_id]);

        $order_item = new \PluginOrderOrder_Item();
        $order_item->getFromDB($order_items_id);
        $order_item->updates = ['itemtype', 'items_id', 'states_id'];

        return $order_item;
    }
}
