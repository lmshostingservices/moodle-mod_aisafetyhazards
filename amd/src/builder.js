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
 * Lesson builder: the two-step wizard (the work task: industry, sub-industry and task, each list following the choice
 * above it; then language, size and picture style), then reviewing a pasted AI draft and creating the scenes.
 *
 * @module     mod_aisafetyhazards/builder
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {init as initCopy} from 'mod_aisafetyhazards/copy';
import * as Requests from 'mod_aisafetyhazards/requests';
import {render, loadStrings, fmt} from 'mod_aisafetyhazards/ui';

/**
 * The risk rating of a likelihood and consequence (the same 5 × 5 matrix as the server).
 *
 * @param {number} l 1 to 5
 * @param {number} c 1 to 5
 * @returns {string}
 */
const riskRating = (l, c) => {
    const score = Math.max(1, Math.min(5, Number(l) || 3)) * Math.max(1, Math.min(5, Number(c) || 3));
    if (score <= 3) {
        return 'low';
    }
    if (score <= 6) {
        return 'medium';
    }
    return score <= 12 ? 'high' : 'extreme';
};

/**
 * One choice card.
 *
 * @param {string} name input name
 * @param {string} value
 * @param {string} label
 * @param {boolean} checked
 * @param {boolean} other the "type your own" card
 * @returns {HTMLElement}
 */
const card = (name, value, label, checked, other = false) => {
    const wrap = document.createElement('label');
    wrap.className = 'sh-pick' + (other ? ' sh-pick-other' : '');
    const input = document.createElement('input');
    input.type = 'radio';
    input.name = name;
    input.value = value;
    input.checked = checked;
    const body = document.createElement('span');
    body.className = 'sh-pick-body';
    const strong = document.createElement('strong');
    strong.textContent = label;
    body.appendChild(strong);
    wrap.append(input, body);
    return wrap;
};

/**
 * The wizard (set-up steps 1 and 2).
 *
 * @param {string} selector
 * @param {number} start the step to open (1 or 2), e.g. 2 when coming back from step 3
 */
