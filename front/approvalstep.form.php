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

use GlpiPlugin\Assetmove\ApprovalStep;
use GlpiPlugin\Assetmove\DocType;

$step = new ApprovalStep();

if (isset($_POST['add'])) {
    $input = [
        'plugin_assetmove_doctypes_id' => $_POST['plugin_assetmove_doctypes_id'] ?? 0,
        'step_order'                   => $_POST['step_order'] ?? 1,
        'name'                         => $_POST['name'] ?? '',
        'approver_type'                => $_POST['approver_type'] ?? ApprovalStep::APPROVER_USER,
        'approver_id'                  => match ($_POST['approver_type'] ?? '') {
            ApprovalStep::APPROVER_USER    => (int) ($_POST['_approver_users_id'] ?? 0),
            ApprovalStep::APPROVER_GROUP   => (int) ($_POST['_approver_groups_id'] ?? 0),
            ApprovalStep::APPROVER_PROFILE => (int) ($_POST['_approver_profiles_id'] ?? 0),
            default                        => 0,
        },
        'mode'                => $_POST['mode'] ?? ApprovalStep::MODE_ALL,
        'is_mandatory'        => isset($_POST['is_mandatory']) ? 1 : 0,
        'condition_min_price' => $_POST['condition_min_price'] !== '' ? $_POST['condition_min_price'] : null,
    ];

    $step->check(-1, CREATE, $input);
    $step->add($input);
    Html::back();
} elseif (isset($_POST['delete'])) {
    $step->check($_POST['id'], DELETE);
    $doctypes_id = $step->fields['plugin_assetmove_doctypes_id'];
    $step->delete($_POST, true);
    Html::redirect(DocType::getFormURLWithID($doctypes_id));
} else {
    Html::redirect(DocType::getSearchURL());
}
