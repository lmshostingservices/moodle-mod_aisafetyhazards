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
 * Copy-to-clipboard buttons (AI prompts).
 *
 * @module     mod_aisafetyhazards/copy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialises copy buttons: data-target = selector of the textarea to copy, data-done = "Copied" text.
 *
 * @param {string} selector
 */
export const init = (selector) => {
    document.querySelectorAll(selector).forEach((btn) => {
        btn.addEventListener('click', async() => {
            const target = document.querySelector(btn.dataset.target);
            if (!target) {
                return;
            }
            const label = btn.querySelector('span');
            const original = label.textContent;
            try {
                await navigator.clipboard.writeText(target.value);
            } catch (e) {
                // The fallback needs the text visible: open a closed "Show the prompt" section first.
                const details = target.closest('details');
                if (details) {
                    details.open = true;
                }
                target.select();
                document.execCommand('copy');
            }
            label.textContent = btn.dataset.done;
            btn.classList.add('is-done');
            window.setTimeout(() => {
                label.textContent = original;
                btn.classList.remove('is-done');
            }, 2000);
        });
    });
};