export const initWizard = async(selector, start = 1) => {
    const form = document.querySelector(selector);
    if (!form) {
        return;
    }
    const data = JSON.parse(form.dataset.tree || '{}');
    const tree = data.tree || [];
    const steps = Array.from(form.querySelectorAll('[data-step]'));
    const prev = form.querySelector('[data-action="prev"]');
    const next = form.querySelector('[data-action="nextstep"]');
    const save = form.querySelector('[data-action="save"]');
    const S = await loadStrings(['other_own', 'taskpath']);
    let current = 0;
    form.classList.add('is-enhanced');

    const level = (name) => form.querySelector('[data-level="' + name + '"]');
    const value = (name) => form.querySelector('input[name="' + name + '"]:checked')?.value || '';
    const custom = (name) => form.querySelector('input[name="custom' + name + '"]').value.trim();
    const name = (name) => {
        const v = value(name);
        if (v === 'custom') {
            return custom(name);
        }
        const input = form.querySelector('input[name="' + name + '"]:checked');
        return input ? input.closest('label').textContent.trim() : '';
    };
    const fill = (region, inputname, entries, selected) => {
        const grid = form.querySelector('[data-region="' + region + '"]');
        grid.replaceChildren(...entries.map((e) => card(inputname, e.key, e.name, e.key === selected)),
            card(inputname, 'custom', S.other_own, selected === 'custom', true));
    };
    // Each level follows the one above it: an industry shows its sub-industries, a sub-industry its tasks.
    const sync = (focus = '') => {
        const ind = value('industry');
        const entry = tree.find((i) => i.key === ind);
        const subs = entry ? entry.subs : [];
        level('subindustry').hidden = ind === '';
        form.querySelector('[data-custom="industry"]').hidden = ind !== 'custom';
        // A new choice above clears the one below (or picks Other when there is nothing to choose from).
        const reset = (list) => (list.length ? '' : 'custom');
        if (focus === 'industry' || !form.querySelector('input[name="subindustry"]')) {
            fill('subindustries', 'subindustry', subs, focus === 'industry' ? reset(subs) : data.subindustry);
        }
        const sub = value('subindustry');
        const subentry = subs.find((s) => s.key === sub);
        const tasks = subentry ? subentry.tasks : [];
        level('task').hidden = sub === '';
        form.querySelector('[data-custom="subindustry"]').hidden = sub !== 'custom';
        if (focus === 'industry' || focus === 'subindustry' || !form.querySelector('input[name="task"]')) {
            fill('tasks', 'task', tasks, focus ? reset(tasks) : data.task);
        }
        const task = value('task');
        form.querySelector('[data-custom="task"]').hidden = task !== 'custom';
        const path = [name('industry'), name('subindustry'), name('task')].filter((p) => p !== '');
        form.querySelector('[data-region="taskpath"]').textContent = path.length === 3 ? fmt(S.taskpath, path.join(' › ')) : '';
        if (focus) {
            const target = level(focus === 'industry' ? 'subindustry' : 'task');
            if (focus !== 'task') {
                target.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }
        }
    };
    const taskChosen = () => ['industry', 'subindustry', 'task'].every((n) => value(n) !== ''
        && (value(n) !== 'custom' || custom(n) !== ''));
    const show = (i) => {
        current = Math.max(0, Math.min(steps.length - 1, i));
        steps.forEach((s, k) => {
            s.hidden = k !== current;
        });
        prev.hidden = current === 0;
        next.hidden = current === steps.length - 1;
        save.hidden = current !== steps.length - 1;
        form.scrollIntoView({behavior: 'smooth', block: 'start'});
    };
    const valid = () => {
        const error = form.querySelector('[data-region="taskerror"]');
        error.hidden = current !== 0 || taskChosen();
        return error.hidden;
    };

    form.addEventListener('change', (e) => {
        if (['industry', 'subindustry', 'task'].includes(e.target.name)) {
            sync(e.target.name);
            form.querySelector('[data-region="taskerror"]').hidden = true;
            if (e.target.value === 'custom') {
                form.querySelector('input[name="custom' + e.target.name + '"]').focus();
            }
        }
    });
    form.addEventListener('input', (e) => {
        if (e.target.name && e.target.name.startsWith('custom')) {
            sync();
        }
    });
    prev.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => {
        if (valid()) {
            show(current + 1);
        }
    });
    form.addEventListener('submit', (e) => {
        if (!taskChosen()) {
            e.preventDefault();
            show(0);
            valid();
        }
    });
    sync();
    show(start - 1);
};

/**
 * Step 3: review a pasted draft and create the scenes.
 *
 * @param {string} selector
 */
