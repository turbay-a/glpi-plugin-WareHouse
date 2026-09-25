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
use GlpiPlugin\Assetmove\ApprovalStep;
use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\DocType;
use GlpiPlugin\Assetmove\Status;
use GlpiPlugin\Assetmove\Validation;
use GlpiPlugin\Assetmove\Warehouse;
use GlpiPlugin\Assetmove\Writeoff;
use Infocom;
use NotificationEvent;
use Session;
use User;

/**
 * Expands a DocType's approval route into concrete Validation rows for one
 * document (TZ 8.1) and drives what happens as each row gets answered
 * (TZ 8.2). Everything here reads the DocType/ApprovalStep *once*, at
 * submission time, and stores the result on Validation rows -- once
 * submitted, later edits to the DocType's route never affect a document
 * already in flight (TZ 5, end of section: "зміна довідника на документ не
 * впливає").
 */
final class ApprovalEngine
{
    private function __construct()
    {
        // Static utility class, never instantiated.
    }

    /**
     * @return true|string True if $doc can be submitted for approval right
     *                      now, otherwise a human-readable reason it can't.
     */
    public static function canSubmit(AbstractDocument $doc): true|string
    {
        if (self::resolveRoute($doc) === []) {
            return __('The approval route is empty once resolved to actual approvers; check the document type\'s approval steps.', 'assetmove');
        }

        return true;
    }

    /**
     * Create the Validation rows for $doc's route and activate the first
     * step. Assumes canSubmit() was already checked (this is called from
     * AbstractDocument::post_updateItem(), after prepareInputForUpdate()
     * already validated the transition via canSubmit()).
     */
    public static function submit(AbstractDocument $doc): void
    {
        $steps = self::resolveRoute($doc);
        if ($steps === []) {
            return;
        }

        $min_step_order = min(array_column($steps, 'step_order'));

        foreach ($steps as $step) {
            (new Validation())->add([
                'plugin_assetmove_movements_id' => $doc->getID(),
                'step_order'                    => $step['step_order'],
                'mode'                           => $step['mode'],
                'is_mandatory'                   => $step['is_mandatory'],
                'users_id'                       => Session::getLoginUserID(),
                'users_id_validate'              => $step['users_id_validate'],
                'groups_id_validate'             => $step['groups_id_validate'],
                'status'                         => $step['step_order'] === $min_step_order
                    ? Validation::STATUS_WAITING
                    : Validation::STATUS_NOT_STARTED,
                'submission_date'                => $_SESSION['glpi_currenttime'],
            ]);
        }

        NotificationEvent::raiseEvent('validation_request', $doc);
    }

    /**
     * Record $status (ACCEPTED/REFUSED) for $validation and react: refuse
     * the whole document, advance to the next step, or approve it once
     * every step is done (TZ 8.2).
     */
    public static function answer(Validation $validation, int $status, string $comment): bool
    {
        if (!$validation->canAnswer(Session::getLoginUserID())) {
            return false;
        }

        $validation->update([
            'id'                  => $validation->getID(),
            'status'              => $status,
            'comment_validation'  => $comment,
            'users_id_answer'     => Session::getLoginUserID(),
            'validation_date'     => $_SESSION['glpi_currenttime'],
        ]);

        self::onValidationAnswered($validation);

        return true;
    }

    private static function onValidationAnswered(Validation $validation): void
    {
        $doc = self::loadDocument($validation);
        if ($doc === null) {
            return;
        }

        if ((int) $validation->fields['status'] === Validation::STATUS_REFUSED) {
            $doc->transitionInternally(Status::REFUSED);
            NotificationEvent::raiseEvent('refused', $doc);
            return;
        }

        $step_order = (int) $validation->fields['step_order'];
        if (!self::isStepComplete($doc, $step_order)) {
            return;
        }

        $next_step_order = self::getNextStepOrder($doc, $step_order);
        if ($next_step_order !== null) {
            /** @var \DBmysql $DB */
            global $DB;
            $DB->update(Validation::getTable(), ['status' => Validation::STATUS_WAITING], [
                'plugin_assetmove_movements_id' => $doc->getID(),
                'step_order'                     => $next_step_order,
                'status'                         => Validation::STATUS_NOT_STARTED,
            ]);
            NotificationEvent::raiseEvent('validation_request', $doc);
            return;
        }

        $doc->transitionInternally(Status::APPROVED);
        NotificationEvent::raiseEvent('approved', $doc);

        $doctype = new DocType();
        if (
            $doctype->getFromDB($doc->fields['plugin_assetmove_doctypes_id'])
            && $doctype->fields['is_autoexecute_on_approval']
        ) {
            $doc->getFromDB($doc->getID());
            if ($doc instanceof Writeoff) {
                $doc->transitionInternally(Status::DONE);
            } elseif ($doc->fields['is_two_phase']) {
                $doc->transitionInternally(Status::SHIPPED);
            } else {
                $doc->transitionInternally(Status::DONE);
            }
        }
    }

