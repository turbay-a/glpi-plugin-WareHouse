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

use GlpiPlugin\Assetmove\AbstractDocument;
use GlpiPlugin\Assetmove\DocType;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;
use GlpiPlugin\Assetmove\Warehouse;
use GlpiPlugin\Assetmove\Writeoff;
use Log;
use Session;

/**
 * The one place that knows which status transitions exist and what a user
 * needs to be allowed to trigger them (TZ 6). Both AbstractDocument's
 * prepareInputForUpdate() (the enforcement point -- hiding a button in a
 * template is not enough, TZ 16.9) and the form templates (to decide which
 * buttons to even show) go through this class rather than duplicating the
 * matrix.
 */
final class StateMachine
{
    private function __construct()
    {
        // Static utility class, never instantiated.
    }

    /**
     * @return array<int, int[]> from status => allowed target statuses
     */
    public static function getAllowedTransitions(): array
    {
        return [
            Status::NEW        => [Status::TOVALIDATE, Status::APPROVED, Status::CANCELLED],
            Status::TOVALIDATE => [Status::APPROVED, Status::REFUSED, Status::CANCELLED],
            Status::APPROVED   => [Status::SHIPPED, Status::DONE, Status::CANCELLED],
            Status::SHIPPED    => [Status::DONE, Status::CANCELLED],
            Status::DONE       => [],
            Status::CANCELLED  => [],
            Status::REFUSED    => [],
        ];
    }

    public static function isTransitionAllowed(int $from, int $to): bool
    {
        return in_array($to, self::getAllowedTransitions()[$from] ?? [], true);
    }

    /**
     * Whether $doc may move from its current (persisted) status to $to
     * right now.
     *
     * @param AbstractDocument $doc      The document, fields already loaded (pre-update).
     * @param int              $to       Requested target status.
     * @param bool             $force    Whether the caller asked to bypass the normal
     *                                   SHIPPED->CANCELLED restriction (still requires the
     *                                   FORCE right on top -- ignored when $internal).
     * @param bool             $internal True for a transition Engine\ApprovalEngine drives
     *                                   itself (TOVALIDATE -> APPROVED/REFUSED once every
     *                                   step is resolved, or the post-approval autoexecute):
     *                                   structural preconditions still apply, but no right is
     *                                   required from whichever user's session happens to be
     *                                   active when the engine runs.
     *
     * @return true|string True if allowed, otherwise a human-readable reason it is not.
     */
    public static function canTransition(AbstractDocument $doc, int $to, bool $force = false, bool $internal = false): true|string
    {
        $from = (int) $doc->fields['status'];

        if ($from === $to) {
            return true;
        }

        if (!self::isTransitionAllowed($from, $to)) {
            return __('This status change is not allowed from the current status.', 'assetmove');
        }

        if ($from === Status::TOVALIDATE && !$internal) {
            // Per TZ 6, TOVALIDATE -> APPROVED/REFUSED only happens
            // automatically once every mandatory approval step is
            // resolved (Engine\ApprovalEngine). No direct user right
            // grants it.
            return __('This transition can only happen automatically once approval is complete.', 'assetmove');
        }

        if ($to === Status::SHIPPED && (!($doc instanceof Movement) || !$doc->fields['is_two_phase'])) {
            return __('Only two-phase movements can be shipped.', 'assetmove');
        }

        if ($to === Status::DONE && $from === Status::APPROVED && $doc instanceof Movement && $doc->fields['is_two_phase']) {
            return __('A two-phase movement must be shipped before it can be marked done.', 'assetmove');
        }

        if ($to === Status::APPROVED && !$internal && self::doctypeRequiresValidation($doc)) {
            return __('This document type requires approval; submit it for validation instead.', 'assetmove');
        }

        if ($to === Status::TOVALIDATE) {
            $can_submit = ApprovalEngine::canSubmit($doc);
            if ($can_submit !== true) {
                return $can_submit;
            }
        }

        if ($internal) {
            return true;
        }

        $rightname   = $doc::$rightname;
        $is_writeoff = $doc instanceof Writeoff;

        $needed = match ($to) {
            Status::TOVALIDATE, Status::APPROVED => UPDATE,
            Status::CANCELLED => $is_writeoff ? Writeoff::RIGHT_CANCEL : Movement::RIGHT_CANCEL,
            Status::SHIPPED   => Movement::RIGHT_SHIP,
            Status::DONE      => $is_writeoff ? Writeoff::RIGHT_EXECUTE : Movement::RIGHT_RECEIVE,
            default           => null,
        };

        if ($needed !== null && !Session::haveRight($rightname, $needed)) {
            return self::rightError();
        }

        if ($to === Status::CANCELLED && $from === Status::SHIPPED) {
            $force_needed = $is_writeoff ? Writeoff::RIGHT_FORCE : Movement::RIGHT_FORCE;
            if (!$force || !Session::haveRight($rightname, $force_needed)) {
                return __('Cancelling a shipped movement needs the Force right and an explicit confirmation.', 'assetmove');
            }
            self::logForceUsage($doc, __('Forced cancellation of a shipped movement', 'assetmove'));
        }

        if ($to === Status::SHIPPED) {
            // The instanceof check above already guarantees $doc is a
            // Movement here (only Movements can ever target SHIPPED).
            $ship_error = self::canShip($doc, (int) Session::getLoginUserID());
            if ($ship_error !== true) {
                return $ship_error;
            }
        }

        if ($to === Status::DONE && $from === Status::SHIPPED && $doc instanceof Movement) {
            $receive_error = self::canReceive($doc, (int) Session::getLoginUserID(), $force);
            if ($receive_error !== true) {
                return $receive_error;
            }
        }

        return true;
    }

