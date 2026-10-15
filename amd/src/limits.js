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
 * Character counters for scene fields, so the text sits neatly on the learner's page.
 *
 * @module     mod_aisafetyhazards/limits
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {loadStrings, fmt} from 'mod_aisafetyhazards/ui';

/**
 * Adds a live "120 / 300" counter under every field with data-sh-limit.
 */
export const init = async() => {
    const fields = document.querySelectorAll('[data-sh-limit]');
    if (!fields.length) {
        return;
    }
    const S = await loadStrings(['limit_count', 'limit_over']);
    fields.forEach((field) => {
        const max = parseInt(field.dataset.ssLimit, 10);
        const counter = document.createElement('div');
        counter.className = 'sh-limit form-text';
        counter.id = 'sh-limit-' + Math.random().toString(36).slice(2);
        field.setAttribute('aria-describedby', ((field.getAttribute('aria-describedby') || '') + ' ' + counter.id).trim());
        field.insertAdjacentElement('afterend', counter);
        const update = () => {
            const length = Array.from(field.value.trim()).length;
            const over = length > max;
            counter.textContent = fmt(over ? S.limit_over : S.limit_count, {length, max});
            counter.classList.toggle('is-over', over);
            // Announced only when the text goes over the limit, not on every key.
            counter.setAttribute('role', over ? 'status' : 'note');
        };
        field.addEventListener('input', update);
        update();
    });
};
