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
 * Scene editor: drag each hazard's number onto the picture, and edit the scene, its three hazards (with their starting
 * likelihood and consequence) and each hazard's rows of labels: ways to eliminate it, ways to reduce the risk, and
 * PPE. Every label is right or wrong; right ones are tagged with their hierarchy level and how much they lower the
 * likelihood and the consequence.
 *
 * @module     mod_aisafetyhazards/editor
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {add as addToast} from 'core/toast';
import {el, icon, button, iconButton, loadStrings, fmt} from 'mod_aisafetyhazards/ui';

const KINDS = ['eliminate', 'reduce', 'ppe'];
const LIKELIHOOD = ['rare', 'unlikely', 'possible', 'likely', 'almostcertain'];
const CONSEQUENCE = ['insignificant', 'minor', 'moderate', 'major', 'catastrophic'];

/**
 * The risk rating of a likelihood and consequence (5 × 5 matrix, as on the server).
 *
 * @param {number} l
 * @param {number} c
 * @returns {string}
 */
export const rating = (l, c) => {
    const score = Math.max(1, Math.min(5, l)) * Math.max(1, Math.min(5, c));
    if (score <= 3) {
        return 'low';
    }
    if (score <= 6) {
        return 'medium';
    }
    return score <= 12 ? 'high' : 'extreme';
};

