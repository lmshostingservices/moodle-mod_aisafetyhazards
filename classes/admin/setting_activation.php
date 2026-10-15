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

namespace mod_aisafetyhazards\admin;

use html_writer;
use mod_aisafetyhazards\local\credentials;
use mod_aisafetyhazards\local\unlock;
use moodle_url;

/**
 * The activation panel inside the plugin settings page.
 *
 * It shows the stored access state only, so opening the settings page never calls LMS Labs and never spends
 * credits. "Check access" and "Unlock" post the page's sesskey to activation.php, which acts and returns here; the
 * live price is fetched and confirmed on the unlock confirmation step. The panel stores no setting of its own.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_activation extends \admin_setting {
    /**
     * Creates the panel.
     *
     * @param string $name
     */
    public function __construct(string $name) {
        $this->nosave = true;
        parent::__construct($name, get_string('activation', 'mod_aisafetyhazards'), '', '');
    }

    /**
     * Nothing is stored.
     *
     * @return bool
     */
    public function get_setting() {
        return true;
    }

    /**
     * Nothing is stored.
     *
     * @return bool
     */
    public function get_defaultsetting() {
        return true;
    }

    /**
     * Nothing is stored.
     *
     * @param mixed $data
     * @return string
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Found by an admin search for activation, unlock or access.
     *
     * @param string $query
     * @return bool
     */
    public function is_related($query) {
        if (parent::is_related($query)) {
            return true;
        }
        $query = \core_text::strtolower($query);
        foreach (['activation', 'activate', 'unlock', 'access', 'entitlement'] as $word) {
            if (str_contains($word, $query) || str_contains($query, $word)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The panel: where the credentials come from, the last known access, balance, and the two actions.
     *
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        $str = fn($k, $a = null) => get_string($k, 'mod_aisafetyhazards', $a);
        $pair = credentials::find();
        $source = $pair === null ? 'missing' : $pair['source'];
        $state = unlock::state();
        $pending = unlock::pending();

        $details = [];
        if (!empty($state['checkedat'])) {
            $details[] = $str('act_checkedat', userdate($state['checkedat']));
        }
        if (!empty($state['unlockedat'])) {
            $details[] = $str('act_unlockedat', userdate($state['unlockedat']));
        }
        if (!empty($state['source'])) {
            $details[] = $str('act_entitlementsource', $state['source']);
        }
        if ($state['status'] === 'unknown' && !empty($state['error'])) {
            $details[] = in_array($state['error'], ['nocredentials', 'network'], true)
                ? $str('act_err_' . $state['error']) : $state['error'];
        }
        if (!empty($state['unlimited'])) {
            $balance = $str('act_balance_unlimited');
        } else {
            $balance = isset($state['credits']) && $state['credits'] !== null ? $str('act_credits', $state['credits'])
                : $str('act_balance_unknown');
        }

        $rows = [
            [$str('act_access'), html_writer::tag('strong', s($str('act_status_' . $state['status']))) .
                ($details ? html_writer::div(s(implode(' · ', $details)), 'text-muted small') : '')],
            [$str('act_source'), s($str('act_source_' . $source))],
            [$str('act_balance'), s($balance)],
            [$str('act_price'), s($str('act_price_onreview'))],
        ];
        $table = '';
        foreach ($rows as [$label, $value]) {
            $table .= html_writer::tag('dt', s($label), ['class' => 'col-sm-3']) .
                html_writer::tag('dd', $value, ['class' => 'col-sm-9']);
        }

        // Why "Unlock" is not offered now; the confirmation step checks again before anything is bought.
        $blocked = '';
        if ($source === 'missing') {
            $blocked = 'nocredentials';
        } else if ($pending) {
            $blocked = 'pending';
        } else if ($state['status'] === 'unlocked') {
            $blocked = 'unlocked';
        }

        // The settings page is one form: the buttons post it (with its sesskey) to activation.php instead. The action
        // travels as the button's own value, because the settings form already has a field called "action".
        $button = function (string $action, string $label, bool $primary, bool $disabled): string {
            $attributes = [
                'type' => 'submit',
                'class' => 'btn ' . ($primary ? 'btn-primary' : 'btn-secondary'),
                'formaction' => (new moodle_url('/mod/aisafetyhazards/activation.php'))->out(false),
                'formmethod' => 'post',
                'formnovalidate' => 'formnovalidate',
                'name' => 'activationaction',
                'value' => $action,
            ];
            if ($disabled) {
                $attributes['disabled'] = 'disabled';
            }
            return html_writer::tag('button', s($label), $attributes);
        };

        $html = html_writer::tag('p', s($str('act_intro') . ' ' . $str('act_notproof')));
        $html .= html_writer::tag('dl', $table, ['class' => 'row mb-2']);
        if ($pending) {
            $html .= html_writer::div(
                s(
                    $str('act_pendingnote', userdate($pending['time'])) . ' ' .
                    $str('act_blocked_pending')
                ),
                'alert alert-warning'
            );
        }
        $html .= html_writer::div(
            $button('check', $str('act_check'), false, $source === 'missing') . ' ' .
            $button('review', $str('act_unlock'), true, $blocked !== ''),
            'd-flex flex-wrap gap-2'
        );
        $html .= html_writer::div(
            s($blocked !== '' ? $str('act_blocked_' . $blocked) : $str('act_unsaved')),
            'text-muted small mt-2'
        );

        $class = $state['status'] === 'unlocked' ? 'alert-success' : 'alert-warning';
        return html_writer::div(
            html_writer::tag('h3', s($this->visiblename), ['class' => 'h5']) . $html,
            "alert $class mod_aisafetyhazards-activation",
            ['id' => 'admin-' . $this->name]
        );
    }
}
