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

use GlpiPlugin\Assetmove\Config;
use GlpiPlugin\Assetmove\DocType;
use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\Engine\AssetTypes;
use GlpiPlugin\Assetmove\Engine\CronTasks;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\NotificationTargetMovement;
use GlpiPlugin\Assetmove\Warehouse;
use GlpiPlugin\Assetmove\Writeoff;

/**
 * Add "Add to a new movement/write-off" massive actions to every movable
 * asset list (Computer, Monitor, ...). Registered via the plugin's
 * `use_massive_action` hook; GLPI calls this function (by naming
 * convention: `plugin_<key>_MassiveActions`) for every itemtype whose list
 * is displayed, and expects an `'Class' . MassiveAction::CLASS_ACTION_SEPARATOR . 'action'
 * => label` map back -- see Document_Item::showMassiveActionsSubForm() /
 * ::processMassiveActionsForOneItemtype() for the other half.
 *
 * @param class-string $itemtype
 *
 * @return array<string, string>
 */
function plugin_assetmove_MassiveActions($itemtype)
{
    if (!AssetTypes::isMovable($itemtype)) {
        return [];
    }

    $actions = [];
    $prefix  = Document_Item::class . MassiveAction::CLASS_ACTION_SEPARATOR;

    if (Movement::canCreate()) {
        $actions[$prefix . 'add_to_movement'] = __('Add to a new movement', 'assetmove');
    }
    if (Writeoff::canCreate()) {
        $actions[$prefix . 'add_to_writeoff'] = __('Add to a new write-off', 'assetmove');
    }

    return $actions;
}

/**
 * Keep the Movement and Writeoff lists separate at the SQL level, even
 * though both itemtypes read the same table: registered via
 * `Glpi\Plugin\Hooks::AUTO_ADD_DEFAULT_WHERE` (see
 * `Glpi\Search\Provider\SQLProvider::getDefaultWhereCriteria()`).
 *
 * @param class-string $itemtype
 *
 * @return array
 */
function plugin_assetmove_addDefaultWhere($itemtype)
{
    return match ($itemtype) {
        Movement::class => ['kind' => Movement::KIND],
        Writeoff::class => ['kind' => Writeoff::KIND],
        default          => [],
    };
}

/**
 * Plugin install process.
 *
 * @return boolean
 */
function plugin_assetmove_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_ASSETMOVE_VERSION);

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    plugin_assetmove_create_tables($DB, $default_charset, $default_collation, $default_key_sign);
    plugin_assetmove_seed_config();

    // Schema additions after the plugin already has live data on some
    // stands (a plain CREATE TABLE change in plugin_assetmove_create_tables()
    // above only helps a *fresh* install -- uninstall/reinstall is no
    // longer an option once real Movement/Warehouse rows exist): addField()
    // itself checks fieldExists() and is a no-op on a table that already
    // has the column, so this runs safely on every install() call.
    $migration->addField(
        'glpi_plugin_assetmove_warehouses',
        'print_requisites',
        'text',
        ['after' => 'comment']
    );

    // Found while building the M-11 print feature: the "Comments" textarea
    // on Movement/Writeoff's own form (movement_form.html.twig /
    // writeoff_form.html.twig, `name="comment"`, and
    // AbstractDocument::rawSearchOptions()'s own search option) has been
    // reading/writing a `comment` field that never existed on this table --
    // the column the CREATE TABLE below actually had was named `content`
    // instead, an unrelated column never referenced by any PHP code in this
    // plugin. Every value ever typed into that textarea was silently
    // discarded on save (CommonDBTM::update() ignores unknown input keys,
    // no error). changeField() renames the column in place, preserving any
    // (until-now write-only) `content` data that may already be in it.
    $migration->changeField(
        'glpi_plugin_assetmove_movements',
        'content',
        'comment',
        'text'
    );

    // M-11 print form (Engine\PrintM11): editable overrides for the header/
    // recipient (auto-derived by default, editable per document before
    // printing) and a per-row price (used to compute "Сума" = quantity *
    // price -- the form has no way to represent a value it never stores).
    $migration->addField('glpi_plugin_assetmove_movements', 'print_header', 'text', ['after' => 'comment']);
    $migration->addField('glpi_plugin_assetmove_movements', 'print_sender', 'text', ['after' => 'print_header']);
    $migration->addField('glpi_plugin_assetmove_movements', 'print_recipient', 'text', ['after' => 'print_sender']);
    $migration->addField('glpi_plugin_assetmove_movements', 'print_via', 'text', ['after' => 'print_recipient']);
    // 'decimal' is not one of Migration::fieldFormat()'s known keywords --
    // its default branch uses the $type argument verbatim as the raw SQL
    // column format, which is exactly what's needed here to match the
    // DECIMAL(20,4) used in plugin_assetmove_create_tables() for a fresh
    // install.
    $migration->addField(
        'glpi_plugin_assetmove_movements_items',
        'price',
        'DECIMAL(20,4) NOT NULL DEFAULT 0',
        ['after' => 'comment']
    );

    $movement_rights = ALLSTANDARDRIGHT
        | Movement::RIGHT_APPROVE
        | Movement::RIGHT_SHIP
        | Movement::RIGHT_RECEIVE
        | Movement::RIGHT_CANCEL
        | Movement::RIGHT_FORCE;
    $writeoff_rights = ALLSTANDARDRIGHT
        | Writeoff::RIGHT_APPROVE
        | Writeoff::RIGHT_EXECUTE
        | Writeoff::RIGHT_CANCEL
        | Writeoff::RIGHT_FORCE;

    $migration->addRight(Movement::$rightname, $movement_rights);
    $migration->addRight(Writeoff::$rightname, $writeoff_rights);
    $migration->addRight(DocType::$rightname, ALLSTANDARDRIGHT);
    $migration->addRight(Warehouse::$rightname, ALLSTANDARDRIGHT);
    $migration->addRight(Config::$rightname, ALLSTANDARDRIGHT);

    $migration->executeMigration();

    NotificationTargetMovement::install();
    plugin_assetmove_register_crontasks();

    return true;
}

