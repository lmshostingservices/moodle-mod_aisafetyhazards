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
 * Scene manager: create scene pictures with LMS Labs AI, one at a time or all missing pictures at once, and create the
 * missing voiceover clips one after another.
 *
 * @module     mod_aisafetyhazards/scenes
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import * as Requests from 'mod_aisafetyhazards/requests';
import {loadStrings, fmt} from 'mod_aisafetyhazards/ui';

/**
 * While clips or pictures are being made one after another, Back and Next are locked and leaving the page asks first,
 * so a run is not cut off by accident. (Leaving never charges twice: every request is stored with its key first.)
 *
 * @param {boolean} on
 * @param {string} busytext shown on the locked buttons' tooltip
 */
const lockNav = (on, busytext = '') => {
    document.querySelectorAll('.sh-setupnav a, .sh-setupnav button, .sh-setup-steps a').forEach((link) => {
        link.classList.toggle('disabled', on);
        if (on) {
            link.setAttribute('aria-disabled', 'true');
            link.setAttribute('tabindex', '-1');
            link.dataset.ssTitle = link.getAttribute('title') || '';
            link.setAttribute('title', busytext);
        } else {
            link.removeAttribute('aria-disabled');
            link.removeAttribute('tabindex');
            link.setAttribute('title', link.dataset.ssTitle || '');
        }
    });
    window.onbeforeunload = on ? () => busytext : null;
};
document.addEventListener('click', (e) => {
    const link = e.target.closest('.sh-setupnav [aria-disabled="true"], .sh-setup-steps [aria-disabled="true"]');
    if (link) {
        e.preventDefault();
        e.stopPropagation();
    }
}, true);

