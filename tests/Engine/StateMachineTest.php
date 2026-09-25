<?php

namespace GlpiPlugin\Assetmove\Tests\Engine;

use GlpiPlugin\Assetmove\Engine\StateMachine;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;
use GlpiPlugin\Assetmove\Writeoff;
use PHPUnit\Framework\TestCase;

/**
 * Pure transition-matrix logic (TZ 6): no DB fixtures needed, `->fields` is
 * set directly on a freshly-constructed (never DB-loaded) Movement/Writeoff,
 * matching how CommonDBTM is documented to behave before getFromDB()/add().
 */
final class StateMachineTest extends TestCase
{
    public function testNewCanGoToValidateApprovedOrCancelled(): void
    {
        $allowed = StateMachine::getAllowedTransitions()[Status::NEW];

        $this->assertEqualsCanonicalizing(
            [Status::TOVALIDATE, Status::APPROVED, Status::CANCELLED],
            $allowed
        );
    }

    public function testDoneAndCancelledAreTerminal(): void
    {
        $this->assertSame([], StateMachine::getAllowedTransitions()[Status::DONE]);
        $this->assertSame([], StateMachine::getAllowedTransitions()[Status::CANCELLED]);
        $this->assertSame([], StateMachine::getAllowedTransitions()[Status::REFUSED]);
    }

    public function testIsTransitionAllowedMatchesTheMatrix(): void
    {
        $this->assertTrue(StateMachine::isTransitionAllowed(Status::APPROVED, Status::SHIPPED));
        $this->assertTrue(StateMachine::isTransitionAllowed(Status::APPROVED, Status::DONE));
        $this->assertFalse(StateMachine::isTransitionAllowed(Status::NEW, Status::SHIPPED));
        $this->assertFalse(StateMachine::isTransitionAllowed(Status::DONE, Status::NEW));
    }

    public function testSameStatusIsAlwaysAllowed(): void
    {
        $movement = $this->makeMovement(['status' => Status::APPROVED]);

        $this->assertTrue(StateMachine::canTransition($movement, Status::APPROVED));
    }

    public function testDisallowedTransitionIsRejectedBeforeAnyRightCheck(): void
    {
        // NEW -> SHIPPED is not even in the matrix: must fail on the matrix
        // check alone, before ever touching Session::haveRight().
        $movement = $this->makeMovement(['status' => Status::NEW, 'is_two_phase' => 1]);

        $result = StateMachine::canTransition($movement, Status::SHIPPED);

        $this->assertIsString($result);
    }

    public function testOnlyTwoPhaseMovementsCanBeShipped(): void
    {
        $movement = $this->makeMovement(['status' => Status::APPROVED, 'is_two_phase' => 0]);

        $result = StateMachine::canTransition($movement, Status::SHIPPED);

        $this->assertIsString($result, 'A single-phase movement must not be shippable.');
    }

    public function testWriteoffCanNeverBeShipped(): void
    {
        $writeoff = $this->makeWriteoff(['status' => Status::APPROVED]);

        $result = StateMachine::canTransition($writeoff, Status::SHIPPED);

        $this->assertIsString($result);
    }

    public function testTovalidateToApprovedIsOnlyAllowedInternally(): void
    {
        $movement = $this->makeMovement(['status' => Status::TOVALIDATE]);

        $direct   = StateMachine::canTransition($movement, Status::APPROVED, false, false);
        $internal = StateMachine::canTransition($movement, Status::APPROVED, false, true);

        $this->assertIsString($direct, 'A user must not be able to force TOVALIDATE -> APPROVED directly.');
        $this->assertTrue($internal, 'Engine\ApprovalEngine driving the same transition internally must be allowed.');
    }

    public function testTwoPhaseMovementCannotSkipShippedWhenGoingFromApprovedToDone(): void
    {
        $movement = $this->makeMovement(['status' => Status::APPROVED, 'is_two_phase' => 1]);

        $result = StateMachine::canTransition($movement, Status::DONE, false, true);

        $this->assertIsString($result);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeMovement(array $overrides): Movement
    {
        $movement = new Movement();
        $movement->fields = array_merge($this->baseFields(), $overrides);

        return $movement;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeWriteoff(array $overrides): Writeoff
    {
        $writeoff = new Writeoff();
        $writeoff->fields = array_merge($this->baseFields(), $overrides);

        return $writeoff;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseFields(): array
    {
        return [
            'id'                            => 0,
            'plugin_assetmove_doctypes_id'  => 0,
            'is_two_phase'                  => 0,
            'source_itemtype'               => null,
            'source_items_id'               => 0,
            'dest_itemtype'                 => null,
            'dest_items_id'                 => 0,
            'is_selfreception_allowed'      => 0,
            'users_id_sender'               => 0,
        ];
    }
}