    /**
     * TZ 7.1: a user needs the general SHIP right *and*, for a source that
     * is a specific Warehouse with a manager and/or group assigned, to
     * actually be that manager or a member of that group. A warehouse with
     * neither (M2 customer decision) skips the second check.
     */
    public static function canShip(Movement $doc, int $users_id): true|string
    {
        if (!Session::haveRight(Movement::$rightname, Movement::RIGHT_SHIP)) {
            return self::rightError();
        }

        if (!self::isAttachedToWarehouse($doc->fields['source_itemtype'] ?? null, (int) ($doc->fields['source_items_id'] ?? 0), $users_id)) {
            return __('You are not the manager (or a member of the responsible group) of the source warehouse.', 'assetmove');
        }

        return true;
    }

    /**
     * TZ 7.1: same warehouse-attachment rule as canShip(), for the
     * destination, plus: the sender cannot also be the receiver of the
     * same movement, unless the document type allows self-reception or the
     * user has the Force right (and asked for it explicitly).
     */
    public static function canReceive(Movement $doc, int $users_id, bool $force = false): true|string
    {
        if (!Session::haveRight(Movement::$rightname, Movement::RIGHT_RECEIVE)) {
            return self::rightError();
        }

        if (!self::isAttachedToWarehouse($doc->fields['dest_itemtype'] ?? null, (int) ($doc->fields['dest_items_id'] ?? 0), $users_id)) {
            return __('You are not the manager (or a member of the responsible group) of the destination warehouse.', 'assetmove');
        }

        if (
            !$doc->fields['is_selfreception_allowed']
            && (int) $doc->fields['users_id_sender'] === $users_id
            && $users_id !== 0
        ) {
            if (!$force || !Session::haveRight(Movement::$rightname, Movement::RIGHT_FORCE)) {
                return __('The same person cannot both ship and receive this movement.', 'assetmove');
            }
            self::logForceUsage($doc, __('Forced self-reception (same person shipped and received)', 'assetmove'));
        }

        return true;
    }

    private static function isAttachedToWarehouse(?string $itemtype, int $items_id, int $users_id): bool
    {
        if ($itemtype !== Warehouse::class || $items_id <= 0) {
            // Not a warehouse-typed endpoint: nothing to be "attached" to.
            return true;
        }

        $warehouse = new Warehouse();
        if (!$warehouse->getFromDB($items_id)) {
            return true;
        }

        $manager_id = (int) $warehouse->fields['users_id_manager'];
        $groups_id  = (int) $warehouse->fields['groups_id'];

        if ($manager_id <= 0 && $groups_id <= 0) {
            // M2 customer decision: a warehouse with neither a manager nor
            // a group skips this check entirely.
            return true;
        }

        if ($manager_id === $users_id) {
            return true;
        }

        return $groups_id > 0 && countElementsInTable('glpi_groups_users', [
            'groups_id' => $groups_id,
            'users_id'  => $users_id,
        ]) > 0;
    }

    /**
     * TZ 7.1: every use of the Force right is logged separately with a
     * reason, on the document itself.
     */
    private static function logForceUsage(AbstractDocument $doc, string $reason): void
    {
        Log::history($doc->getID(), $doc->getType(), [0, '', $reason], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }

    private static function rightError(): string
    {
        return __('You do not have the right to perform this status change.', 'assetmove');
    }

    private static function doctypeRequiresValidation(AbstractDocument $doc): bool
    {
        $doctype = new DocType();
        if (!$doctype->getFromDB($doc->fields['plugin_assetmove_doctypes_id'])) {
            return false;
        }

        return (bool) $doctype->fields['is_validation_required'];
    }
}