/**
 * Initialises the page.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const S = await loadStrings(['generating', 'genone_confirm', 'genone_confirm_replace', 'genall_confirm',
        'genall_progress', 'confirm_title', 'confirm_create', 'voice_confirm', 'voice_progress',
        'voice_busy', 'genall_busy', 'voice_stop', 'voice_stopping', 'voice_quoting', 'voice_confirm_head',
        'voice_confirm_headone', 'voice_confirm_new', 'voice_confirm_newone', 'voice_confirm_free', 'voice_confirm_freeone',
        'voice_confirm_note']);
    const region = root.querySelector('[data-region="aireqs"]');
    // A delivered picture is already saved: show it by reloading the page.
    const done = (request) => {
        Requests.toast(request);
        window.setTimeout(() => window.location.reload(), 2500);
    };
    Requests.init(region, done);

    // Every paid request is confirmed first; the credits are named in the question.
    const confirm = (question) => new Promise((resolve) => {
        Notification.saveCancel(S.confirm_title, question, S.confirm_create, () => resolve(true), () => resolve(false));
    });
    // While a picture is being made, the button counts the seconds, so a long wait never looks like a hang.
    const ticking = (label, text) => {
        const start = Date.now();
        const draw = () => {
            const s = Math.floor((Date.now() - start) / 1000);
            label.textContent = (label.dataset.base || text) + ' ' + Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        };
        draw();
        const timer = window.setInterval(draw, 1000);
        return () => window.clearInterval(timer);
    };
    // One intentional, stored request (a new key); it is never resent automatically.
    // Ajax.call gives a jQuery promise; a native one is needed for finally().
    const create = (sceneid) => Promise.resolve(Ajax.call([{methodname: 'mod_aisafetyhazards_generate_image',
        args: {sceneid}}], true, true, false, 200000)[0]);

    root.querySelectorAll('[data-action="genimage"]').forEach((btn) => {
        btn.addEventListener('click', async() => {
            if (!await confirm(btn.dataset.replace === '1' ? S.genone_confirm_replace : S.genone_confirm)) {
                return;
            }
            const label = btn.querySelector('span');
            const original = label.textContent;
            btn.disabled = true;
            const stop = ticking(label, S.generating);
            try {
                const request = await create(parseInt(btn.dataset.scene, 10)).finally(stop);
                await Requests.show(region, request, done);
                if (request.status !== 'completed') {
                    btn.disabled = false;
                    label.textContent = original;
                    region.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            } catch (err) {
                btn.disabled = false;
                label.textContent = original;
                Notification.exception(err);
            }
        });
    });

    // Voiceover: every missing clip, one after another; the run stops at the first clip that is not delivered.
    const voice = root.querySelector('[data-action="genvoice"]');
    if (voice) {
        voice.addEventListener('click', async() => {
            const clips = voice.dataset.clips.split(',').filter((v) => v !== '').map((v) => v.split(':').map(Number));
            const each = parseInt(voice.dataset.credits, 10);
            // With free remakes, each clip's current price comes from LMS Labs first (free) and is its ceiling.
            const prices = new Map();
            let question = '';
            if (voice.dataset.remakes === '1' && clips.length) {
                voice.disabled = true;
                const label = voice.querySelector('span');
                const before = label.textContent;
                label.textContent = S.voice_quoting;
                try {
                    const quotes = await Ajax.call([{methodname: 'mod_aisafetyhazards_quote_voices',
                        args: {clips: clips.map(([sceneid, index]) => ({sceneid, index}))}}], true, true, false, 120000)[0];
                    quotes.forEach((q) => prices.set(q.sceneid + ':' + q.index, q.credits));
                } catch (err) {
                    Notification.exception(err);
                    voice.disabled = false;
                    label.textContent = before;
                    return;
                }
                voice.disabled = false;
                label.textContent = before;
                const paid = clips.filter(([s, i]) => prices.get(s + ':' + i) !== 0).length;
                const free = clips.length - paid;
                const parts = [];
                if (paid) {
                    parts.push(fmt(paid === 1 ? S.voice_confirm_newone : S.voice_confirm_new, {paid, each, credits: paid * each}));
                }
                if (free) {
                    parts.push(fmt(free === 1 ? S.voice_confirm_freeone : S.voice_confirm_free, free));
                }
                question = fmt(clips.length === 1 ? S.voice_confirm_headone : S.voice_confirm_head, clips.length) + ' '
                    + parts.join(' ') + ' ' + S.voice_confirm_note;
            } else {
                question = fmt(S.voice_confirm, {count: clips.length, credits: clips.length * each, each});
            }
            if (!clips.length || !await confirm(question)) {
                return;
            }
            const label = voice.querySelector('span');
            const original = label.textContent;
            voice.disabled = true;
            lockNav(true, S.voice_busy);
            // Stop after the clip being made (that one is never cut off: it may already be charged).
            let stop = false;
            const stopbtn = document.createElement('button');
            stopbtn.type = 'button';
            stopbtn.className = 'sh-btn sh-btn-ghost sh-btn-sm ms-2';
            stopbtn.textContent = S.voice_stop;
            stopbtn.addEventListener('click', () => {
                stop = true;
                stopbtn.disabled = true;
                stopbtn.textContent = S.voice_stopping;
            });
            voice.after(stopbtn);
            let made = 0;
            try {
                for (const [sceneid, index] of clips) {
                    if (stop) {
                        break;
                    }
                    label.textContent = fmt(S.voice_progress, {done: made + 1, count: clips.length});
                    let request;
                    try {
                        const maxcredits = prices.get(sceneid + ':' + index) === 0 ? 0 : each;
                        request = await Ajax.call([{methodname: 'mod_aisafetyhazards_create_voice',
                            args: {sceneid, index, maxcredits}}], true, true, false, 120000)[0];
                    } catch (err) {
                        if (err && err.errorcode === 'voice_alreadymade') {
                            // Made meanwhile (another tab): nothing bought, go on.
                            made++;
                            continue;
                        }
                        throw err;
                    }
                    if (request.status !== 'completed') {
                        await Requests.show(region, request, null);
                        region.scrollIntoView({behavior: 'smooth', block: 'start'});
                        break;
                    }
                    made++;
                }
            } catch (err) {
                Notification.exception(err);
            }
            lockNav(false);
            stopbtn.remove();
            if (made > 0) {
                window.location.reload();
                return;
            }
            voice.disabled = false;
            label.textContent = original;
        });
    }

    const all = root.querySelector('[data-action="genall"]');
    if (all) {
        all.addEventListener('click', async() => {
            const ids = all.dataset.scenes.split(',').map((v) => parseInt(v, 10)).filter((v) => v > 0);
            const credits = ids.length * parseInt(all.dataset.credits, 10);
            if (!ids.length || !await confirm(fmt(S.genall_confirm, {count: ids.length, credits}))) {
                return;
            }
            const label = all.querySelector('span');
            const original = label.textContent;
            all.disabled = true;
            lockNav(true, S.genall_busy);
            let made = 0;
            let stopped = false;
            const queue = ids.slice();
            const stop = ticking(label, S.generating);
            const progress = () => fmt(S.genall_progress, {done: made, count: ids.length});
            label.dataset.base = progress();
            // Two pictures at a time (each its own request and key); no new one starts after one that was
            // not delivered.
            const worker = async() => {
                while (queue.length && !stopped) {
                    const request = await create(queue.shift());
                    if (request.status !== 'completed') {
                        stopped = true;
                        await Requests.show(region, request, null);
                        region.scrollIntoView({behavior: 'smooth', block: 'start'});
                        return;
                    }
                    made++;
                    label.dataset.base = progress();
                }
            };
            try {
                await Promise.all([worker(), worker()]);
            } catch (err) {
                Notification.exception(err);
            }
            stop();
            delete label.dataset.base;
            lockNav(false);
            if (made > 0) {
                window.location.reload();
                return;
            }
            all.disabled = false;
            label.textContent = original;
        });
    }
};