/**
 * Register the four daily reminder tasks (TZ 13), idempotently --
 * CronTask::register() itself refuses to create a duplicate by
 * (itemtype, name).
 *
 * @return void
 */
function plugin_assetmove_register_crontasks()
{
    $itemtype = CronTasks::class;

    foreach (['overdueShipment', 'overduePlanned', 'overdueReturn', 'pendingValidation'] as $name) {
        CronTask::register($itemtype, $name, DAY_TIMESTAMP, [
            'comment' => CronTasks::cronInfo($name)['description'] ?? '',
            'mode'    => CronTask::MODE_INTERNAL,
        ]);
    }
}

/**
 * Create every table owned by the plugin, if it does not exist yet.
 *
 * @param DBmysql $DB
 * @param string  $default_charset
 * @param string  $default_collation
 * @param string  $default_key_sign
 *
 * @return void
 */
function plugin_assetmove_create_tables($DB, $default_charset, $default_collation, $default_key_sign)
{
    if (!$DB->tableExists('glpi_plugin_assetmove_movements')) {
        $query = "CREATE TABLE `glpi_plugin_assetmove_movements` (
            `id`                    INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `entities_id`           INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive`          TINYINT NOT NULL DEFAULT 0,
            `kind`                  TINYINT NOT NULL DEFAULT 1,
            `name`                  VARCHAR(255) DEFAULT NULL,
            `plugin_assetmove_doctypes_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `status`                INT NOT NULL DEFAULT 1,

            `source_itemtype`       VARCHAR(255) DEFAULT NULL,
            `source_items_id`       INT {$default_key_sign} NOT NULL DEFAULT 0,
            `dest_itemtype`         VARCHAR(255) DEFAULT NULL,
            `dest_items_id`         INT {$default_key_sign} NOT NULL DEFAULT 0,

            `is_two_phase`          TINYINT NOT NULL DEFAULT 0,
            `states_id_target`      INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_apply_location`     TINYINT NOT NULL DEFAULT 1,
            `is_apply_user`         TINYINT NOT NULL DEFAULT 0,
            `is_clear_location`     TINYINT NOT NULL DEFAULT 0,
            `is_clear_user`         TINYINT NOT NULL DEFAULT 0,
            `is_selfreception_allowed` TINYINT NOT NULL DEFAULT 0,
            `locations_id_transit`  INT {$default_key_sign} NOT NULL DEFAULT 0,

            `users_id_author`       INT {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_responsible`  INT {$default_key_sign} NOT NULL DEFAULT 0,
            `groups_id_responsible` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_sender`       INT {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_receiver`     INT {$default_key_sign} NOT NULL DEFAULT 0,

            `date_planned`          TIMESTAMP NULL DEFAULT NULL,
            `date_shipped`          TIMESTAMP NULL DEFAULT NULL,
            `date_received`         TIMESTAMP NULL DEFAULT NULL,
            `date_execution`        TIMESTAMP NULL DEFAULT NULL,
            `date_return_expected`  TIMESTAMP NULL DEFAULT NULL,

            `source_origin_itemtype` VARCHAR(255) DEFAULT NULL,
            `source_origin_items_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_auto_generated`      TINYINT NOT NULL DEFAULT 0,
            `movements_id_reverse`   INT {$default_key_sign} NOT NULL DEFAULT 0,

            `has_discrepancy`       TINYINT NOT NULL DEFAULT 0,
            `comment`               TEXT DEFAULT NULL,
            `print_header`          TEXT DEFAULT NULL,
            `print_recipient`       TEXT DEFAULT NULL,
            `is_deleted`            TINYINT NOT NULL DEFAULT 0,
            `date_creation`         TIMESTAMP NULL DEFAULT NULL,
            `date_mod`              TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `kind_status` (`kind`, `status`),
            KEY `entities_id` (`entities_id`),
            KEY `doctype` (`plugin_assetmove_doctypes_id`),
            KEY `source` (`source_itemtype`, `source_items_id`),
            KEY `dest` (`dest_itemtype`, `dest_items_id`),
            KEY `origin` (`source_origin_itemtype`, `source_origin_items_id`),
            KEY `date_planned` (`date_planned`),
            KEY `date_shipped` (`date_shipped`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_assetmove_movements_items')) {
        $query = "CREATE TABLE `glpi_plugin_assetmove_movements_items` (
            `id`               INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_assetmove_movements_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `itemtype`         VARCHAR(255) NOT NULL,
            `items_id`         INT {$default_key_sign} NOT NULL DEFAULT 0,
            `old_locations_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `old_users_id`     INT {$default_key_sign} NOT NULL DEFAULT 0,
            `old_groups_id`    INT {$default_key_sign} NOT NULL DEFAULT 0,
            `old_states_id`    INT {$default_key_sign} NOT NULL DEFAULT 0,
            `old_entities_id`  INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_shipped`       TINYINT NOT NULL DEFAULT 0,
            `date_shipped`     TIMESTAMP NULL DEFAULT NULL,
            `is_received`      TINYINT NOT NULL DEFAULT 0,
            `date_received`    TIMESTAMP NULL DEFAULT NULL,
            `reception_status` TINYINT NOT NULL DEFAULT 0,
            `is_moved`         TINYINT NOT NULL DEFAULT 0,
            `date_moved`       TIMESTAMP NULL DEFAULT NULL,
            `new_states_id`    INT {$default_key_sign} NOT NULL DEFAULT 0,
            `comment`          TEXT DEFAULT NULL,
            `price`            DECIMAL(20,4) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_assetmove_movements_id`, `itemtype`, `items_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `reception_status` (`reception_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_assetmove_doctypes')) {
        $query = "CREATE TABLE `glpi_plugin_assetmove_doctypes` (
            `id`                   INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `entities_id`          INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive`         TINYINT NOT NULL DEFAULT 1,
            `name`                 VARCHAR(255) DEFAULT NULL,
            `kind`                 TINYINT NOT NULL DEFAULT 1,
            `source_itemtype`      VARCHAR(255) DEFAULT NULL,
            `dest_itemtype`        VARCHAR(255) DEFAULT NULL,
            `is_apply_location`    TINYINT NOT NULL DEFAULT 1,
            `is_apply_user`        TINYINT NOT NULL DEFAULT 0,
            `is_apply_entity`      TINYINT NOT NULL DEFAULT 0,
            `is_clear_location`    TINYINT NOT NULL DEFAULT 0,
            `is_clear_user`        TINYINT NOT NULL DEFAULT 0,
            `states_id_target`     INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_two_phase`         TINYINT NOT NULL DEFAULT 0,
            `locations_id_transit` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_validation_required` TINYINT NOT NULL DEFAULT 0,
            `is_autoexecute_on_approval` TINYINT NOT NULL DEFAULT 0,
            `is_return_expected`   TINYINT NOT NULL DEFAULT 0,
            `is_selfreception_allowed` TINYINT NOT NULL DEFAULT 0,
            `is_default_for_reception` TINYINT NOT NULL DEFAULT 0,
            `is_active`            TINYINT NOT NULL DEFAULT 1,
            `comment`              TEXT DEFAULT NULL,
            `date_creation`        TIMESTAMP NULL DEFAULT NULL,
            `date_mod`             TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `kind_active` (`kind`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_assetmove_approvalsteps')) {
        $query = "CREATE TABLE `glpi_plugin_assetmove_approvalsteps` (
            `id`            INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_assetmove_doctypes_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `step_order`    INT NOT NULL DEFAULT 1,
            `name`          VARCHAR(255) DEFAULT NULL,
            `approver_type` VARCHAR(50) NOT NULL,
            `approver_id`   INT {$default_key_sign} NOT NULL DEFAULT 0,
            `mode`          TINYINT NOT NULL DEFAULT 1,
            `is_mandatory`  TINYINT NOT NULL DEFAULT 1,
            `condition_min_price` DECIMAL(20,6) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `type_order` (`plugin_assetmove_doctypes_id`, `step_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_assetmove_validations')) {
        $query = "CREATE TABLE `glpi_plugin_assetmove_validations` (
            `id`            INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_assetmove_movements_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `step_order`    INT NOT NULL DEFAULT 1,
            `mode`          TINYINT NOT NULL DEFAULT 1,
            `is_mandatory`  TINYINT NOT NULL DEFAULT 1,
            `users_id`      INT {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_validate`  INT {$default_key_sign} NOT NULL DEFAULT 0,
            `groups_id_validate` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_answer`    INT {$default_key_sign} NOT NULL DEFAULT 0,
            `status`        INT NOT NULL DEFAULT 1,
            `comment_submission` TEXT DEFAULT NULL,
            `comment_validation` TEXT DEFAULT NULL,
            `submission_date`    TIMESTAMP NULL DEFAULT NULL,
            `validation_date`    TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `movement_step` (`plugin_assetmove_movements_id`, `step_order`, `status`),
            KEY `validator` (`users_id_validate`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_assetmove_warehouses')) {
        $query = "CREATE TABLE `glpi_plugin_assetmove_warehouses` (
            `id`               INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `entities_id`      INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive`     TINYINT NOT NULL DEFAULT 0,
            `name`             VARCHAR(255) DEFAULT NULL,
            `locations_id`     INT {$default_key_sign} NOT NULL DEFAULT 0,
            `users_id_manager` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `groups_id`        INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_default`       TINYINT NOT NULL DEFAULT 0,
            `is_active`        TINYINT NOT NULL DEFAULT 1,
            `comment`          TEXT DEFAULT NULL,
            `print_requisites` TEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `locations_id` (`locations_id`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_assetmove_configs')) {
        $query = "CREATE TABLE `glpi_plugin_assetmove_configs` (
            `id`                        INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `locations_id_transit`      INT {$default_key_sign} NOT NULL DEFAULT 0,
            `states_id_instock`         INT {$default_key_sign} NOT NULL DEFAULT 0,
            `states_id_intransit`       INT {$default_key_sign} NOT NULL DEFAULT 0,
            `states_id_writeoff`        INT {$default_key_sign} NOT NULL DEFAULT 0,
            `states_id_lost`            INT {$default_key_sign} NOT NULL DEFAULT 0,
            `states_id_damaged`         INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_order_integration_active` TINYINT NOT NULL DEFAULT 1,
            `reception_initial_status`  INT NOT NULL DEFAULT 1,
            `is_infocom_fallback_active` TINYINT NOT NULL DEFAULT 0,
            `is_block_parallel_movements` TINYINT NOT NULL DEFAULT 1,
            `is_move_to_trash_on_writeoff` TINYINT NOT NULL DEFAULT 0,
            `is_close_tickets_on_writeoff` TINYINT NOT NULL DEFAULT 0,
            `is_fill_infocom_date_on_writeoff` TINYINT NOT NULL DEFAULT 1,
            `overdue_reminder_days`     INT NOT NULL DEFAULT 3,
            `number_format_move`        VARCHAR(64) NOT NULL DEFAULT 'MOV-{Y}-{######}',
            `number_format_writeoff`    VARCHAR(64) NOT NULL DEFAULT 'WOF-{Y}-{######}',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};";
        $DB->doQuery($query);
    }
}

/**
 * Seed the singleton config row and the dropdown values (states, transit
 * location) it points to, without duplicating them on repeated installs/
 * upgrades.
 *
 * @return void
 */
function plugin_assetmove_seed_config()
{
    /** @var DBmysql $DB */
    global $DB;

    // State/location names are stored as plain data (the same way an admin
    // would type them into the dropdown), not run through gettext: they are
    // user-facing dictionary values, not UI strings.
    $states_id_instock    = plugin_assetmove_find_or_create_state('На складі');
    $states_id_intransit  = plugin_assetmove_find_or_create_state('В транзиті');
    $states_id_writeoff   = plugin_assetmove_find_or_create_state('Виведено з експлуатації');
    $states_id_lost       = plugin_assetmove_find_or_create_state('Втрачено');
    $states_id_damaged    = plugin_assetmove_find_or_create_state('Пошкоджено');
    $locations_id_transit = plugin_assetmove_find_or_create_transit_location();

    if ($DB->request(['FROM' => 'glpi_plugin_assetmove_configs', 'LIMIT' => 1])->count() === 0) {
        $DB->insert('glpi_plugin_assetmove_configs', [
            'locations_id_transit' => $locations_id_transit,
            'states_id_instock'    => $states_id_instock,
            'states_id_intransit'  => $states_id_intransit,
            'states_id_writeoff'   => $states_id_writeoff,
            'states_id_lost'       => $states_id_lost,
            'states_id_damaged'    => $states_id_damaged,
        ]);
    }
}

/**
 * Find an existing State by name, or create it.
 *
 * @param string $name
 *
 * @return int
 */
function plugin_assetmove_find_or_create_state($name)
{
    $state = new State();

    if ($state->getFromDBByCrit(['name' => $name])) {
        return (int) $state->getID();
    }

    $states_id = $state->add([
        'name'         => $name,
        'entities_id'  => 0,
        'is_recursive' => 1,
    ]);

    return $states_id ? (int) $states_id : 0;
}

/**
 * Find or create the "Transit" location used as a stopover for two-phase
 * movements when a movement type does not override it.
 *
 * @return int
 */
function plugin_assetmove_find_or_create_transit_location()
{
    $location = new Location();
    $name     = __('Transit', 'assetmove');

    if ($location->getFromDBByCrit(['name' => $name, 'locations_id' => 0])) {
        return (int) $location->getID();
    }

    $locations_id = $location->add([
        'name'         => $name,
        'locations_id' => 0,
        'entities_id'  => 0,
        'is_recursive' => 1,
    ]);

    return $locations_id ? (int) $locations_id : 0;
}

/**
 * Plugin uninstall process.
 *
 * @return boolean
 */
function plugin_assetmove_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    CronTask::unregister('assetmove');
    plugin_assetmove_remove_notifications();

    $tables = [
        'glpi_plugin_assetmove_validations',
        'glpi_plugin_assetmove_approvalsteps',
        'glpi_plugin_assetmove_movements_items',
        'glpi_plugin_assetmove_movements',
        'glpi_plugin_assetmove_doctypes',
        'glpi_plugin_assetmove_warehouses',
        'glpi_plugin_assetmove_configs',
    ];
    foreach ($tables as $table) {
        $DB->doQuery(sprintf('DROP TABLE IF EXISTS `%s`', $table));
    }

    $rightnames = [
        Movement::$rightname,
        Writeoff::$rightname,
        DocType::$rightname,
        Warehouse::$rightname,
        Config::$rightname,
    ];

    $DB->delete('glpi_profilerights', ['name' => $rightnames]);

    if (isset($_SESSION['glpiactiveprofile'])) {
        foreach ($rightnames as $rightname) {
            unset($_SESSION['glpiactiveprofile'][$rightname]);
        }
    }

    return true;
}

/**
 * Remove the notifications/templates created by
 * NotificationTargetMovement::install(), for both Movement and Writeoff.
 *
 * @return void
 */
function plugin_assetmove_remove_notifications()
{
    foreach ([Movement::class, Writeoff::class] as $itemtype) {
        foreach ((new Notification())->find(['itemtype' => $itemtype]) as $row) {
            (new Notification())->delete(['id' => $row['id']], true);
        }
        foreach ((new NotificationTemplate())->find(['itemtype' => $itemtype]) as $row) {
            (new NotificationTemplate())->delete(['id' => $row['id']], true);
        }
    }
}