    /**
     * mode=ALL: every mandatory row of the step must be ACCEPTED.
     * mode=ANY: one ACCEPTED row is enough; the other still-WAITING rows of
     * the step are closed as NOT_STARTED (their answer no longer matters).
     * If a step mixes rows with different modes (an unusual admin setup),
     * a single ANY row is enough for the whole step -- see PROGRESS.md.
     */
    private static function isStepComplete(AbstractDocument $doc, int $step_order): bool
    {
        $rows = (new Validation())->find([
            'plugin_assetmove_movements_id' => $doc->getID(),
            'step_order'                     => $step_order,
        ]);

        $has_any_mode = false;
        foreach ($rows as $row) {
            if ((int) $row['mode'] === Validation::MODE_ANY) {
                $has_any_mode = true;
                break;
            }
        }

        if ($has_any_mode) {
            foreach ($rows as $row) {
                if ((int) $row['status'] === Validation::STATUS_ACCEPTED) {
                    /** @var \DBmysql $DB */
                    global $DB;
                    $DB->update(Validation::getTable(), ['status' => Validation::STATUS_NOT_STARTED], [
                        'plugin_assetmove_movements_id' => $doc->getID(),
                        'step_order'                     => $step_order,
                        'status'                         => Validation::STATUS_WAITING,
                    ]);

                    return true;
                }
            }

            return false;
        }

        foreach ($rows as $row) {
            if (!$row['is_mandatory']) {
                continue;
            }
            if ((int) $row['status'] !== Validation::STATUS_ACCEPTED) {
                return false;
            }
        }

        return true;
    }

    private static function getNextStepOrder(AbstractDocument $doc, int $current_step_order): ?int
    {
        $rows = (new Validation())->find([
            'plugin_assetmove_movements_id' => $doc->getID(),
            ['step_order' => ['>', $current_step_order]],
        ], ['step_order ASC'], 1);

        $row = reset($rows);

        return $row ? (int) $row['step_order'] : null;
    }

    private static function loadDocument(Validation $validation): ?AbstractDocument
    {
        $doc = new \GlpiPlugin\Assetmove\Movement();
        if (!$doc->getFromDB($validation->fields['plugin_assetmove_movements_id'])) {
            return null;
        }

        if ((int) $doc->fields['kind'] === Writeoff::KIND) {
            $doc = new Writeoff();
            $doc->getFromDB($validation->fields['plugin_assetmove_movements_id']);
        }

        return $doc;
    }

    /**
     * @return array<int, array{step_order:int, mode:int, is_mandatory:int, users_id_validate:int, groups_id_validate:int}>
     */
    private static function resolveRoute(AbstractDocument $doc): array
    {
        $doctype = new DocType();
        if (!$doctype->getFromDB($doc->fields['plugin_assetmove_doctypes_id'])) {
            return [];
        }

        $items_value = self::computeItemsValue($doc);

        $steps = (new ApprovalStep())->find(
            ['plugin_assetmove_doctypes_id' => $doctype->getID()],
            ['step_order ASC', 'id ASC']
        );

        $resolved = [];
        $seen     = [];

        foreach ($steps as $step) {
            if ($step['condition_min_price'] !== null && (float) $step['condition_min_price'] > $items_value) {
                continue;
            }

            foreach (self::resolveApprovers($step, $doc) as $users_id => $groups_id) {
                $key = $step['step_order'] . ':' . $users_id;
                if (isset($seen[$key])) {
                    // Duplicate within the step (TZ 8.1.2): keep the first.
                    continue;
                }
                $seen[$key] = true;

                $resolved[] = [
                    'step_order'          => (int) $step['step_order'],
                    'mode'                => (int) $step['mode'],
                    'is_mandatory'        => (int) $step['is_mandatory'],
                    'users_id_validate'   => (int) $users_id,
                    'groups_id_validate'  => (int) $groups_id,
                ];
            }
        }

        return $resolved;
    }

