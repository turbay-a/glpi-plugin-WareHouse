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

namespace GlpiPlugin\Assetmove;

use CommonDBTM;
use DbUtils;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;
use User;

/**
 * NotificationTarget itself is the CommonDBChild for `glpi_notificationtargets`
 * (see its `$table` property) -- picking which of addAdditionalTargets()'s
 * offered targets are actually wired to a given Notification is normally a
 * manual step done from that Notification's own "Targets" tab in the admin
 * UI; installForItemtype() below seeds a sensible default set per event so
 * the plugin is useful out of the box, editable later like any other
 * Notification.
 */

/**
 * Notification target for Movement (and, via NotificationTargetWriteoff,
 * Writeoff -- GLPI resolves the target class from the itemtype's short
 * name, so a Writeoff notification needs a class of that exact name; see
 * that file). Covers every event listed in TZ 13.
 */
class NotificationTargetMovement extends NotificationTarget
{
    public const TARGET_AUTHOR                    = 33001;
    public const TARGET_RESPONSIBLE                = 33002;
    public const TARGET_RESPONSIBLE_GROUP          = 33003;
    public const TARGET_SOURCE_WAREHOUSE_MANAGER   = 33004;
    public const TARGET_DEST_WAREHOUSE_MANAGER     = 33005;
    public const TARGET_CURRENT_APPROVERS          = 33006;
    public const TARGET_ESCALATION                 = 33007;

    /**
     * Default target set wired to each event at install time (the admin can
     * add/remove from this later via the Notification's own "Targets" tab,
     * a stock GLPI mechanism -- this is only the seeded starting point).
     */
    private const DEFAULT_EVENT_TARGETS = [
        'new'                => [self::TARGET_AUTHOR, self::TARGET_RESPONSIBLE, self::TARGET_RESPONSIBLE_GROUP],
        'validation_request' => [self::TARGET_CURRENT_APPROVERS],
        'approved'           => [self::TARGET_AUTHOR, self::TARGET_RESPONSIBLE],
        'refused'            => [self::TARGET_AUTHOR, self::TARGET_RESPONSIBLE],
        'shipped'            => [self::TARGET_AUTHOR, self::TARGET_DEST_WAREHOUSE_MANAGER],
        'received'           => [self::TARGET_AUTHOR, self::TARGET_RESPONSIBLE],
        'discrepancy'        => [self::TARGET_AUTHOR, self::TARGET_RESPONSIBLE, self::TARGET_SOURCE_WAREHOUSE_MANAGER, self::TARGET_DEST_WAREHOUSE_MANAGER],
        'done'               => [self::TARGET_AUTHOR, self::TARGET_RESPONSIBLE],
        'cancelled'          => [self::TARGET_AUTHOR, self::TARGET_RESPONSIBLE],
        'overdue'            => [self::TARGET_RESPONSIBLE, self::TARGET_SOURCE_WAREHOUSE_MANAGER, self::TARGET_DEST_WAREHOUSE_MANAGER, self::TARGET_ESCALATION],
    ];

    public function getEvents()
    {
        return [
            'new'                => __s('New document', 'assetmove'),
            'validation_request' => __s('Approval requested', 'assetmove'),
            'approved'           => __s('Approved', 'assetmove'),
            'refused'            => __s('Refused', 'assetmove'),
            'shipped'            => __s('Shipped', 'assetmove'),
            'received'           => __s('Received', 'assetmove'),
            'discrepancy'        => __s('Discrepancy', 'assetmove'),
            'done'               => __s('Done', 'assetmove'),
            'cancelled'          => __s('Cancelled', 'assetmove'),
            'overdue'            => __s('Overdue', 'assetmove'),
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_AUTHOR, __s('Author'));
        $this->addTarget(self::TARGET_RESPONSIBLE, __s('Responsible', 'assetmove'));
        $this->addTarget(self::TARGET_RESPONSIBLE_GROUP, __s('Responsible group', 'assetmove'));
        $this->addTarget(self::TARGET_SOURCE_WAREHOUSE_MANAGER, __s('Source warehouse manager', 'assetmove'));
        $this->addTarget(self::TARGET_DEST_WAREHOUSE_MANAGER, __s('Destination warehouse manager', 'assetmove'));
        $this->addTarget(self::TARGET_CURRENT_APPROVERS, __s('Current approvers', 'assetmove'));
        $this->addTarget(self::TARGET_ESCALATION, __s('Escalation (overdue shipment cron, twice the reminder delay)', 'assetmove'));
    }

    public function addSpecificTargets($data, $options)
    {
        $doc = $this->obj;
        if (!($doc instanceof AbstractDocument)) {
            return;
        }

        switch ($data['items_id']) {
            case self::TARGET_AUTHOR:
                $this->addUserByField('users_id_author');
                break;
            case self::TARGET_RESPONSIBLE:
                $this->addUserByField('users_id_responsible');
                break;
            case self::TARGET_RESPONSIBLE_GROUP:
                $groups_id = (int) $doc->getField('groups_id_responsible');
                if ($groups_id > 0) {
                    $this->addForGroup(0, $groups_id);
                }
                break;
            case self::TARGET_SOURCE_WAREHOUSE_MANAGER:
                $this->addWarehouseManager($doc, 'source_itemtype', 'source_items_id');
                break;
            case self::TARGET_DEST_WAREHOUSE_MANAGER:
                $this->addWarehouseManager($doc, 'dest_itemtype', 'dest_items_id');
                break;
            case self::TARGET_CURRENT_APPROVERS:
                $this->addCurrentApprovers($doc);
                break;
            case self::TARGET_ESCALATION:
                if (!empty($options['_escalate_to'])) {
                    $this->addUserById((int) $options['_escalate_to']);
                }
                break;
        }
    }

