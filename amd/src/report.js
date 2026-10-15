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
 * Reports: select all attempts, and confirm before deleting.
 *
 * @module     mod_aisafetyhazards/report
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {loadStrings} from 'mod_aisafetyhazards/ui';

/**
 * Initialises the page.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    const form = root ? root.querySelector('[data-region="attemptsform"]') : null;
    if (!form) {
        return;
    }
    const S = await loadStrings(['confirmdeleteattempts', 'deleteselected']);
    const all = form.querySelector('[data-action="selectall"]');
    if (all) {
        all.addEventListener('change', () => {
            form.querySelectorAll('input[name="attemptids[]"]').forEach((c) => {
                c.checked = all.checked;
            });
        });
    }
    const del = form.querySelector('[data-action="deleteselected"]');
    if (del) {
        del.addEventListener('click', (e) => {
            if (form.dataset.confirmed === '1') {
                return;
            }
            e.preventDefault();
            if (!form.querySelector('input[name="attemptids[]"]:checked')) {
                return;
            }
            Notification.deleteCancelPromise(S.deleteselected, S.confirmdeleteattempts, S.deleteselected).then(() => {
                form.dataset.confirmed = '1';
                del.click();
                return null;
            }).catch(() => null);
        });
    }
};
