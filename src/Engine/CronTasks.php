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

use CronTask;
use GlpiPlugin\Assetmove\AbstractDocument;
use GlpiPlugin\Assetmove\Config;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Status;
use GlpiPlugin\Assetmove\Validation;
use GlpiPlugin\Assetmove\Writeoff;
use NotificationEvent;

/**
 * Hosts the four daily reminder tasks from TZ 13 (registered as this
 * class's own "itemtype", the way `CronTask::register()` allows for any
 * plugin class -- it does not have to be a CommonDBTM). Every method name
 * matches `cron<TaskName>` exactly (`CronTask::launch()` calls
 * `"{$itemtype}::cron{$name}"` verbatim), and `cronInfo()` supplies the
 * description shown in Setup > Automatic actions.
 */
final class CronTasks
{
    private function __construct()
    {
        // Static utility class, never instantiated.
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Asset movements', 'assetmove');
    }

    public static function cronInfo($name): array
    {
        return match ($name) {
            'overdueShipment'   => ['description' => __('Remind about movements stuck in transit too long', 'assetmove')],
            'overduePlanned'    => ['description' => __('Remind about movements past their planned date', 'assetmove')],
            'overdueReturn'     => ['description' => __('Remind about items overdue for return', 'assetmove')],
            'pendingValidation' => ['description' => __('Remind approvers about pending approvals', 'assetmove')],
            default             => [],
        };
    }

    /**
     * SHIPPED longer than Config::overdue_reminder_days: 'overdue' event to
     * the destination warehouse manager (already an 'overdue'-subscribed
     * target on NotificationTargetMovement); past twice that many days,
     * also escalate straight to that manager's own manager
     * (users_id_supervisor), since there is no separate "manager of a
     * warehouse manager" concept to target automatically otherwise.
     */
    public static function cronoverdueShipment(CronTask $task): int
    {
        $days = max(1, (int) Config::getConfig()->fields['overdue_reminder_days']);
        $count = 0;

        foreach ((new Movement())->find([
            'status'       => Status::SHIPPED,
            'date_shipped' => ['<', self::daysAgo($days)],
        ]) as $row) {
            $movement = new Movement();
            $movement->getFromDB($row['id']);
            NotificationEvent::raiseEvent('overdue', $movement);

            if ((string) $movement->fields['date_shipped'] < self::daysAgo($days * 2)) {
                self::escalateToManager($movement);
            }

            $count++;
        }

        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }

    /**
     * date_planned passed, status not final: remind the responsible.
     * Covers both Movement and Writeoff (shared table).
     */
    public static function cronoverduePlanned(CronTask $task): int
    {
        $count = 0;

        foreach ((new Movement())->find([
            'date_planned' => ['<', $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')],
            'NOT'          => ['date_planned' => null],
            'status'       => ['NOT IN', [Status::DONE, Status::CANCELLED, Status::REFUSED]],
        ]) as $row) {
            NotificationEvent::raiseEvent('overdue', self::loadDocument((int) $row['id'], (int) $row['kind']));
            $count++;
        }

        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }

    /**
     * A Done movement with an expected return date in the past and no
     * reverse movement created yet (TZ 6: undoing a done movement is a new
     * reverse document, tracked via movements_id_reverse) is overdue for
     * its return.
     */
    public static function cronoverdueReturn(CronTask $task): int
    {
        $count = 0;

        foreach ((new Movement())->find([
            'status'                => Status::DONE,
            'movements_id_reverse'  => 0,
            'NOT'                   => ['date_return_expected' => null],
            'date_return_expected'  => ['<', $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')],
        ]) as $row) {
            NotificationEvent::raiseEvent('overdue', self::loadDocument((int) $row['id'], (int) $row['kind']));
            $count++;
        }

        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }

    /**
     * A Validation row still WAITING after Config::overdue_reminder_days
     * (no dedicated "validation reminder delay" setting in Config; reusing
     * this one is a deliberate simplification, see PROGRESS.md) gets a
     * fresh 'validation_request' reminder to its approver.
     */
    public static function cronpendingValidation(CronTask $task): int
    {
        $days  = max(1, (int) Config::getConfig()->fields['overdue_reminder_days']);
        $count = 0;

        foreach ((new Validation())->find([
            'status'          => Validation::STATUS_WAITING,
            'submission_date' => ['<', self::daysAgo($days)],
        ]) as $row) {
            $movement = new Movement();
            if (!$movement->getFromDB($row['plugin_assetmove_movements_id'])) {
                continue;
            }
            NotificationEvent::raiseEvent(
                'validation_request',
                self::loadDocument((int) $movement->getID(), (int) $movement->fields['kind'])
            );
            $count++;
        }

        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }

    private static function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    private static function loadDocument(int $id, int $kind): AbstractDocument
    {
        $document = $kind === Writeoff::KIND ? new Writeoff() : new Movement();
        $document->getFromDB($id);

        return $document;
    }

    private static function escalateToManager(Movement $movement): void
    {
        if ($movement->fields['dest_itemtype'] !== \GlpiPlugin\Assetmove\Warehouse::class) {
            return;
        }

        $warehouse = new \GlpiPlugin\Assetmove\Warehouse();
        if (!$warehouse->getFromDB($movement->fields['dest_items_id']) || (int) $warehouse->fields['users_id_manager'] <= 0) {
            return;
        }

        $manager = new \User();
        if (!$manager->getFromDB($warehouse->fields['users_id_manager']) || (int) $manager->fields['users_id_supervisor'] <= 0) {
            return;
        }

        NotificationEvent::raiseEvent('overdue', $movement, ['_escalate_to' => (int) $manager->fields['users_id_supervisor']]);
    }
}