/**
 * Initialises the editor.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const data = JSON.parse(root.dataset.scene);
    const S = await loadStrings(['ed_scene', 'ed_title', 'ed_context', 'ed_imageprompt', 'ed_teachingnote', 'ed_hazard',
        'ed_hazardname', 'ed_anchor', 'ed_anchor_help', 'ed_why', 'ed_likelihood', 'ed_consequence', 'ed_startrisk',
        'ed_row_eliminate', 'ed_row_reduce', 'ed_row_ppe', 'ed_row_empty',
        'ed_rowhelp_eliminate', 'ed_rowhelp_reduce', 'ed_rowhelp_ppe',
        'ed_label', 'ed_right', 'ed_removes', 'ed_level', 'ed_dl', 'ed_dc', 'ed_reason', 'ed_addlabel', 'ed_removelabel',
        'ed_distractors', 'ed_distractors_help', 'ed_adddistractor', 'ed_saved', 'ed_saving', 'ed_notready',
        'ed_spot', 'ed_spot_auto', 'ed_after', 'ed_eliminated', 'ed_charcount',
        ...LIKELIHOOD.map((k) => 'l_' + k), ...CONSEQUENCE.map((k) => 'c_' + k),
        'rating_low', 'rating_medium', 'rating_high', 'rating_extreme', 'rating_eliminated',
        ...data.levels.map((k) => 'level_' + k)]);
    const content = root.querySelector('[data-region="content"]');
    const figure = root.querySelector('[data-region="figure"]');
    const status = root.querySelector('[data-region="status"]');
    const real = () => data.hazards.filter((h) => !h.distractor);
    let dirty = false;
    const touch = () => {
        dirty = true;
        status.textContent = '';
    };
    window.addEventListener('beforeunload', (e) => {
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // A text field bound to an object's property, with a character count against its limit.
    const field = (label, obj, key, opts = {}) => {
        const wrap = el('label', 'sh-ed-field' + (opts.cls ? ' ' + opts.cls : ''));
        wrap.appendChild(el('span', 'sh-ed-label', {text: label}));
        const input = el(opts.multi ? 'textarea' : 'input', 'sh-input', {dir: 'auto', rows: opts.multi ? (opts.rows || 2) : null,
            type: opts.multi ? null : 'text', maxlength: opts.max ? opts.max * 2 : null});
        input.value = obj[key] || '';
        wrap.appendChild(input);
        let count = null;
        if (opts.limit) {
            count = el('span', 'sh-limit sh-mini');
            wrap.appendChild(count);
        }
        const update = () => {
            if (count) {
                const length = Array.from(input.value.trim()).length;
                count.textContent = fmt(S.ed_charcount, {length, max: opts.limit});
                count.classList.toggle('is-over', length > opts.limit);
            }
        };
        input.addEventListener('input', () => {
            obj[key] = input.value;
            update();
            touch();
            if (opts.onchange) {
                opts.onchange();
            }
        });
        update();
        if (opts.help) {
            wrap.appendChild(el('span', 'sh-mini', {text: opts.help}));
        }
        return wrap;
    };
    // A 1 to 5 (or 0 to 4) select bound to an object's property.
    const select = (label, obj, key, options, onchange) => {
        const wrap = el('label', 'sh-ed-field sh-ed-select');
        wrap.appendChild(el('span', 'sh-ed-label', {text: label}));
        const sel = el('select', 'custom-select form-select');
        options.forEach(([value, text]) => {
            const opt = el('option', '', {value, text});
            opt.selected = String(obj[key]) === String(value);
            sel.appendChild(opt);
        });
        sel.addEventListener('change', () => {
            obj[key] = isNaN(Number(sel.value)) ? sel.value : Number(sel.value);
            touch();
            if (onchange) {
                onchange();
            }
        });
        wrap.appendChild(sel);
        return wrap;
    };
    const check = (label, obj, key, onchange) => {
        const wrap = el('label', 'sh-check-row');
        const box = el('input', '', {type: 'checkbox'});
        box.checked = !!Number(obj[key]);
        box.addEventListener('change', () => {
            obj[key] = box.checked ? 1 : 0;
            touch();
            if (onchange) {
                onchange();
            }
        });
        wrap.append(box, el('span', '', {text: label}));
        return wrap;
    };
    const badge = (key) => el('span', 'sh-rating sh-rating-' + key, {text: S['rating_' + key]});

    // The hazard's risk after its right controls: what learners can reach.
    const after = (h) => {
        let l = h.likelihood;
        let c = h.consequence;
        let gone = false;
        KINDS.forEach((kind) => (h.controls[kind] || []).forEach((x) => {
            if (!Number(x.correct)) {
                return;
            }
            if (kind === 'eliminate' && Number(x.eliminates)) {
                gone = true;
            }
            l -= Number(x.dl) || 0;
            c -= Number(x.dc) || 0;
        }));
        return gone ? 'eliminated' : rating(Math.max(1, l), Math.max(1, c));
    };

    // The picture: one numbered spot per hazard, dragged into place.
    const drawSpots = () => {
        if (!figure) {
            return;
        }
        figure.querySelectorAll('.sh-spot').forEach((s) => s.remove());
        real().forEach((h, i) => {
            const spot = el('button', 'sh-spot' + (Number(h.placed) === 1 ? '' : ' is-auto'), {type: 'button',
                'aria-label': fmt(S.ed_spot, {number: i + 1, name: h.name || ''}),
                title: (h.name || '') + (Number(h.placed) === 1 ? '' : ' · ' + S.ed_spot_auto), text: String(i + 1)});
            spot.style.left = h.x + '%';
            spot.style.top = h.y + '%';
            spot.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                spot.setPointerCapture(e.pointerId);
                const move = (ev) => {
                    const r = figure.getBoundingClientRect();
                    h.x = Math.round(Math.max(0, Math.min(100, (ev.clientX - r.left) / r.width * 100)) * 100) / 100;
                    h.y = Math.round(Math.max(0, Math.min(100, (ev.clientY - r.top) / r.height * 100)) * 100) / 100;
                    h.placed = 1;
                    spot.style.left = h.x + '%';
                    spot.style.top = h.y + '%';
                    spot.classList.remove('is-auto');
                    touch();
                };
                const up = () => {
                    spot.removeEventListener('pointermove', move);
                    spot.removeEventListener('pointerup', up);
                };
                spot.addEventListener('pointermove', move);
                spot.addEventListener('pointerup', up);
            });
            spot.addEventListener('keydown', (e) => {
                const step = e.shiftKey ? 5 : 1;
                const moves = {ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step]};
                if (moves[e.key]) {
                    e.preventDefault();
                    h.x = Math.max(0, Math.min(100, h.x + moves[e.key][0]));
                    h.y = Math.max(0, Math.min(100, h.y + moves[e.key][1]));
                    h.placed = 1;
                    spot.style.left = h.x + '%';
                    spot.style.top = h.y + '%';
                    spot.classList.remove('is-auto');
                    touch();
                }
            });
            figure.appendChild(spot);
        });
    };

    // One label of a row.
    const labelRow = (h, kind, c, redraw) => {
        const row = el('div', 'sh-ed-ctl' + (Number(c.correct) ? ' is-right' : ''));
        const top = el('div', 'sh-ed-ctl-top');
        top.appendChild(field(S.ed_label, c, 'text', {limit: data.limits.text, cls: 'sh-ed-grow'}));
        top.appendChild(check(S.ed_right, c, 'correct', redraw));
        top.appendChild(iconButton('sh-iconbtn', S.ed_removelabel, 'trash', () => {
            if (h.controls[kind].length <= data.rowsize[0]) {
                return;
            }
            h.controls[kind] = h.controls[kind].filter((x) => x !== c);
            touch();
            redraw();
        }));
        row.appendChild(top);
        if (Number(c.correct)) {
            const tags = el('div', 'sh-ed-tags');
            if (kind === 'eliminate') {
                tags.appendChild(check(S.ed_removes, c, 'eliminates', redraw));
            }
            if (kind === 'reduce') {
                tags.appendChild(select(S.ed_level, c, 'level', data.levels.filter((l) => l !== 'elimination' && l !== 'ppe')
                    .map((l) => [l, S['level_' + l]]), redraw));
            }
            if (!(kind === 'eliminate' && Number(c.eliminates))) {
                const steps = [0, 1, 2, 3, 4].map((n) => [n, n ? '−' + n : '0']);
                tags.appendChild(select(S.ed_dl, c, 'dl', steps, redraw));
                tags.appendChild(select(S.ed_dc, c, 'dc', steps, redraw));
            }
            row.appendChild(tags);
        }
        row.appendChild(field(S.ed_reason, c, 'reason', {multi: true, limit: data.limits.reason}));
        return row;
    };

    // One hazard card with its three rows.
    const hazardCard = (h, i) => {
        const card = el('section', 'sh-ed-hazard', {'aria-label': fmt(S.ed_hazard, i + 1)});
        // The head opens and closes the card, so the page stays short: one hazard at a time.
        const head = button('sh-ed-hazard-head', '', 'next', () => setOpen(body.hidden));
        head.replaceChildren();
        const title = el('h4', '', {text: fmt(S.ed_hazard, i + 1)});
        const name = el('span', 'sh-ed-hazard-name', {dir: 'auto'});
        const risk = el('span', 'sh-ed-risk');
        const chevron = icon('next');
        chevron.classList.add('sh-ed-chevron');
        head.append(el('span', 'sh-spot sh-spot-static', {text: String(i + 1), 'aria-hidden': 'true'}), title, name, risk,
            chevron);
        const body = el('div', 'sh-ed-hazard-body', {id: 'sh-ed-hazard-' + i});
        head.setAttribute('aria-controls', body.id);
        card.append(head, body);
        const setOpen = (open) => {
            body.hidden = !open;
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
            card.classList.toggle('is-open', open);
        };
        const syncName = () => {
            name.textContent = h.name ? '· ' + h.name : '';
        };
        const syncRisk = () => {
            risk.replaceChildren(el('span', 'sh-mini', {text: S.ed_startrisk}), badge(rating(h.likelihood, h.consequence)),
                el('span', 'sh-mini', {text: S.ed_after}), badge(after(h)));
        };
        body.appendChild(field(S.ed_hazardname, h, 'name', {limit: data.limits.name, onchange: () => {
            drawSpots();
            syncName();
        }}));
        body.appendChild(field(S.ed_anchor, h, 'anchor', {help: S.ed_anchor_help}));
        body.appendChild(field(S.ed_why, h, 'why', {multi: true, limit: data.limits.why}));
        const scale = el('div', 'sh-ed-scale');
        scale.appendChild(select(S.ed_likelihood, h, 'likelihood', LIKELIHOOD.map((k, n) => [n + 1, (n + 1) + ' · ' + S['l_' + k]]),
            syncRisk));
        scale.appendChild(select(S.ed_consequence, h, 'consequence',
            CONSEQUENCE.map((k, n) => [n + 1, (n + 1) + ' · ' + S['c_' + k]]), syncRisk));
        body.appendChild(scale);
        KINDS.forEach((kind) => {
            const box = el('div', 'sh-ed-row sh-ed-row-' + kind);
            const rowtitle = el('div', 'sh-ed-row-title');
            rowtitle.append(icon(kind), el('strong', '', {text: S['ed_row_' + kind]}));
            box.appendChild(rowtitle);
            box.appendChild(el('p', 'sh-mini', {text: S['ed_rowhelp_' + kind]}));
            const list = el('div', 'sh-ed-ctls');
            // An empty Reduce or PPE row (older content): learners need it even when the hazard can be eliminated.
            const empty = el('p', 'sh-mini sh-ed-warn', {text: S.ed_row_empty, hidden: true});
            box.appendChild(empty);
            const redraw = () => {
                empty.hidden = kind === 'eliminate' || (h.controls[kind] || []).length > 0;
                list.replaceChildren(...(h.controls[kind] || []).map((c) => labelRow(h, kind, c, redraw)));
                add.disabled = (h.controls[kind] || []).length >= data.rowsize[1];
                syncRisk();
            };
            const add = button('sh-btn sh-btn-ghost sh-btn-sm', S.ed_addlabel, 'plus', () => {
                h.controls[kind] = h.controls[kind] || [];
                h.controls[kind].push({id: 0, text: '', correct: 0, eliminates: 0,
                    level: {ppe: 'ppe', eliminate: 'elimination'}[kind] || 'engineering', dl: 0, dc: 0,
                    reason: ''});
                touch();
                redraw();
            });
            box.append(list, add);
            redraw();
            body.appendChild(box);
        });
        syncName();
        syncRisk();
        setOpen(i === 0);
        return card;
    };

    // Distractor hazard labels: hazards that are not in this picture.
    const distractors = () => {
        const box = el('section', 'sh-ed-distractors');
        box.appendChild(el('h4', '', {text: S.ed_distractors}));
        box.appendChild(el('p', 'sh-mini', {text: S.ed_distractors_help}));
        const list = el('div', 'sh-ed-ctls');
        const redraw = () => {
            const items = data.hazards.filter((h) => h.distractor);
            list.replaceChildren(...items.map((h) => {
                const row = el('div', 'sh-ed-ctl');
                const top = el('div', 'sh-ed-ctl-top');
                top.appendChild(field(S.ed_hazardname, h, 'name', {limit: data.limits.name, cls: 'sh-ed-grow'}));
                top.appendChild(iconButton('sh-iconbtn', S.ed_removelabel, 'trash', () => {
                    data.hazards = data.hazards.filter((x) => x !== h);
                    touch();
                    redraw();
                }));
                row.append(top, field(S.ed_why, h, 'why', {multi: true, limit: data.limits.why}));
                return row;
            }));
            add.disabled = items.length >= data.maxdistractors;
        };
        const add = button('sh-btn sh-btn-ghost sh-btn-sm', S.ed_adddistractor, 'plus', () => {
            data.hazards.push({id: 0, name: '', anchor: '', why: '', likelihood: 3, consequence: 3, x: 0, y: 0, placed: 0,
                distractor: 1, controls: {eliminate: [], reduce: [], ppe: []}});
            touch();
            redraw();
        });
        box.append(list, add);
        redraw();
        return box;
    };

    const scene = el('section', 'sh-ed-scene');
    scene.appendChild(el('h4', '', {text: S.ed_scene}));
    scene.appendChild(field(S.ed_title, data, 'title', {max: 255}));
    scene.appendChild(field(S.ed_context, data, 'context', {multi: true, rows: 3, limit: data.limits.context}));
    scene.appendChild(field(S.ed_imageprompt, data, 'imageprompt', {multi: true, rows: 3}));
    scene.appendChild(field(S.ed_teachingnote, data, 'teachingnote', {multi: true}));
    content.replaceChildren(scene, ...real().map(hazardCard), distractors());
    drawSpots();
    if (data.focus === 'spots' && figure) {
        figure.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    const save = root.querySelector('[data-action="save"]');
    save.addEventListener('click', async() => {
        save.disabled = true;
        status.textContent = S.ed_saving;
        try {
            const payload = {title: data.title, context: data.context, imageprompt: data.imageprompt,
                teachingnote: data.teachingnote, hazards: data.hazards};
            const result = await Ajax.call([{methodname: 'mod_aisafetyhazards_save_scene',
                args: {sceneid: data.sceneid, data: JSON.stringify(payload)}}])[0];
            dirty = false;
            status.textContent = result.ready ? S.ed_saved : S.ed_notready;
            addToast(status.textContent, {type: result.ready ? 'success' : 'warning'});
            if (result.ready) {
                window.setTimeout(() => {
                    window.location.href = data.backurl;
                }, 900);
            }
        } catch (err) {
            status.textContent = '';
            Notification.exception(err);
        } finally {
            save.disabled = false;
        }
    });
};