    /**
     * @return array<int, int> users_id => groups_id (0 if not resolved via a group)
     */
    private static function resolveApprovers(array $step, AbstractDocument $doc): array
    {
        return match ($step['approver_type']) {
            ApprovalStep::APPROVER_USER => (int) $step['approver_id'] > 0
                ? [(int) $step['approver_id'] => 0]
                : [],
            ApprovalStep::APPROVER_GROUP => self::groupMembers((int) $step['approver_id']),
            ApprovalStep::APPROVER_PROFILE => self::profileUsers((int) $step['approver_id'], (int) $doc->fields['entities_id']),
            ApprovalStep::APPROVER_AUTHOR_MANAGER => self::authorManager($doc),
            ApprovalStep::APPROVER_WAREHOUSE_MANAGER_SRC => self::warehouseManager(
                $doc->fields['source_itemtype'] ?? null,
                (int) ($doc->fields['source_items_id'] ?? 0)
            ),
            ApprovalStep::APPROVER_WAREHOUSE_MANAGER_DST => self::warehouseManager(
                $doc->fields['dest_itemtype'] ?? null,
                (int) ($doc->fields['dest_items_id'] ?? 0)
            ),
            ApprovalStep::APPROVER_ITEM_OWNER => self::itemOwners($doc),
            default => [],
        };
    }

    /**
     * @return array<int, int>
     */
    private static function groupMembers(int $groups_id): array
    {
        if ($groups_id <= 0) {
            return [];
        }

        $members = [];
        foreach ((new \Group_User())->find(['groups_id' => $groups_id]) as $row) {
            $members[(int) $row['users_id']] = $groups_id;
        }

        return $members;
    }

    /**
     * Users holding $profiles_id in $entities_id or globally (recursive
     * from root): a simplification of GLPI's full entity-inheritance tree,
     * documented in PROGRESS.md.
     *
     * @return array<int, int>
     */
    private static function profileUsers(int $profiles_id, int $entities_id): array
    {
        if ($profiles_id <= 0) {
            return [];
        }

        $users = [];
        $rows  = (new \Profile_User())->find([
            'profiles_id' => $profiles_id,
            'OR'          => [
                'entities_id' => $entities_id,
                ['entities_id' => 0, 'is_recursive' => 1],
            ],
        ]);
        foreach ($rows as $row) {
            $users[(int) $row['users_id']] = 0;
        }

        return $users;
    }

    /**
     * @return array<int, int>
     */
    private static function authorManager(AbstractDocument $doc): array
    {
        $author = new User();
        if (
            !$author->getFromDB($doc->fields['users_id_author'])
            || (int) $author->fields['users_id_supervisor'] <= 0
        ) {
            return [];
        }

        return [(int) $author->fields['users_id_supervisor'] => 0];
    }

    /**
     * @return array<int, int>
     */
    private static function warehouseManager(?string $itemtype, int $items_id): array
    {
        if ($itemtype !== Warehouse::class || $items_id <= 0) {
            return [];
        }

        $warehouse = new Warehouse();
        if (!$warehouse->getFromDB($items_id) || (int) $warehouse->fields['users_id_manager'] <= 0) {
            return [];
        }

        return [(int) $warehouse->fields['users_id_manager'] => 0];
    }

    /**
     * Distinct owners (`users_id`) of the items already on the document.
     *
     * @return array<int, int>
     */
    private static function itemOwners(AbstractDocument $doc): array
    {
        $owners = [];
        foreach ((new Document_Item())->find(['plugin_assetmove_movements_id' => $doc->getID()]) as $row) {
            if (!class_exists($row['itemtype'])) {
                continue;
            }
            $item = new $row['itemtype']();
            if ($item->getFromDB($row['items_id']) && $item->isField('users_id') && (int) $item->fields['users_id'] > 0) {
                $owners[(int) $item->fields['users_id']] = 0;
            }
        }

        return $owners;
    }

    /**
     * Sum of Infocom.value for the document's items; items without an
     * Infocom count as 0 (TZ 8.1.1).
     */
    private static function computeItemsValue(AbstractDocument $doc): float
    {
        $sum = 0.0;
        foreach ((new Document_Item())->find(['plugin_assetmove_movements_id' => $doc->getID()]) as $row) {
            $infocom = new Infocom();
            if ($infocom->getFromDBforDevice($row['itemtype'], $row['items_id'])) {
                $sum += (float) $infocom->fields['value'];
            }
        }

        return $sum;
    }
}
