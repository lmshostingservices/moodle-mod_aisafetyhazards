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
 * Stored LMS Labs requests: show them, check again (same key and body, never charged twice) and dismiss them.
 *
 * Only requests LMS Labs reported as still in progress (HTTP 202) are checked again automatically, after the
 * Retry-After delay and a limited number of times. Nothing else is ever resent without the teacher asking.
 *
 * @module     mod_aisafetyhazards/requests
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {add as addToast} from 'core/toast';
import {render, loadStrings} from 'mod_aisafetyhazards/ui';

/** @var {number} Most automatic checks of one in-progress request per page view. */
const MAX_POLLS = 36;

const polls = new Map();
let strings = null;

/**
 * Loads the strings this module needs.
 *
 * @returns {Promise<object>}
 */
const getStrings = async() => {
    if (!strings) {
        strings = await loadStrings(['aireq_checking', 'aireq_dismissconfirm', 'aireq_dismiss', 'aireq_checkagain']);
    }
    return strings;
};

/**
 * Shows a request in the list (replacing its earlier version), then schedules a check if it is in progress.
 *
 * @param {HTMLElement} region the aireq_list region
 * @param {object} request as returned by the web services
 * @param {Function} onComplete called with the request once it is completed
 */
export const show = async(region, request, onComplete) => {
    const list = region.querySelector('[data-region="aireqlist"]');
    const node = await render('aireq_item', request);
    const old = list.querySelector('[data-request="' + request.id + '"]');
    if (old) {
        old.replaceWith(node);
    } else {
        list.prepend(node);
    }
    region.hidden = false;
    if (request.status === 'completed' && onComplete) {
        onComplete(request, node);
    }
    schedule(region, node, onComplete);
};

/**
 * Checks an in-progress request again after its Retry-After delay.
 *
 * @param {HTMLElement} region
 * @param {HTMLElement} node
 * @param {Function} onComplete
 */
const schedule = (region, node, onComplete) => {
    if (node.dataset.poll !== '1') {
        return;
    }
    const id = parseInt(node.dataset.request, 10);
    const count = (polls.get(id) || 0) + 1;
    if (count > MAX_POLLS) {
        return;
    }
    polls.set(id, count);
    const delay = Math.max(1, parseInt(node.dataset.retryafter, 10) || 5) * 1000;
    window.setTimeout(() => {
        if (document.body.contains(node) && node.dataset.poll === '1') {
            check(region, node, onComplete, false);
        }
    }, delay);
};

/**
 * Asks LMS Labs again about one request (same key, same body).
 *
 * @param {HTMLElement} region
 * @param {HTMLElement} node
 * @param {Function} onComplete
 * @param {boolean} byTeacher
 */
const check = async(region, node, onComplete, byTeacher) => {
    const S = await getStrings();
    const button = node.querySelector('[data-action="recheck"]');
    if (button) {
        button.disabled = true;
        button.textContent = S.aireq_checking;
    }
    try {
        const request = await Ajax.call([{methodname: 'mod_aisafetyhazards_check_request',
            args: {cmid: parseInt(region.dataset.cmid, 10), requestid: parseInt(node.dataset.request, 10)}}],
            true, true, false, 180000)[0];
        await show(region, request, onComplete);
    } catch (err) {
        if (button) {
            button.disabled = false;
            button.textContent = S.aireq_checkagain;
        }
        if (byTeacher) {
            Notification.exception(err);
        }
    }
};

/**
 * Dismisses one request.
 *
 * @param {HTMLElement} region
 * @param {HTMLElement} node
 */
const dismiss = async(region, node) => {
    const S = await getStrings();
    const go = async() => {
        try {
            await Ajax.call([{methodname: 'mod_aisafetyhazards_dismiss_request',
                args: {cmid: parseInt(region.dataset.cmid, 10), requestid: parseInt(node.dataset.request, 10)}}])[0];
            node.remove();
            region.hidden = !region.querySelector('[data-request]');
        } catch (err) {
            Notification.exception(err);
        }
    };
    if (node.querySelector('[data-action="dismiss"]').dataset.confirm === '1') {
        Notification.saveCancel('', S.aireq_dismissconfirm, S.aireq_dismiss, go);
    } else {
        go();
    }
};

/**
 * Initialises a request list.
 *
 * @param {HTMLElement} region the aireq_list region
 * @param {Function} onComplete called with a request once it is completed
 */
export const init = (region, onComplete) => {
    if (!region) {
        return;
    }
    region.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action="recheck"], [data-action="dismiss"]');
        const node = button && button.closest('[data-request]');
        if (!node) {
            return;
        }
        if (button.dataset.action === 'recheck') {
            check(region, node, onComplete, true);
        } else {
            dismiss(region, node);
        }
    });
    region.querySelectorAll('[data-request]').forEach((node) => schedule(region, node, onComplete));
};

/**
 * Shows a toast for a completed request.
 *
 * @param {object} request
 */
export const toast = (request) => addToast(request.message, {type: 'success'});