    private function addWarehouseManager(AbstractDocument $doc, string $itemtype_field, string $items_id_field): void
    {
        if ($doc->getField($itemtype_field) !== Warehouse::class) {
            return;
        }

        $items_id = (int) $doc->getField($items_id_field);
        if ($items_id <= 0) {
            return;
        }

        $warehouse = new Warehouse();
        if (!$warehouse->getFromDB($items_id) || (int) $warehouse->fields['users_id_manager'] <= 0) {
            return;
        }

        $this->addUserById((int) $warehouse->fields['users_id_manager']);
    }

    private function addCurrentApprovers(AbstractDocument $doc): void
    {
        $rows = (new Validation())->find([
            'plugin_assetmove_movements_id' => $doc->getID(),
            'status'                          => Validation::STATUS_WAITING,
        ]);

        $seen = [];
        foreach ($rows as $row) {
            $users_id = (int) $row['users_id_validate'];
            if ($users_id > 0 && !isset($seen[$users_id])) {
                $seen[$users_id] = true;
                $this->addUserById($users_id);
            }
        }
    }

    private function addUserById(int $users_id): void
    {
        $user = new User();
        if ($user->getFromDB($users_id)) {
            $this->addToRecipientsList([
                'language' => $user->getField('language'),
                'users_id' => $user->getField('id'),
            ]);
        }
    }

    public function getTags()
    {
        $tags = [
            'assetmove.action'  => __('Action', 'assetmove'),
            'assetmove.name'    => __('Name'),
            'assetmove.status'  => __('Status'),
            'assetmove.comment' => __('Comment'),
            'assetmove.entity'  => \Entity::getTypeName(1),
            'assetmove.url'     => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        asort($this->tag_descriptions);

        return parent::getTags();
    }

    public function addDataForTemplate($event, $options = [])
    {
        if (!($this->obj instanceof CommonDBTM)) {
            return;
        }

        $events = $this->getAllEvents();

        $this->data['##assetmove.action##']  = $events[$event] ?? $event;
        $this->data['##assetmove.name##']    = $this->obj->getField('name');
        $this->data['##assetmove.status##']  = Status::getLabels()[(int) $this->obj->getField('status')] ?? '';
        $this->data['##assetmove.comment##'] = $this->obj->getField('comment');
        $this->data['##assetmove.entity##']  = (new DbUtils())->getTreeValueCompleteName('glpi_entities', $this->obj->getField('entities_id'));
        $this->data['##assetmove.url##']     = $this->formatURL($options['additionnaloption']['usertype'] ?? '', $this->obj->getType() . '_' . $this->obj->getID());

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    /**
     * Create (idempotently) one shared template plus one Notification per
     * event, for both Movement and Writeoff. Called from hook.php's
     * install(). The template is deliberately generic (one per itemtype,
     * not one per event, same choice as most small notification-target
     * plugins e.g. typology) since every event shares the same handful of
     * useful tags.
     */
    public static function install(): void
    {
        foreach ([Movement::class, Writeoff::class] as $itemtype) {
            self::installForItemtype($itemtype);
        }
    }

    private static function installForItemtype(string $itemtype): void
    {
        $dbu = new DbUtils();

        $template = new NotificationTemplate();
        $templates_id = $template->getFromDBByCrit(['itemtype' => $itemtype, 'name' => 'Assetmove'])
            ? $template->getID()
            : $template->add([
                'name'     => 'Assetmove',
                'itemtype' => $itemtype,
                'date_mod' => $_SESSION['glpi_currenttime'],
            ]);

        if (!$templates_id) {
            return;
        }

        $translation = new NotificationTemplateTranslation();
        if (!$dbu->countElementsInTable($translation->getTable(), ['notificationtemplates_id' => $templates_id])) {
            $translation->add([
                'notificationtemplates_id' => $templates_id,
                'language'                 => '',
                'subject'                  => '##assetmove.action## : ##assetmove.name##',
                'content_text'             => "##assetmove.action##\n"
                    . "##lang.assetmove.name## : ##assetmove.name##\n"
                    . "##lang.assetmove.status## : ##assetmove.status##\n"
                    . "##assetmove.url##",
                'content_html'             => '<p>##assetmove.action##</p>'
                    . '<p>##lang.assetmove.name## : ##assetmove.name##<br>'
                    . '##lang.assetmove.status## : ##assetmove.status##</p>'
                    . '<p><a href="##assetmove.url##">##assetmove.url##</a></p>',
            ]);
        }

        $notification         = new Notification();
        $notification_template = new Notification_NotificationTemplate();
        $target_class          = new self();

        foreach (array_keys($target_class->getEvents()) as $event) {
            if ($dbu->countElementsInTable('glpi_notifications', ['itemtype' => $itemtype, 'event' => $event])) {
                continue;
            }

            $notifications_id = $notification->add([
                'name'         => 'Assetmove: ' . $event,
                'entities_id'  => 0,
                'itemtype'     => $itemtype,
                'event'        => $event,
                'is_recursive' => 1,
                'is_active'    => 1,
                'date_mod'     => $_SESSION['glpi_currenttime'],
            ]);

            if ($notifications_id) {
                $notification_template->add([
                    'notificationtemplates_id' => $templates_id,
                    'mode'                     => 'mailing',
                    'notifications_id'         => $notifications_id,
                ]);

                foreach (self::DEFAULT_EVENT_TARGETS[$event] ?? [] as $target_const) {
                    (new NotificationTarget())->add([
                        'notifications_id' => $notifications_id,
                        'type'             => Notification::USER_TYPE,
                        'items_id'         => $target_const,
                    ]);
                }
            }
        }
    }
}
