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
use GlpiPlugin\Assetmove\Engine\ApprovalEngine;
use GlpiPlugin\Assetmove\Engine\Mover;
use GlpiPlugin\Assetmove\Engine\StateMachine;
use Log;
use Notepad;
use NotificationEvent;
use Session;

/**
 * Shared base for the two document itemtypes (Movement, Writeoff) that
 * both live in the `glpi_plugin_assetmove_movements` table, distinguished
 * by their `kind` value (see Status/Movement/Writeoff constants).
 *
 * The workflow itself (allowed status transitions, applying changes to
 * assets) lives in Engine\StateMachine / Engine\Mover, milestone M4.
 */
abstract class AbstractDocument extends CommonDBTM
{
    public $dohistory = true;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_assetmove_movements';
    }

    /**
     * The `kind` value (see Status) this itemtype is responsible for.
     *
     * Movement/Writeoff lists are kept separate at the SQL level via the
     * `addDefaultWhere` plugin hook (see plugin_assetmove_addDefaultWhere()
     * in hook.php).
     *
     * @return int
     */
    abstract public static function getKind(): int;

    /**
     * @return string Config field name holding this kind's numbering pattern
     */
    abstract protected static function getNumberFormatConfigField(): string;

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Document_Item::class, $tabs, $options);
        $this->addStandardTab(Validation::class, $tabs, $options);
        $this->addStandardTab(\Document_Item::class, $tabs, $options);
        $this->addStandardTab(Notepad::class, $tabs, $options);
        $this->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        $input['kind'] = static::getKind();

        if (empty($input['entities_id'])) {
            $input['entities_id'] = Session::getActiveEntity();
        }
        if (empty($input['users_id_author'])) {
            $input['users_id_author'] = Session::getLoginUserID();
        }
        if (empty($input['status'])) {
            $input['status'] = Status::NEW;
        }
        if (empty($input['name'])) {
            $input['name'] = $this->generateName();
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();

        NotificationEvent::raiseEvent('new', $this);
    }

    /**
     * Every status change is validated here regardless of how it was
     * requested (form button, forged POST, API): hiding a button in a
     * template is not enough (TZ 16.9). Actually applying the change to
     * the assets happens afterwards, in post_updateItem(), once the new
     * status is safely persisted.
     */
    public function prepareInputForUpdate($input)
    {
        if (isset($input['status']) && (int) $input['status'] !== (int) $this->fields['status']) {
            $result = StateMachine::canTransition(
                $this,
                (int) $input['status'],
                !empty($input['_force']),
                !empty($input['_internal_transition'])
            );
            if ($result !== true) {
                Session::addMessageAfterRedirect($result, false, ERROR);

                return false;
            }
        }

        return $input;
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);

        if (in_array('status', $this->updates, true)) {
            $new_status = (int) $this->fields['status'];
            if ($new_status === Status::TOVALIDATE) {
                ApprovalEngine::submit($this);
            } else {
                Mover::execute($this, (int) $this->oldvalues['status']);
            }

            // 'approved'/'refused' are raised by ApprovalEngine itself
            // (it already has the richer context); every other status
            // change worth an event maps directly from the status here.
            $event = match ($new_status) {
                Status::SHIPPED   => 'shipped',
                Status::DONE      => 'done',
                Status::CANCELLED => 'cancelled',
                default           => null,
            };
            if ($event !== null) {
                NotificationEvent::raiseEvent($event, $this);
            }
        }

        if ((int) ($this->fields['has_discrepancy'] ?? 0) === 1 && in_array('has_discrepancy', $this->updates, true)) {
            NotificationEvent::raiseEvent('discrepancy', $this);
        }
    }

    /**
     * Change status the way Engine\ApprovalEngine drives a document
     * (TOVALIDATE -> APPROVED/REFUSED, and the post-approval autoexecute):
     * still checked against the allowed-transitions matrix and structural
     * preconditions in StateMachine, but not against the current session's
     * rights -- see StateMachine::canTransition()'s `$internal` parameter.
     */
    public function transitionInternally(int $to): bool
    {
        return (bool) $this->update([
            'id'                    => $this->getID(),
            'status'                => $to,
            '_internal_transition'  => true,
        ]);
    }

    /**
     * Render this kind's numbering pattern (Config::number_format_move or
     * ::number_format_writeoff): `{Y}` becomes the current year, a run of
     * `#` becomes a zero-padded sequential number for this kind and year.
     *
     * Sequential numbers are derived by counting existing documents of the
     * same kind/year rather than a dedicated sequence table: fine for the
     * normal one-document-at-a-time flow this plugin targets, but two
     * documents created in the same instant could race for the same
     * number. Acceptable for now; revisit if that turns out to matter.
     *
     * @return string
     */
    protected function generateName(): string
    {
        $format = Config::getConfig()->fields[static::getNumberFormatConfigField()];
        $year   = date('Y', strtotime($_SESSION['glpi_currenttime'] ?? 'now'));

        $count = countElementsInTable(static::getTable(), [
            'kind' => static::getKind(),
            'AND'  => [
                ['date_creation' => ['>=', "$year-01-01 00:00:00"]],
                ['date_creation' => ['<', ($year + 1) . '-01-01 00:00:00']],
            ],
        ]);

        $sequence = $count + 1;

        return preg_replace_callback(
            '/\{Y\}|\{(#+)\}/',
            static function (array $matches) use ($year, $sequence) {
                if ($matches[0] === '{Y}') {
                    return (string) $year;
                }

                return str_pad((string) $sequence, strlen($matches[1]), '0', STR_PAD_LEFT);
            },
            $format
        );
    }

    /**
     * Search options shared by Movement and Writeoff. Each subclass adds
     * its own kind-specific entries (e.g. source/destination for Movement)
     * on top of these, starting numbering at 20 to leave room here.
     *
     * @return array
     */
    protected function getCommonSearchOptions(): array
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => __('Characteristics'),
        ];

        $tab[] = [
            'id'            => '1',
            'table'         => static::getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '2',
            'table'    => static::getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id'            => '3',
            'table'         => static::getTable(),
            'field'         => 'status',
            'name'          => __('Status'),
            'datatype'      => 'specific',
            'searchtype'    => 'equals',
        ];

        $tab[] = [
            'id'       => '4',
            'table'    => static::getTable(),
            'field'    => 'date_planned',
            'name'     => __('Planned date'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => '5',
            'table'    => static::getTable(),
            'field'    => 'date_execution',
            'name'     => __('Execution date'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'            => '6',
            'table'         => 'glpi_users',
            'field'         => 'name',
            'linkfield'     => 'users_id_author',
            'name'          => __('Author'),
            'datatype'      => 'dropdown',
            'right'         => 'all',
        ];

        $tab[] = [
            'id'            => '7',
            'table'         => 'glpi_users',
            'field'         => 'name',
            'linkfield'     => 'users_id_responsible',
            'name'          => __('Responsible', 'assetmove'),
            'datatype'      => 'dropdown',
            'right'         => 'all',
        ];

        $tab[] = [
            'id'       => '8',
            'table'    => static::getTable(),
            'field'    => 'has_discrepancy',
            'name'     => __('Discrepancy', 'assetmove'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'            => '80',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => \Entity::getTypeName(1),
            'datatype'      => 'dropdown',
        ];

        $tab[] = [
            'id'       => '16',
            'table'    => static::getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => '19',
            'table'    => static::getTable(),
            'field'    => 'date_mod',
            'name'     => __('Last update'),
            'datatype' => 'datetime',
        ];

        return $tab;
    }

    /**
     * Friendly label for a polymorphic (itemtype, items_id) reference such
     * as this document's source/destination.
     *
     * @param string|null $itemtype
     * @param int         $items_id
     *
     * @return string
     */
    protected static function formatPolymorphicRef(?string $itemtype, int $items_id): string
    {
        if (empty($itemtype) || $items_id <= 0 || !class_exists($itemtype)) {
            return '';
        }

        $item = new $itemtype();
        if (!$item->getFromDB($items_id)) {
            return '';
        }

        return sprintf('%s: %s', $itemtype::getTypeName(1), $item->getFriendlyName());
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'status') {
            $value = is_array($values) ? $values['status'] : $values;

            return Status::getLabels()[(int) $value] ?? '';
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Status buttons the current user may actually press right now, for
     * the form template to render -- computed from the same StateMachine
     * that enforces them server-side, so a button never lies about what
     * will happen if clicked.
     *
     * @return array<int, string> target status => button label
     */
    public function getAvailableTransitions(): array
    {
        if ($this->isNewItem()) {
            return [];
        }

        $labels = [
            Status::TOVALIDATE => __('Submit for approval', 'assetmove'),
            Status::APPROVED   => __('Approve', 'assetmove'),
            Status::SHIPPED    => __('Ship', 'assetmove'),
            Status::DONE       => $this instanceof Writeoff ? __('Execute', 'assetmove') : __('Receive', 'assetmove'),
            Status::CANCELLED  => __('Cancel', 'assetmove'),
        ];

        $available = [];
        foreach (StateMachine::getAllowedTransitions()[(int) $this->fields['status']] ?? [] as $to) {
            if ($to === Status::DONE && (int) $this->fields['status'] === Status::SHIPPED) {
                // Two-phase reception goes through the per-row form on the
                // Items tab (Engine\Mover::processReception()), not a
                // one-click "Receive" button.
                continue;
            }
            if (StateMachine::canTransition($this, $to) === true) {
                $available[$to] = $labels[$to] ?? (string) $to;
            }
        }

        return $available;
    }
}
