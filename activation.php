<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AI Safety Hazards activation actions (administrators only). The panel itself is in the plugin settings page.
 *
 * Every action is a POST with sesskey and returns to the settings page: check (free), review (free: shows the
 * confirmation with the live price), unlock (spends credits, only with confirm=1 and the exact price and release the
 * administrator saw). A GET without an action goes to the settings page; nothing is ever bought on page load.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_aisafetyhazards\local\unlock;
use mod_aisafetyhazards\local\credentials;

require_login();
require_capability('moodle/site:config', context_system::instance());
// The buttons in the settings page send the action as their own value ("action" is taken by the settings form).
$action = optional_param('activationaction', '', PARAM_ALPHA) ?: optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingaisafetyhazards']);
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/mod/aisafetyhazards/activation.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('activation', 'mod_aisafetyhazards'));
if (!in_array($action, ['check', 'review', 'unlock'], true)) {
    redirect($pageurl);
}
$str = fn($k, $a = null) => get_string($k, 'mod_aisafetyhazards', $a);

if ($action !== '') {
    // Every action is a POST with a valid sesskey; nothing happens on a GET.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
}

// A balance for display.
$balancetext = function (array $state) use ($str): string {
    if (!empty($state['unlimited'])) {
        return $str('act_balance_unlimited');
    }
    return isset($state['credits']) && $state['credits'] !== null ? $str('act_credits', $state['credits'])
        : $str('act_balance_unknown');
};

// A readable error (never contains request data).
$errortext = function (string $error) use ($str): string {
    if ($error === 'nocredentials' || $error === 'network') {
        return $str('act_err_' . $error);
    }
    return $error;
};

// Why the release/price is not confirmed.
$reasontext = function (array $release) use ($str): string {
    $a = $release['reason'] === 'mode' ? $release['mode'] : $release['availability'];
    return $str('act_reason_' . $release['reason'], s($a !== '' ? $a : '-'));
};

// Why unlocking is not offered (with the catalogue reason when the release or price is not confirmed).
$blockedtext = function (string $blocked, array $state, array $release) use ($str, $reasontext): string {
    $text = $str('act_blocked_' . $blocked);
    if ($blocked === 'release' && !empty($release['reason'])) {
        $text .= ' ' . $str('act_price_unavailable', $reasontext($release));
    }
    return $text;
};

