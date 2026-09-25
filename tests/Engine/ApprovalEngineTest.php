<?php

namespace GlpiPlugin\Assetmove\Tests\Engine;

use GlpiPlugin\Assetmove\ApprovalStep;
use GlpiPlugin\Assetmove\DocType;
use GlpiPlugin\Assetmove\Engine\ApprovalEngine;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;
use GlpiPlugin\Assetmove\Validation;
use PHPUnit\Framework\TestCase;

/**
 * Route resolution and the approve/refuse state machine (TZ 8), against
 * real DocType/ApprovalStep/Movement/Validation rows -- resolveRoute() and
 * the step-completion logic read the DB directly, so this is a functional
 * test, not a pure-unit one.
 */
final class ApprovalEngineTest extends TestCase
{
    private array $cleanup_doctypes = [];
    private array $cleanup_movements = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup_movements as $id) {
            (new Validation())->deleteByCriteria(['plugin_assetmove_movements_id' => $id], true);
            (new Movement())->delete(['id' => $id], true);
        }
        foreach ($this->cleanup_doctypes as $id) {
            (new ApprovalStep())->deleteByCriteria(['plugin_assetmove_doctypes_id' => $id]);
            (new DocType())->delete(['id' => $id], true);
        }
    }

    public function testCanSubmitFailsWhenTheRouteHasNoSteps(): void
    {
        $doctypes_id = $this->makeDocType();
        $movement    = $this->makeMovement($doctypes_id);

        $result = ApprovalEngine::canSubmit($movement);

        $this->assertIsString($result);
    }

    public function testCanSubmitSucceedsWithASingleUserStep(): void
    {
        $doctypes_id = $this->makeDocType();
        $this->addStep($doctypes_id, 1, ApprovalStep::APPROVER_USER, $this->currentUserId());
        $movement = $this->makeMovement($doctypes_id);

        $this->assertTrue(ApprovalEngine::canSubmit($movement));
    }

    public function testSingleStepApprovalMovesDocumentToApproved(): void
    {
        $doctypes_id = $this->makeDocType();
        $this->addStep($doctypes_id, 1, ApprovalStep::APPROVER_USER, $this->currentUserId());
        $movement = $this->makeMovement($doctypes_id);

        $this->assertTrue((bool) $movement->update(['id' => $movement->getID(), 'status' => Status::TOVALIDATE]));

        $validation = $this->soleWaitingValidation($movement->getID());
        $this->assertNotNull($validation);

        $this->assertTrue(ApprovalEngine::answer($validation, Validation::STATUS_ACCEPTED, 'ok'));

        $movement->getFromDB($movement->getID());
        $this->assertSame(Status::APPROVED, (int) $movement->fields['status']);
    }

    public function testRefusalMovesDocumentToRefusedRegardlessOfOtherSteps(): void
    {
        $doctypes_id = $this->makeDocType();
        $this->addStep($doctypes_id, 1, ApprovalStep::APPROVER_USER, $this->currentUserId());
        $movement = $this->makeMovement($doctypes_id);
        $movement->update(['id' => $movement->getID(), 'status' => Status::TOVALIDATE]);

        $validation = $this->soleWaitingValidation($movement->getID());
        $this->assertTrue(ApprovalEngine::answer($validation, Validation::STATUS_REFUSED, 'no'));

        $movement->getFromDB($movement->getID());
        $this->assertSame(Status::REFUSED, (int) $movement->fields['status']);
    }

    public function testTwoSequentialStepsActivateTheSecondOnlyAfterTheFirstIsAccepted(): void
    {
        $other_users_id = $this->anyOtherUserId();
        $doctypes_id    = $this->makeDocType();
        $this->addStep($doctypes_id, 1, ApprovalStep::APPROVER_USER, $this->currentUserId());
        $this->addStep($doctypes_id, 2, ApprovalStep::APPROVER_USER, $other_users_id);
        $movement = $this->makeMovement($doctypes_id);
        $movement->update(['id' => $movement->getID(), 'status' => Status::TOVALIDATE]);

        $rows = (new Validation())->find(['plugin_assetmove_movements_id' => $movement->getID()], ['step_order ASC']);
        $this->assertCount(2, $rows);

        $step1 = new Validation();
        $step1->getFromDB(array_values($rows)[0]['id']);
        $step2_before = array_values($rows)[1];
        $this->assertSame(Validation::STATUS_NOT_STARTED, (int) $step2_before['status']);

        ApprovalEngine::answer($step1, Validation::STATUS_ACCEPTED, 'ok');

        $step2 = new Validation();
        $step2->getFromDB($step2_before['id']);
        $this->assertSame(Validation::STATUS_WAITING, (int) $step2->fields['status'], 'Second step must activate once the first is accepted.');

        $movement->getFromDB($movement->getID());
        $this->assertSame(Status::TOVALIDATE, (int) $movement->fields['status'], 'Document must stay pending until every step is resolved.');

        // Step 2's approver is a different user: Validation::canAnswer()
        // requires the answering session to actually be that user, so
        // switch $_SESSION['glpiID'] the same way M5's tests did to reach
        // SoD-gated code as a specific user without a second Session::init()
        // (session_regenerate_id() is unavailable outside an HTTP request).
        $original_users_id = (int) $_SESSION['glpiID'];
        $_SESSION['glpiID'] = $other_users_id;
        ApprovalEngine::answer($step2, Validation::STATUS_ACCEPTED, 'ok');
        $_SESSION['glpiID'] = $original_users_id;

        $movement->getFromDB($movement->getID());
        $this->assertSame(Status::APPROVED, (int) $movement->fields['status']);
    }

    public function testConditionMinPriceSkipsAStepWhenTheDocumentHasNoValue(): void
    {
        $doctypes_id = $this->makeDocType();
        $this->addStep($doctypes_id, 1, ApprovalStep::APPROVER_USER, $this->currentUserId(), 1000.0);
        $movement = $this->makeMovement($doctypes_id);

        // No items with an Infocom value attached -> computed value is 0,
        // which is below the step's condition_min_price -> route is empty.
        $result = ApprovalEngine::canSubmit($movement);

        $this->assertIsString($result);
    }

    private function makeDocType(): int
    {
        $doctype = new DocType();
        $doctypes_id = $doctype->add([
            'name'            => 'M8 PHPUnit DocType ' . uniqid(),
            'entities_id'     => 0,
            'kind'            => Movement::KIND,
            'is_active'       => 1,
            'source_itemtype' => 'Entity',
            'dest_itemtype'   => 'Entity',
        ]);
        $this->assertGreaterThan(0, $doctypes_id);
        $this->cleanup_doctypes[] = $doctypes_id;

        return $doctypes_id;
    }

    private function addStep(int $doctypes_id, int $step_order, string $approver_type, int $approver_id, ?float $min_price = null): int
    {
        $step = new ApprovalStep();
        $id = $step->add([
            'plugin_assetmove_doctypes_id' => $doctypes_id,
            'step_order'                    => $step_order,
            'mode'                           => ApprovalStep::MODE_ALL,
            'is_mandatory'                   => 1,
            'approver_type'                  => $approver_type,
            'approver_id'                    => $approver_id,
            'condition_min_price'            => $min_price,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function makeMovement(int $doctypes_id): Movement
    {
        $movement = new Movement();
        $movements_id = $movement->add([
            'entities_id'                  => 0,
            'plugin_assetmove_doctypes_id' => $doctypes_id,
            'source_items_id'              => 0,
            'dest_items_id'                => 0,
        ]);
        $this->assertGreaterThan(0, $movements_id);
        $this->cleanup_movements[] = $movements_id;

        return $movement;
    }

    private function soleWaitingValidation(int $movements_id): ?Validation
    {
        $rows = (new Validation())->find([
            'plugin_assetmove_movements_id' => $movements_id,
            'status'                          => Validation::STATUS_WAITING,
        ]);
        $row = reset($rows);
        if (!$row) {
            return null;
        }

        $validation = new Validation();
        $validation->getFromDB($row['id']);

        return $validation;
    }

    private function currentUserId(): int
    {
        return (int) $_SESSION['glpiID'];
    }

    private function anyOtherUserId(): int
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => ['NOT' => ['id' => $this->currentUserId()]],
            'LIMIT' => 1,
        ])->current();

        $this->assertNotNull($row);

        return (int) $row['id'];
    }
}