export const initBuild = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const S = await loadStrings(['lessoninvalid', 'creating', 'lessonempty', 'aidraft_drafting', 'aidraft_confirm',
        'confirm_title', 'confirm_create', 'import_confirm', 'rating_low', 'rating_medium', 'rating_high', 'rating_extreme']);
    initCopy('.sh-copy');
    const cmid = parseInt(root.dataset.cmid, 10);
    const review = root.querySelector('[data-region="review"]');

    const showDraft = async(draft) => {
        const scenes = draft.scenes.map((s, index) => ({title: s.title, context: s.context, index,
            hazards: s.hazards.filter((h) => !h.distractor).map((h) => {
                const rating = riskRating(h.likelihood, h.consequence);
                return {name: h.name, rating, ratingname: S['rating_' + rating],
                    controls: Object.values(h.controls).reduce((n, row) => n + row.length, 0)};
            }),
            distractors: s.hazards.filter((h) => h.distractor).map((h) => ({name: h.name}))}));
        const node = await render('builder_review', {count: scenes.length, scenes});
        review.replaceChildren(node);
        review.hidden = false;
        review.scrollIntoView({behavior: 'smooth', block: 'start'});
        node.querySelector('[data-action="create"]').addEventListener('click', async(e) => {
            const btn = e.currentTarget;
            const keep = Array.from(node.querySelectorAll('[data-scene]')).filter((c) => c.checked)
                .map((c) => draft.scenes[parseInt(c.dataset.scene, 10)]);
            if (!keep.length) {
                Notification.alert('', S.lessonempty);
                return;
            }
            // Charged like LMS Labs scene drafts: confirmed first, with the credits named.
            const credits = keep.length * parseInt(root.dataset.importcredits, 10);
            const go = await new Promise((resolve) => Notification.saveCancel(S.confirm_title,
                fmt(S.import_confirm, {count: keep.length, credits}), S.confirm_create, () => resolve(true), () => resolve(false)));
            if (!go) {
                return;
            }
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = S.creating;
            const region = root.querySelector('[data-region="importreqs"] [data-region="aireqs"]');
            const imported = (request) => {
                Requests.toast(request);
                window.setTimeout(() => {
                    window.location.href = root.dataset.nexturl;
                }, 1500);
            };
            try {
                // One intentional, stored charge per click (a new key); it is never resent automatically.
                const request = await Ajax.call([{methodname: 'mod_aisafetyhazards_import_lesson', args: {cmid,
                    // Escape "<" so the JSON passes PARAM_TEXT; the server decodes it and strips any markup itself.
                    draft: JSON.stringify({scenes: keep}).replace(/</g, '\\u003c')}}], true, true, false, 180000)[0];
                await Requests.show(region, request, imported);
                if (request.status !== 'completed') {
                    btn.disabled = false;
                    btn.textContent = original;
                    region.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            } catch (err) {
                btn.disabled = false;
                btn.textContent = original;
                Notification.exception(err);
            }
        });
    };

    // Two ways to create scenes: show only the one the teacher picks.
    root.querySelectorAll('input[name="sh-path"]').forEach((radio) => radio.addEventListener('change', () => {
        root.querySelectorAll('[data-path]').forEach((path) => {
            path.hidden = path.dataset.path !== radio.value;
        });
        root.querySelector('[data-path="' + radio.value + '"]').scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }));

    const importregion = root.querySelector('[data-region="importreqs"] [data-region="aireqs"]');
    if (importregion) {
        // An import confirmed later ("Check again") has created its scenes: continue to the pictures.
        Requests.init(importregion, () => {
            window.location.href = root.dataset.nexturl;
        });
    }

    const panel = root.querySelector('[data-region="aidraft"]');
    if (panel) {
        const region = panel.querySelector('[data-region="aireqs"]');
        // A delivered scene is saved already: reload so it counts and Next opens.
        const delivered = (request) => {
            Requests.toast(request);
            window.setTimeout(() => window.location.reload(), 2500);
        };
        Requests.init(region, delivered);
        const button = panel.querySelector('[data-action="aidraft"]');
        const label = button.querySelector('span');
        const original = label.textContent;
        button.addEventListener('click', async() => {
            const value = (name) => panel.querySelector('[data-field="' + name + '"]').value;
            // No text of their own: LMS Labs AI writes a scene from the choices made earlier.
            const brief = value('brief').trim() || panel.querySelector('[data-field="brief"]').dataset.default;
            // A paid request: confirmed first, with the credits named.
            const go = await new Promise((resolve) => Notification.saveCancel(S.confirm_title, S.aidraft_confirm,
                S.confirm_create, () => resolve(true), () => resolve(false)));
            if (!go) {
                return;
            }
            button.disabled = true;
            label.textContent = S.aidraft_drafting;
            try {
                // One intentional, stored request per click (a new key); it is never resent automatically.
                const request = await Ajax.call([{methodname: 'mod_aisafetyhazards_draft_scene', args: {cmid, brief}}],
                    true, true, false, 180000)[0];
                await Requests.show(region, request, delivered);
            } catch (err) {
                Notification.exception(err);
            } finally {
                button.disabled = false;
                label.textContent = original;
            }
        });
    }

    const preview = root.querySelector('[data-action="preview"]');
    preview?.addEventListener('click', async() => {
        // Read on the server exactly as the import will read it, so only scenes that can be created are shown.
        const text = root.querySelector('[data-region="json"]').value;
        if (!text.trim()) {
            Notification.alert('', S.lessoninvalid);
            return;
        }
        try {
            const result = await Ajax.call([{methodname: 'mod_aisafetyhazards_preview_lesson', args: {cmid,
                draft: text.replace(/</g, '\\u003c')}}])[0];
            showDraft(JSON.parse(result.data));
        } catch (err) {
            Notification.exception(err);
        }
    });
};