if ($action === 'check') {
    $state = unlock::verify();
    $msg = $state['status'] === 'unknown' ? $str('act_msg_check_unknown', s($errortext($state['error'] ?? '')))
        : $str('act_msg_check_' . $state['status']);
    if (!empty($state['resolved'])) {
        $msg .= ' ' . $str('act_msg_resolved_' . $state['resolved']);
    }
    redirect(
        $pageurl,
        $msg,
        null,
        $state['status'] === 'unknown' ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'unlock') {
    // Confirmed on the review screen; the live price and release are checked again before anything is bought.
    $confirm = optional_param('confirm', 0, PARAM_BOOL);
    $expected = required_param('expected', PARAM_INT);
    $sha = required_param('sha', PARAM_ALPHANUM);
    if (!$confirm) {
        redirect($pageurl);
    }
    $r = unlock::buy($expected, $sha);
    $type = \core\output\notification::NOTIFY_ERROR;
    switch ($r['outcome']) {
        case 'unlocked':
            // A new unlock: creditsConsumed is what LMS Labs recorded for it.
            $type = \core\output\notification::NOTIFY_SUCCESS;
            $msg = $str('act_msg_unlocked') . ' ' . ($r['consumed'] !== null ? $str('act_msg_consumed', $r['consumed'])
                : $str('act_msg_notreported'));
            break;
        case 'restored':
            // A recognised purchase (Marketplace or earlier purchase) activated at zero credits.
            $type = \core\output\notification::NOTIFY_SUCCESS;
            $msg = $str('act_msg_unlocked') . ' ' . $str('act_msg_restoredpurchase', s($r['source']));
            break;
        case 'already':
            // Access granted; no charge is claimed. creditsConsumed (if any) is the original purchase.
            $type = \core\output\notification::NOTIFY_SUCCESS;
            $msg = $str('act_msg_already');
            if ($r['historic'] !== null) {
                $msg .= ' ' . $str('act_msg_historic', $r['historic']);
            }
            break;
        case 'insufficient':
            $msg = $str('act_msg_insufficient', s($errortext($r['error'])));
            break;
        case 'stale':
            $type = \core\output\notification::NOTIFY_WARNING;
            $msg = $str('act_msg_stale', s($r['error']));
            break;
        case 'ambiguous':
            $msg = $str('act_msg_ambiguous', s($r['error']));
            break;
        case 'conflict':
            $msg = $str('act_msg_conflict', s($r['error']));
            break;
        case 'changed':
            // Detected here before sending: nothing was bought.
            $type = \core\output\notification::NOTIFY_WARNING;
            $msg = $str('act_msg_changed');
            break;
        case 'uncertain':
            $type = \core\output\notification::NOTIFY_WARNING;
            $msg = $str('act_msg_uncertain', s($errortext($r['error'])));
            break;
        case 'refused':
            $msg = $str('act_msg_refused', s($errortext($r['error'])));
            break;
        default:
            $msg = $str('act_msg_blocked', $str('act_blocked_' . ($r['error'] ?: 'unverified')));
    }
    if (in_array($r['outcome'], ['unlocked', 'restored', 'already', 'insufficient'], true)) {
        // The balance LMS Labs reports (verify: credits; unlock: remainingCredits; insufficient: currentCredits).
        $bal = !empty($r['balance']['unlimited']) || $r['balance']['credits'] !== null ? $r['balance'] : ($r['state'] ?? []);
        if (!empty($bal['unlimited']) || (isset($bal['credits']) && $bal['credits'] !== null)) {
            $msg .= ' ' . $str('act_msg_balance', $balancetext($bal));
        }
    }
    if (in_array($r['outcome'], ['unlocked', 'restored', 'already'], true) && $r['message'] !== '') {
        $msg .= ' ' . $str('act_msg_servermessage', s($r['message']));
    }
    redirect($pageurl, $msg, null, $type);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($str('activation'));

if ($action === 'review') {
    // Free: a fresh access check and the live price. Nothing is bought here.
    $r = unlock::review();
    if (!$r['canbuy']) {
        echo $OUTPUT->notification(
            $str('act_msg_blocked', $blockedtext($r['blocked'], $r['state'], $r['release'])),
            \core\output\notification::NOTIFY_WARNING
        );
        echo $OUTPUT->continue_button($pageurl);
        echo $OUTPUT->footer();
        exit;
    }
    $release = $r['release'];
    $confirmurl = new moodle_url(
        '/mod/aisafetyhazards/activation.php',
        ['action' => 'unlock', 'confirm' => 1,
        'expected' => $release['price'], 'sha' => $release['sha']]
    );
    $warning = $r['warning'] === 'insufficient' ? ' ' . $str(
        'act_warn_insufficient',
        ['price' => $release['price'], 'balance' => $balancetext($r['state'])]
    ) : '';
    $message = $str(
        'act_confirm',
        [
        'price' => $release['price'],
        'balance' => $balancetext($r['state']),
        'release' => ($release['version'] !== '' ? $release['version'] . ', ' : '') . 'SHA-256 ' . $release['sha'],
        ]
    ) . $warning;
    echo $OUTPUT->confirm(
        $message,
        new single_button($confirmurl, $str('act_confirmbutton', $release['price']), 'post', single_button::BUTTON_PRIMARY),
        new single_button($pageurl, get_string('cancel'), 'get')
    );
    echo $OUTPUT->footer();
    exit;
}
