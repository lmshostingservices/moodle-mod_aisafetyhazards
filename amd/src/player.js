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
 * The hazard player.
 *
 * Each scene is a picture of one step of the work task with three hazards. A see-through box of labels lies over the
 * picture with four rows: hazards (drag each onto its numbered spot), then for each hazard in turn ways to eliminate
 * it, ways to reduce the risk, and the PPE required (drag each onto the hazard). Gauges show the hazard's likelihood,
 * consequence and risk rating; a right control lowers the likelihood and/or the consequence by its tags, so the gauge
 * it changes turns green while the other stays where it was. Every label is marked on the server.
 *
 * Study shows every answer and its effect, step by step. Practice marks each label at once: a wrong one comes back
 * with the reason. Test marks nothing until the end, and the reduce and PPE rows end with "Done".
 *
 * @module     mod_aisafetyhazards/player
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import * as Sound from 'mod_aisafetyhazards/sound';
import {el, icon, button, iconButton, loadStrings, fmt, confetti, REDUCED} from 'mod_aisafetyhazards/ui';

const ROWS = ['hazard', 'eliminate', 'reduce', 'ppe'];
const KINDS = ['eliminate', 'reduce', 'ppe'];
const LIKELIHOOD = ['rare', 'unlikely', 'possible', 'likely', 'almostcertain'];
const CONSEQUENCE = ['insignificant', 'minor', 'moderate', 'major', 'catastrophic'];
const LEVELS = ['elimination', 'substitution', 'isolation', 'engineering', 'administrative', 'ppe'];

const KEYS = ['row_hazard', 'row_eliminate', 'row_reduce', 'row_ppe', 'rowhelp_hazard', 'rowhelp_eliminate',
    'rowhelp_reduce', 'rowhelp_ppe', 'rowwait_hazard', 'rowwait_controls', 'rowdone', 'rowdone_button', 'rowdone_eliminated',
    'likelihood', 'consequence', 'riskrating', 'safetybar', 'safetybar_help', 'progressbar', 'scene_of', 'mode_study',
    'mode_practice', 'mode_test', 'nextscene', 'finish', 'backtohome', 'sounds', 'fullscreen', 'fullscreen_exit',
    'listen', 'listen_stop', 'spot', 'spot_found', 'activehazard', 'hazard_n', 'controls_applied', 'none_yet',
    'right', 'wrong', 'fb_right', 'fb_wrong', 'fb_lowers', 'fb_lowers_l', 'fb_lowers_c', 'fb_removes', 'fb_nochange',
    'fb_hazardfound', 'fb_testsaved', 'fb_cannot', 'test_gauges', 'scenedone', 'scenedone_test', 'study_next', 'study_shown',
    'study_wrong', 'study_hazards', 'labelbox', 'drag_help', 'picked', 'dropon',
    'result_title', 'result_score', 'result_safety', 'result_right', 'result_wrong', 'result_passed', 'result_notpassed',
    'result_passmark', 'result_missed', 'result_wrongchosen', 'result_labelled', 'result_perfect', 'result_start', 'result_end',
    'tryagain', 'next_study', 'next_practice', 'next_test', 'attemptsleft', 'eliminated', 'matrix',
    'rating_low', 'rating_medium', 'rating_high', 'rating_extreme', 'rating_eliminated', 'gauge_value',
    ...LIKELIHOOD.map((k) => 'l_' + k), ...CONSEQUENCE.map((k) => 'c_' + k), ...LEVELS.map((k) => 'level_' + k)];

let S = {};

/**
 * The risk rating of a likelihood and a consequence (the server's 5 × 5 matrix).
 *
 * @param {number} l
 * @param {number} c
 * @returns {string}
 */
const rating = (l, c) => {
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
 * The risk score of a hazard now (0 once eliminated).
 *
 * @param {object} now {likelihood, consequence, eliminated}
 * @returns {number}
 */
const riskScore = (now) => (now.eliminated ? 0 : now.likelihood * now.consequence);

/**
 * The risk rating of a hazard now ('eliminated' once eliminated).
 *
 * @param {object} now {likelihood, consequence, eliminated}
 * @returns {string}
 */
const riskName = (now) => (now.eliminated ? 'eliminated' : rating(now.likelihood, now.consequence));

/**
 * The colour band of a 1 to 5 value: green at 1 and 2, amber at 3, red at 4 and 5.
 *
 * @param {number} v
 * @returns {string}
 */
const band = (v) => ['green', 'green', 'green', 'amber', 'red', 'red'][Math.max(0, Math.min(5, v))];

/**
 * A half-circle gauge from 1 to 5 with a needle.
 *
 * @param {string} label
 * @param {string[]} names the five steps, 1 first
 * @returns {object} {node, set(value, changed)}
 */
const gauge = (label, names) => {
    const node = el('div', 'sh-gauge', {role: 'meter', 'aria-valuemin': '1', 'aria-valuemax': '5', 'aria-label': label});
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 120 70');
    svg.setAttribute('aria-hidden', 'true');
    // Five coloured arcs, green to red.
    const colours = ['g1', 'g2', 'a3', 'r4', 'r5'];
    for (let i = 0; i < 5; i++) {
        const a0 = Math.PI * (1 - i / 5);
        const a1 = Math.PI * (1 - (i + 1) / 5);
        const p = (a, r) => [60 + r * Math.cos(a), 62 - r * Math.sin(a)];
        const [x0, y0] = p(a0, 50);
        const [x1, y1] = p(a1, 50);
        const path = document.createElementNS(ns, 'path');
        path.setAttribute('d', `M${x0.toFixed(2)} ${y0.toFixed(2)} A50 50 0 0 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`);
        path.setAttribute('class', 'sh-gauge-arc sh-gauge-' + colours[i]);
        svg.appendChild(path);
    }
    const needle = document.createElementNS(ns, 'g');
    needle.setAttribute('class', 'sh-gauge-needle');
    const line = document.createElementNS(ns, 'path');
    line.setAttribute('d', 'M60 62 L60 20');
    const hub = document.createElementNS(ns, 'circle');
    hub.setAttribute('cx', '60');
    hub.setAttribute('cy', '62');
    hub.setAttribute('r', '6');
    needle.append(line, hub);
    svg.appendChild(needle);
    const value = el('span', 'sh-gauge-value');
    node.append(el('span', 'sh-gauge-label', {text: label}), svg, value);
    return {
        node,
        set: (v, changed = false) => {
            const angle = -90 + (Math.max(1, Math.min(5, v)) - 0.5) * 36;
            needle.style.transform = `rotate(${angle}deg)`;
            value.textContent = fmt(S.gauge_value, {value: v, name: names[v - 1]});
            node.setAttribute('aria-valuenow', String(v));
            node.setAttribute('aria-valuetext', value.textContent);
            node.dataset.band = band(v);
            if (changed && !REDUCED) {
                node.classList.remove('is-changed');
                // Restart the glow animation.
                void node.offsetWidth;
                node.classList.add('is-changed');
            }
        },
    };
};

/**
 * Voiceover: plays clips one after another, one playlist at a time. Playing costs nothing (the clips are files).
 */
class Voice {
    constructor() {
        this.audio = new Audio();
        this.audio.preload = 'auto';
        this.token = 0;
        this.onstop = null;
    }

    /**
     * Plays a list of clip URLs in order.
     *
     * @param {string[]} urls
     * @param {Function} [onstop] called once when the list ends or is stopped
     */
    play(urls, onstop = null) {
        this.stop();
        if (!urls || !urls.length) {
            return;
        }
        const token = ++this.token;
        this.onstop = onstop;
        let i = 0;
        const next = () => {
            if (token !== this.token) {
                return;
            }
            if (i >= urls.length) {
                this.stop();
                return;
            }
            this.audio.src = urls[i++];
            this.audio.play().catch(() => {
                if (token === this.token) {
                    this.stop();
                }
            });
        };
        this.audio.onended = next;
        this.audio.onerror = () => {
            if (token === this.token) {
                this.stop();
            }
        };
        next();
    }

    /**
     * Stops playing.
     */
    stop() {
        this.token++;
        this.audio.onended = null;
        this.audio.onerror = null;
        this.audio.pause();
        const done = this.onstop;
        this.onstop = null;
        if (done) {
            done();
        }
    }
}

class Player {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     */
    constructor(root) {
        this.root = root;
        this.config = JSON.parse(root.dataset.config || '{}');
        this.home = root.querySelector('[data-region="home"]');
        this.host = root.querySelector('[data-region="player"]');
        this.live = root.querySelector('[data-region="live"]');
        this.voice = new Voice();
        this.busy = false;
        this.picked = null;
        Sound.setAllowed(!!this.config.sounds);
        this.onFullChange = () => this.syncFull();
        document.addEventListener('fullscreenchange', this.onFullChange);
        document.addEventListener('webkitfullscreenchange', this.onFullChange);
    }

    /**
     * Whether the player fills the screen.
     *
     * @returns {boolean}
     */
    isFull() {
        const fs = document.fullscreenElement || document.webkitFullscreenElement;
        return fs === this.host || this.host.classList.contains('is-pseudofull');
    }

    /**
     * Switches full screen on or off (a full-window view where the browser has no full screen for page parts).
     *
     * @param {boolean} on
     */
    async setFull(on) {
        const fs = document.fullscreenElement || document.webkitFullscreenElement;
        if (on) {
            const request = this.host.requestFullscreen || this.host.webkitRequestFullscreen;
            let done = false;
            if (request) {
                try {
                    await request.call(this.host);
                    done = true;
                } catch (e) {
                    done = false;
                }
            }
            if (!done) {
                this.host.classList.add('is-pseudofull');
                document.body.classList.add('sh-noscroll');
            }
        } else {
            if (fs === this.host) {
                const exit = document.exitFullscreen || document.webkitExitFullscreen;
                try {
                    await exit.call(document);
                } catch (e) {
                    // Already left (for example with the Esc key).
                }
            }
            this.host.classList.remove('is-pseudofull');
            document.body.classList.remove('sh-noscroll');
        }
        this.syncFull();
    }

    /**
     * Shows the full screen state on the button and the frame.
     */
    syncFull() {
        const full = this.isFull();
        this.host.classList.toggle('is-full', full);
        const btn = this.host.querySelector('[data-action="fullscreen"]');
        if (btn) {
            btn.setAttribute('aria-pressed', full ? 'true' : 'false');
            const label = full ? S.fullscreen_exit : S.fullscreen;
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
        }
    }

    /**
     * Shows an error (full screen is left first, or Moodle's dialogue would be hidden behind it).
     *
     * @param {Error} err
     */
    async error(err) {
        if (this.isFull()) {
            await this.setFull(false);
        }
        Notification.exception(err);
    }

    /**
     * Announces text to assistive technologies.
     *
     * @param {string} text
     */
    say(text) {
        this.live.textContent = '';
        window.setTimeout(() => {
            this.live.textContent = text;
        }, 50);
    }

    /**
     * Starts or resumes an attempt.
     *
     * @param {string} mode study, practice or test
     */
    async start(mode) {
        this.mode = mode || this.mode || '';
        try {
            const result = await Ajax.call([{methodname: 'mod_aisafetyhazards_start_attempt',
                args: {cmid: this.config.cmid, mode: this.mode}}])[0];
            this.data = JSON.parse(result.data);
        } catch (err) {
            this.error(err);
            return;
        }
        this.mode = this.data.mode;
        this.home.hidden = true;
        this.host.hidden = false;
        this.buildShell();
        // Resume at the first scene not finished.
        const first = this.data.scenes.findIndex((s) => !s.complete);
        this.show(this.mode === 'study' ? 0 : Math.max(0, first));
        this.host.scrollIntoView({behavior: REDUCED ? 'auto' : 'smooth', block: 'start'});
    }

    /**
     * The player frame: back, mode, scene dots, sound and full screen; the stage below.
     */
    buildShell() {
        this.host.replaceChildren();
        const shell = el('div', 'sh-shell sh-mode-' + this.mode);
        const bar = el('header', 'sh-bar');
        bar.appendChild(iconButton('sh-iconbtn', S.backtohome, 'exit', () => this.leave()));
        bar.appendChild(el('span', 'sh-modebadge sh-modebadge-' + this.mode, {text: S['mode_' + this.mode]}));
        this.dots = el('ol', 'sh-dots', {'aria-label': S.progressbar});
        this.data.scenes.forEach((s) => {
            const dot = el('li', 'sh-dot' + (s.complete && this.mode !== 'study' ? ' is-done' : ''));
            dot.appendChild(el('span', 'visually-hidden sr-only', {text: String(s.number)}));
            this.dots.appendChild(dot);
        });
        bar.appendChild(this.dots);
        bar.appendChild(el('span', 'sh-bar-task', {text: this.config.task || ''}));
        const sound = iconButton('sh-iconbtn', S.sounds, Sound.isMuted() ? 'muted' : 'sound', () => {
            const muted = Sound.toggleMute();
            sound.replaceChildren(icon(muted ? 'muted' : 'sound'));
            sound.setAttribute('aria-pressed', muted ? 'false' : 'true');
        });
        sound.setAttribute('aria-pressed', Sound.isMuted() ? 'false' : 'true');
        if (this.config.sounds) {
            bar.appendChild(sound);
        }
        const full = iconButton('sh-iconbtn sh-fullbtn', S.fullscreen, 'full', () => this.setFull(!this.isFull()));
        full.dataset.action = 'fullscreen';
        bar.appendChild(full);
        this.stage = el('div', 'sh-stage');
        shell.append(bar, this.stage);
        this.host.appendChild(shell);
        this.syncFull();
    }

    /**
     * Back to the activity home (the attempt is kept and can be resumed).
     */
    async leave() {
        this.voice.stop();
        if (this.isFull()) {
            await this.setFull(false);
        }
        window.location.reload();
    }

    /**
     * Shows one scene.
     *
     * @param {number} index
     */
    show(index) {
        this.voice.stop();
        this.index = index;
        this.scene = this.data.scenes[index];
        this.picked = null;
        this.studystep = 0;
        Array.from(this.dots.children).forEach((d, i) => {
            d.classList.toggle('is-current', i === index);
            if (i === index) {
                d.setAttribute('aria-current', 'step');
            } else {
                d.removeAttribute('aria-current');
            }
        });
        const scene = this.scene;
        const wrap = el('div', 'sh-play');
        // What is happening in this step of the task.
        const head = el('div', 'sh-scene-head');
        const titles = el('div', 'sh-scene-titles');
        titles.append(el('span', 'sh-eyebrow', {text: fmt(S.scene_of, {number: scene.number, total: this.data.scenes.length})}),
            el('h3', 'sh-scene-title', {dir: 'auto', text: scene.title}));
        if (scene.context) {
            // What is happening in this step of the task, under the title (kept short to leave room for the picture).
            titles.appendChild(el('p', 'sh-scene-context', {dir: 'auto', text: scene.context}));
        }
        head.appendChild(titles);
        if (scene.voice && scene.voice.length) {
            const listen = button('sh-btn sh-btn-ghost sh-btn-sm', S.listen, 'sound', () => {
                if (listen.classList.contains('is-playing')) {
                    this.voice.stop();
                    return;
                }
                listen.classList.add('is-playing');
                listen.querySelector('span').textContent = S.listen_stop;
                this.voice.play(scene.voice, () => {
                    listen.classList.remove('is-playing');
                    listen.querySelector('span').textContent = S.listen;
                });
            });
            head.appendChild(listen);
        }
        if (scene.voicenote) {
            head.appendChild(el('p', 'sh-voicenote', {text: scene.voicenote}));
        }
        wrap.appendChild(head);

        const main = el('div', 'sh-play-main');
        // The picture with its spots, and the label box under it (nothing covers the picture).
        this.figure = el('div', 'sh-figure');
        const pic = el('div', 'sh-pic');
        pic.appendChild(el('img', '', {src: scene.image, alt: '', draggable: 'false'}));
        this.spotnodes = new Map();
        scene.spots.forEach((spot) => pic.appendChild(this.spotNode(spot)));
        this.figure.appendChild(pic);
        this.box = el('div', 'sh-labelbox', {role: 'group', 'aria-label': S.labelbox});
        this.rows = {};
        ROWS.forEach((row) => {
            const r = el('div', 'sh-lrow sh-row-' + row, {'data-row': row});
            const label = el('div', 'sh-lrow-head', {title: S['rowhelp_' + row], tabindex: '0',
                'aria-label': S['row_' + row] + '. ' + S['rowhelp_' + row]});
            label.append(icon(row === 'hazard' ? 'hazard' : row), el('span', 'sh-lrow-name', {text: S['row_' + row]}),
                el('span', 'sh-lrow-tip', {text: S['rowhelp_' + row], 'aria-hidden': 'true'}));
            const chips = el('div', 'sh-lrow-chips');
            r.append(label, chips);
            this.box.appendChild(r);
            this.rows[row] = {node: r, chips};
        });
        this.figure.appendChild(this.box);
        // The picture column: the picture, its label box and the feedback, all as wide as the picture.
        const left = el('div', 'sh-play-left');
        left.appendChild(this.figure);
        this.feedback = el('div', 'sh-feedback', {'aria-live': 'polite'});
        left.appendChild(this.feedback);
        main.appendChild(left);

        // The gauges for the hazard being worked on.
        const side = el('aside', 'sh-side', {'aria-label': S.activehazard});
        this.tabs = el('div', 'sh-hztabs', {role: 'tablist'});
        side.appendChild(this.tabs);
        this.activeName = el('p', 'sh-active-name', {dir: 'auto'});
        side.appendChild(this.activeName);
        const gauges = el('div', 'sh-gauges');
        this.gauges = gauges;
        this.gL = gauge(S.likelihood, LIKELIHOOD.map((k) => S['l_' + k]));
        this.gC = gauge(S.consequence, CONSEQUENCE.map((k) => S['c_' + k]));
        gauges.append(this.gL.node, this.gC.node);
        side.appendChild(gauges);
        this.ratingNode = el('div', 'sh-riskrow');
        side.appendChild(this.ratingNode);
        this.matrix = this.buildMatrix();
        side.appendChild(this.matrix);
        this.testnote = el('p', 'sh-mini sh-testnote', {text: S.test_gauges, hidden: this.mode !== 'test'});
        side.appendChild(this.testnote);
        this.applied = el('div', 'sh-applied');
        side.appendChild(this.applied);
        const bars = el('div', 'sh-bars');
        this.safety = this.bar(S.safetybar, S.safetybar_help, 'safety');
        bars.appendChild(this.safety.node);
        side.appendChild(bars);
        main.appendChild(side);
        wrap.appendChild(main);

        const foot = el('div', 'sh-play-foot');
        this.studybtn = button('sh-btn sh-btn-ghost', S.study_next, 'play', () => this.studyStep());
        this.studybtn.hidden = this.mode !== 'study';
        const last = index === this.data.scenes.length - 1;
        this.nextbtn = button('sh-btn sh-btn-primary', last ? S.finish : S.nextscene, last ? 'flag' : 'next', () => {
            if (last) {
                this.finish();
            } else {
                this.show(index + 1);
            }
        }, {after: !last});
        foot.append(this.studybtn, this.nextbtn);
        wrap.appendChild(foot);
        this.stage.replaceChildren(wrap);

        // A tap on the picture drops the label picked with a tap or the keyboard.
        this.figure.addEventListener('click', (e) => {
            if (this.picked && !e.target.closest('.sh-spot, .sh-labelbox')) {
                const row = this.picked.row;
                if (row !== 'hazard') {
                    this.dropOn(this.active(), this.picked);
                }
            }
        });
        this.refresh();
        this.say(scene.title);
    }

    /**
     * A bar from 0 to 100.
     *
     * @param {string} label
     * @param {string} help
     * @param {string} kind
     * @returns {object} {node, set(percent)}
     */
    bar(label, help, kind) {
        const node = el('div', 'sh-meter sh-meter-' + kind, {title: help});
        const head = el('div', 'sh-meter-head');
        const value = el('span', 'sh-meter-value');
        head.append(el('span', '', {text: label}), value);
        const track = el('div', 'sh-meter-track', {role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100',
            'aria-label': label});
        const fill = el('div', 'sh-meter-fill');
        track.appendChild(fill);
        node.append(head, track);
        return {node, set: (p) => {
            const v = Math.max(0, Math.min(100, Math.round(p)));
            fill.style.width = v + '%';
            value.textContent = v + '%';
            track.setAttribute('aria-valuenow', String(v));
            node.dataset.band = ['red', 'amber', 'green'][(v >= 40 ? 1 : 0) + (v >= 70 ? 1 : 0)];
        }};
    }

    /**
     * The 5 × 5 risk matrix with a dot for the hazard.
     *
     * @returns {HTMLElement}
     */
    buildMatrix() {
        const grid = el('div', 'sh-matrix', {'aria-hidden': 'true', title: S.matrix});
        for (let c = 5; c >= 1; c--) {
            for (let l = 1; l <= 5; l++) {
                grid.appendChild(el('span', 'sh-cell sh-cell-' + rating(l, c), {'data-l': String(l), 'data-c': String(c)}));
            }
        }
        this.dot = el('span', 'sh-matrix-dot');
        grid.appendChild(this.dot);
        return grid;
    }

    /**
     * A numbered spot on the picture, where a hazard label goes.
     *
     * @param {object} spot
     * @returns {HTMLElement}
     */
    spotNode(spot) {
        const node = el('button', 'sh-spot', {type: 'button', 'data-spot': String(spot.id),
            'aria-label': fmt(S.spot, spot.number)});
        node.style.left = spot.x + '%';
        node.style.top = spot.y + '%';
        node.append(el('span', 'sh-spot-num', {text: String(spot.number)}), el('span', 'sh-spot-name', {dir: 'auto'}));
        node.addEventListener('click', (e) => {
            e.stopPropagation();
            if (this.picked) {
                this.dropOn(spot, this.picked);
            } else if (spot.found) {
                this.focusHazard(spot);
            }
        });
        this.spotnodes.set(spot.id, node);
        return node;
    }

    /**
     * The hazard being worked on: in Study the one shown, otherwise the first found hazard not finished.
     *
     * @returns {object|null}
     */
    active() {
        const spots = this.scene.spots;
        if (this.focus && spots.includes(this.focus) && (this.mode === 'study' || !this.focus.complete)) {
            return this.focus;
        }
        if (this.mode === 'study') {
            return spots[Math.min(this.studyHazard || 0, spots.length - 1)];
        }
        if (spots.some((s) => !s.found)) {
            return null;
        }
        return spots.find((s) => !s.complete) || null;
    }

    /**
     * Shows a found hazard's gauges (Study: makes it the hazard shown).
     *
     * @param {object} spot
     */
    focusHazard(spot) {
        this.focus = spot;
        if (this.mode === 'study') {
            this.studyHazard = this.scene.spots.indexOf(spot);
            this.studystep = 0;
        }
        this.refresh();
        if (spot.voice && spot.voice.length) {
            this.voice.play(spot.voice);
        }
    }

    /**
     * The row the learner works on now.
     *
     * @returns {string|null} hazard, eliminate, reduce, ppe or null when the scene is finished
     */
    currentRow() {
        if (this.mode === 'study') {
            return null;
        }
        if (this.scene.spots.some((s) => !s.found)) {
            return 'hazard';
        }
        const h = this.active();
        return h ? KINDS.find((k) => !h.done[k]) || null : null;
    }

    /**
     * Draws the spots, the rows of labels, the gauges and the buttons from the scene's state.
     */
    refresh() {
        const scene = this.scene;
        const row = this.currentRow();
        const h = this.active();
        const study = this.mode === 'study';
        // Spots.
        scene.spots.forEach((s) => {
            const node = this.spotnodes.get(s.id);
            const shown = study || s.found;
            node.classList.toggle('is-found', !!shown);
            node.classList.toggle('is-complete', !!s.complete && !study);
            node.classList.toggle('is-active', h === s && !!h);
            node.classList.toggle('is-target', (row === 'hazard' && !s.found) || (!!row && row !== 'hazard' && h === s));
            node.classList.toggle('is-left', s.x > 62);
            node.querySelector('.sh-spot-name').textContent = shown ? (s.name || '') : '';
            node.setAttribute('aria-label', shown ? fmt(S.spot_found, {number: s.number, name: s.name || ''})
                : fmt(S.spot, s.number));
        });
        // Rows.
        ROWS.forEach((r) => {
            const {node, chips} = this.rows[r];
            const state = this.rowState(r, row, h);
            node.dataset.state = state;
            chips.replaceChildren();
            if (r === 'hazard') {
                if (state === 'done') {
                    chips.appendChild(el('span', 'sh-lrow-wait', {text: S.rowdone}));
                } else {
                    scene.chips.filter((c) => !c.placed).forEach((c) => chips.appendChild(this.chip(c, 'hazard', state === 'now')));
                }
                return;
            }
            if (!h) {
                chips.appendChild(el('span', 'sh-lrow-wait', {text: S.rowwait_hazard}));
                return;
            }
            const labels = h.rows[r] || [];
            if (state === 'done') {
                chips.appendChild(el('span', 'sh-lrow-wait', {text: h.now && h.now.eliminated && r !== 'eliminate'
                    ? S.rowdone_eliminated : S.rowdone}));
            }
            labels.filter((c) => study || !c.chosen).forEach((c) => {
                if (state !== 'done' || study) {
                    chips.appendChild(this.chip(c, r, state === 'now' || study));
                }
            });
            if (state === 'wait') {
                chips.replaceChildren(el('span', 'sh-lrow-wait', {text: S.rowwait_controls}));
            }
            if (this.mode === 'test' && state === 'now' && r !== 'eliminate') {
                chips.appendChild(button('sh-btn sh-btn-sm sh-btn-done', S.rowdone_button, 'check', () => this.doneRow(h, r)));
            }
        });
        // Hazard tabs.
        this.tabs.replaceChildren(...scene.spots.map((s) => {
            const tab = el('button', 'sh-hztab' + (s === h ? ' is-active' : '') + (s.complete && !study ? ' is-complete' : ''),
                {type: 'button', role: 'tab', 'aria-selected': s === h ? 'true' : 'false',
                    disabled: !(study || s.found) ? true : null, text: String(s.number)});
            tab.addEventListener('click', () => this.focusHazard(s));
            return tab;
        }));
        this.activeName.textContent = h ? fmt(S.hazard_n, {number: h.number, name: h.name || ''}) : S.rowwait_hazard;
        this.showRisk(h, false);
        this.showApplied(h);
        // Safety bar: the share of the starting risk removed so far in this scene (Practice and Study).
        this.safety.node.hidden = this.mode === 'test';
        this.safety.set(this.sceneSafety());
        this.studybtn.hidden = !study;
        this.nextbtn.disabled = !study && !scene.complete;
        if (study) {
            this.studybtn.disabled = !h || this.studyDone(h);
        }
    }

    /**
     * A row's state: now (the row to work on), done, wait (later) or off (nothing to do).
     *
     * @param {string} r the row
     * @param {string|null} row the current row
     * @param {object|null} h the active hazard
     * @returns {string}
     */
    rowState(r, row, h) {
        if (this.mode === 'study') {
            return r === 'hazard' ? 'done' : 'study';
        }
        if (r === 'hazard') {
            return row === 'hazard' ? 'now' : 'done';
        }
        if (!h) {
            return 'wait';
        }
        if (h.done[r]) {
            return 'done';
        }
        return row === r ? 'now' : 'wait';
    }

    /**
     * A draggable label.
     *
     * @param {object} c the label {id, text, ...}
     * @param {string} row
     * @param {boolean} live whether it can be dragged now
     * @returns {HTMLElement}
     */
    chip(c, row, live) {
        const study = this.mode === 'study';
        let cls = 'sh-chip sh-chip-' + row;
        if (study && row !== 'hazard') {
            cls += c.correct ? ' is-right' : ' is-wrongans';
        }
        if (this.pending && this.pending.has(row + ':' + c.id)) {
            cls += ' is-checking';
        }
        const node = el('button', cls, {type: 'button', dir: 'auto', disabled: !live || study ? true : null,
            'data-label': row + ':' + c.id,
            'aria-pressed': this.picked && this.picked.id === c.id ? 'true' : 'false'});
        node.appendChild(el('span', 'sh-chip-text', {text: c.text}));
        if (study && row !== 'hazard') {
            node.appendChild(el('span', 'sh-chip-mark', {text: c.correct ? '✓' : '✗', 'aria-hidden': 'true'}));
            node.title = (c.correct ? S.right : S.wrong) + (c.reason ? ': ' + c.reason : '');
            node.setAttribute('aria-label', c.text + '. ' + node.title);
            node.disabled = false;
            node.addEventListener('click', () => this.feedbackCard({marked: 1, correct: c.correct, reason: c.reason,
                level: c.level, dl: c.dl, dc: c.dc, eliminates: c.eliminates}, c.text, row));
            return node;
        }
        if (!live) {
            return node;
        }
        node.addEventListener('click', () => {
            // Tap or keyboard: pick it, then tap the spot (hazards) or the picture (controls).
            if (this.picked && this.picked.id === c.id && this.picked.row === row) {
                this.picked = null;
            } else {
                this.picked = {id: c.id, row, text: c.text};
                Sound.play('pickup');
                this.say(fmt(S.picked, c.text));
                if (row !== 'hazard') {
                    this.dropOn(this.active(), this.picked);
                    return;
                }
            }
            this.box.querySelectorAll('.sh-chip').forEach((n) => n.setAttribute('aria-pressed', 'false'));
            node.setAttribute('aria-pressed', this.picked ? 'true' : 'false');
            this.figure.classList.toggle('is-picking', !!this.picked);
        });
        node.addEventListener('pointerdown', (e) => this.drag(e, node, {id: c.id, row, text: c.text}));
        return node;
    }

    /**
     * Drags a label with the mouse, a finger or a pen; dropping it on a spot (hazards) or the picture (controls).
     *
     * @param {PointerEvent} e
     * @param {HTMLElement} node
     * @param {object} label
     */
    drag(e, node, label) {
        if (e.button > 0) {
            return;
        }
        const sx = e.clientX;
        const sy = e.clientY;
        let ghost = null;
        let over = null;
        const target = (x, y) => {
            const under = document.elementFromPoint(x, y);
            if (!under) {
                return null;
            }
            if (label.row === 'hazard') {
                const spot = under.closest('.sh-spot');
                return spot && this.figure.contains(spot) ? spot : null;
            }
            // A control goes on the hazard being worked on: dropped anywhere on the picture, its spot, or the
            // label box (moved well away from where it started).
            return this.figure.contains(under) && (!under.closest('.sh-labelbox') || Math.hypot(x - sx, y - sy) > 60)
                ? this.figure : null;
        };
        const move = (ev) => {
            if (!ghost) {
                if (Math.hypot(ev.clientX - sx, ev.clientY - sy) < 6) {
                    return;
                }
                ghost = node.cloneNode(true);
                ghost.classList.add('sh-chip-ghost');
                ghost.style.width = node.offsetWidth + 'px';
                (document.fullscreenElement || document.body).appendChild(ghost);
                node.classList.add('is-dragging');
                Sound.play('pickup');
            }
            ghost.style.left = ev.clientX + 'px';
            ghost.style.top = ev.clientY + 'px';
            const t = target(ev.clientX, ev.clientY);
            if (t !== over) {
                over?.classList.remove('is-over');
                over = t;
                over?.classList.add('is-over');
            }
        };
        const up = (ev) => {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            document.removeEventListener('pointercancel', up);
            if (!ghost) {
                return;
            }
            ghost.remove();
            node.classList.remove('is-dragging');
            over?.classList.remove('is-over');
            const t = ev.type === 'pointerup' ? target(ev.clientX, ev.clientY) : null;
            // A click after a drag must not also pick the label.
            node.addEventListener('click', (c) => c.stopImmediatePropagation(), {once: true, capture: true});
            if (!t) {
                return;
            }
            if (label.row === 'hazard') {
                const spot = this.scene.spots.find((s) => String(s.id) === t.dataset.spot);
                this.dropOn(spot, label);
            } else {
                this.dropOn(this.active(), label);
            }
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
        document.addEventListener('pointercancel', up);
    }

    /**
     * Drops a label on a spot (hazard row) or the active hazard (control rows), and shows what happened.
     *
     * @param {object|null} spot
     * @param {object} label {id, row, text}
     */
    dropOn(spot, label) {
        this.picked = null;
        this.figure.classList.remove('is-picking');
        if (!spot || this.mode === 'study' || (label.row === 'hazard' && spot.found)) {
            return;
        }
        const key = label.row + ':' + label.id;
        this.pending = this.pending || new Set();
        if (this.pending.has(key)) {
            return;
        }
        // Shown at once, before the marking comes back from the server: the label is "checking", so a click is never
        // lost or looks ignored. Labels dropped quickly one after another are marked in order.
        this.pending.add(key);
        const node = this.box.querySelector('[data-label="' + key + '"]');
        node?.classList.add('is-checking');
        const scene = this.scene;
        this.queue = (this.queue || Promise.resolve()).then(() => this.mark(scene, spot, label)).catch((err) => this.error(err))
            .finally(() => {
            this.pending.delete(key);
            this.box.querySelector('[data-label="' + key + '"]')?.classList.remove('is-checking');
        });
    }

    /**
     * Sends one dropped label and shows the result.
     *
     * @param {object} scene the scene it was dropped in
     * @param {object} spot
     * @param {object} label
     */
    async mark(scene, spot, label) {
        if (scene !== this.scene || (label.row === 'hazard' && spot.found)) {
            return;
        }
        if (label.row !== 'hazard') {
            // Labels clicked quickly one after another: one that a label before it has made unnecessary (its row is
            // finished, or it is already chosen) is not sent.
            const c = (spot.rows[label.row] || []).find((x) => x.id === label.id);
            if ((spot.done && Number(spot.done[label.row])) || (c && Number(c.chosen)) || spot.complete) {
                return;
            }
        }
        this.busy = true;
        const before = spot.now ? {...spot.now} : {likelihood: spot.likelihood, consequence: spot.consequence};
        let result;
        try {
            result = await Ajax.call([{methodname: 'mod_aisafetyhazards_drop_label', args: {attemptid: this.data.attemptid,
                sceneid: scene.sceneid, kind: label.row, hazardid: spot.id, itemid: label.id}}])[0];
        } catch (err) {
            this.busy = false;
            if (['alreadyplaced', 'rowsinorder', 'findhazardfirst'].includes(err && err.errorcode)) {
                // Not an error: the label cannot go there now. Say why, without a dialogue.
                this.feedback.replaceChildren(el('p', 'sh-fb-test', {text: err.message}));
                return;
            }
            this.error(err);
            return;
        }
        this.busy = false;
        if (scene === this.scene) {
            this.apply(spot, label, result, before);
        }
    }

    /**
     * Test: ends the reduce or PPE row of a hazard.
     *
     * @param {object} spot
     * @param {string} row
     */
    doneRow(spot, row) {
        const scene = this.scene;
        // After any label still being marked, so the row ends with every label the learner chose.
        this.queue = (this.queue || Promise.resolve()).then(() => this.endRow(scene, spot, row)).catch((err) => this.error(err));
    }

    /**
     * Sends "Done with this row" and shows the result.
     *
     * @param {object} scene
     * @param {object} spot
     * @param {string} row
     */
    async endRow(scene, spot, row) {
        if (scene !== this.scene || spot.done[row]) {
            return;
        }
        this.busy = true;
        try {
            const result = await Ajax.call([{methodname: 'mod_aisafetyhazards_done_row', args: {attemptid: this.data.attemptid,
                sceneid: scene.sceneid, hazardid: spot.id, kind: row}}])[0];
            this.busy = false;
            this.apply(spot, null, result, null);
        } catch (err) {
            this.busy = false;
            this.error(err);
        }
    }

    /**
     * Updates the scene from a marked label.
     *
     * @param {object} spot
     * @param {object|null} label
     * @param {object} result
     * @param {object|null} before the hazard's risk before the label
     */
    apply(spot, label, result, before) {
        spot.found = !!result.found;
        spot.done = result.done;
        spot.complete = !!result.complete;
        spot.name = result.name || spot.name;
        spot.now = result.now || spot.now;
        if (label) {
            this.markLabel(spot, label, result);
            this.labelFeedback(spot, label, result);
        }
        if (this.focus && this.focus.complete) {
            this.focus = null;
        }
        this.refresh();
        const now = result.now;
        if (now && before && (before.likelihood !== now.likelihood || before.consequence !== now.consequence || now.eliminated)) {
            this.showRisk(spot, true, before);
        }
        if (result.scenecomplete) {
            this.scene.complete = 1;
            this.dots.children[this.index].classList.add('is-done');
            this.sceneDone();
        }
    }

    /**
     * Records a dropped label on the scene data: a hazard label is used up (in Practice only when right), a control
     * label is chosen with its marking (Practice) or without (Test).
     *
     * @param {object} spot
     * @param {object} label
     * @param {object} result
     */
    markLabel(spot, label, result) {
        const right = !!result.correct;
        if (label.row === 'hazard') {
            const chip = this.scene.chips.find((c) => c.id === label.id);
            if (chip && (right || !result.marked)) {
                // Test: the label stays where it was dropped; Practice: only a right one stays.
                chip.placed = 1;
            }
            return;
        }
        const c = (spot.rows[label.row] || []).find((x) => x.id === label.id);
        if (!c) {
            return;
        }
        c.chosen = 1;
        if (right || !result.marked) {
            Object.assign(c, {correct: result.correct, reason: result.reason, level: result.level, dl: result.dl,
                dc: result.dc, eliminates: result.eliminates});
        } else {
            // A wrong label is crossed out in Practice: it cannot be chosen again.
            c.correct = 0;
            c.reason = result.reason;
        }
    }

    /**
     * The sound, card and voice after a label.
     *
     * @param {object} spot
     * @param {object} label
     * @param {object} result
     */
    labelFeedback(spot, label, result) {
        const right = !!result.correct;
        if (!result.marked) {
            Sound.play('drop');
            this.feedback.replaceChildren(el('p', 'sh-fb-test', {text: fmt(S.fb_testsaved, label.text)}));
        } else {
            Sound.play(right ? 'correct' : 'wrong');
            this.feedbackCard(result, label.text, label.row);
            if (!right) {
                const node = label.row === 'hazard' ? this.spotnodes.get(spot.id) : this.figure;
                node.classList.remove('is-shake');
                void node.offsetWidth;
                node.classList.add('is-shake');
            }
        }
        if (result.voice && result.voice.length) {
            this.voice.play(result.voice);
        }
    }

    /**
     * Shows the risk of a hazard on the gauges and the matrix.
     *
     * @param {object|null} h
     * @param {boolean} animate
     * @param {object|null} before
     */
    showRisk(h, animate, before = null) {
        this.gauges.classList.toggle('is-idle', !h);
        if (!h) {
            this.gL.set(3);
            this.gC.set(3);
            this.ratingNode.replaceChildren();
            this.dot.hidden = true;
            return;
        }
        const now = this.mode === 'test' || !h.now ? {likelihood: h.likelihood, consequence: h.consequence, eliminated: 0,
            rating: h.rating} : h.now;
        if (this.mode === 'study' && this.studyHazard === this.scene.spots.indexOf(h)) {
            Object.assign(now, this.studyRisk(h));
        }
        const l = now.eliminated ? 1 : now.likelihood;
        const c = now.eliminated ? 1 : now.consequence;
        this.gL.set(l, animate && before && before.likelihood !== l);
        this.gC.set(c, animate && before && before.consequence !== c);
        const rate = now.eliminated ? 'eliminated' : rating(now.likelihood, now.consequence);
        const start = rating(h.likelihood, h.consequence);
        this.ratingNode.replaceChildren(el('span', 'sh-mini', {text: S.riskrating}),
            el('span', 'sh-rating sh-rating-' + start + (rate !== start ? ' is-was' : ''), {text: S['rating_' + start]}));
        if (rate !== start) {
            this.ratingNode.append(icon('next'), el('span', 'sh-rating sh-rating-' + rate, {text: S['rating_' + rate]}));
        }
        this.dot.hidden = false;
        this.dot.classList.toggle('is-gone', !!now.eliminated);
        this.dot.style.left = ((l - 0.5) * 20) + '%';
        this.dot.style.top = ((5 - c + 0.5) * 20) + '%';
    }

    /**
     * Lists the right controls applied to a hazard, with their level and effect.
     *
     * @param {object|null} h
     */
    showApplied(h) {
        this.applied.replaceChildren();
        if (!h || this.mode === 'test') {
            return;
        }
        this.applied.appendChild(el('h4', 'sh-applied-title', {text: S.controls_applied}));
        const list = el('ul', 'sh-applied-list');
        let shown = KINDS.flatMap((k) => (h.rows[k] || []).filter((c) => c.chosen && c.correct).map((c) => ({...c, kind: k})));
        if (this.mode === 'study') {
            shown = this.studyShown(h);
        }
        shown.forEach((c) => {
            const li = el('li', 'sh-applied-item sh-chip-' + c.kind);
            li.append(icon(c.kind), el('span', '', {dir: 'auto', text: c.text}),
                el('span', 'sh-tag', {text: this.effect(c)}));
            list.appendChild(li);
        });
        if (!shown.length) {
            list.appendChild(el('li', 'sh-mini', {text: S.none_yet}));
        }
        this.applied.appendChild(list);
    }

    /**
     * A control's level and effect in words: "Engineering · likelihood −3".
     *
     * @param {object} c
     * @returns {string}
     */
    effect(c) {
        const parts = [];
        if ((c.kind === 'eliminate' || c.level === 'elimination') && !Number(c.eliminates)) {
            // A right answer that says why the hazard cannot reasonably be eliminated.
            return S.fb_cannot;
        }
        if (c.level) {
            parts.push(S['level_' + c.level] || c.level);
        }
        if (Number(c.eliminates)) {
            parts.push(S.fb_removes);
        } else {
            if (Number(c.dl)) {
                parts.push(fmt(S.fb_lowers_l, c.dl));
            }
            if (Number(c.dc)) {
                parts.push(fmt(S.fb_lowers_c, c.dc));
            }
        }
        return parts.join(' · ');
    }

    /**
     * The feedback card after a marked label (and when a label is tapped in Study).
     *
     * @param {object} result
     * @param {string} text
     * @param {string} row
     */
    feedbackCard(result, text, row) {
        const right = !!result.correct;
        const card = el('div', 'sh-fb ' + (right ? 'is-right' : 'is-wrong'), {role: 'status'});
        const head = el('div', 'sh-fb-head');
        head.append(icon(right ? 'check' : 'cross'), el('strong', '', {text: right ? S.fb_right : S.fb_wrong}),
            el('span', 'sh-fb-label', {dir: 'auto', text: text}));
        card.appendChild(head);
        if (right && row !== 'hazard') {
            const eff = this.effect(result);
            card.appendChild(el('p', 'sh-fb-effect', {text: eff || S.fb_nochange}));
        }
        if (right && row === 'hazard') {
            card.appendChild(el('p', 'sh-fb-effect', {text: S.fb_hazardfound}));
        }
        if (result.reason) {
            card.appendChild(el('p', 'sh-fb-reason', {dir: 'auto', text: result.reason}));
        }
        this.feedback.replaceChildren(card);
        this.say((right ? S.fb_right : S.fb_wrong) + '. ' + (result.reason || ''));
    }

    /**
     * The share of the scene's starting risk removed so far.
     *
     * @returns {number} percent
     */
    sceneSafety() {
        let start = 0;
        let removed = 0;
        this.scene.spots.forEach((s) => {
            const st = s.likelihood * s.consequence;
            let now = s.now ? riskScore(s.now) : st;
            if (this.mode === 'study') {
                const r = this.studyRisk(s);
                now = r.eliminated ? 0 : r.likelihood * r.consequence;
            }
            start += st - 1;
            removed += Math.min(st - 1, st - now);
        });
        return start > 0 ? 100 * removed / start : 0;
    }

    /**
     * The scene is finished: the risk of each hazard, then Next.
     */
    sceneDone() {
        const test = this.mode === 'test';
        const card = el('div', 'sh-scenedone');
        card.appendChild(el('h4', '', {text: test ? S.scenedone_test : S.scenedone}));
        if (!test) {
            const list = el('ul', 'sh-scenedone-list');
            this.scene.spots.forEach((s) => {
                const end = s.now ? riskName(s.now) : s.rating;
                const li = el('li');
                li.append(el('span', 'sh-scenedone-name', {dir: 'auto', text: s.name}),
                    el('span', 'sh-rating sh-rating-' + s.rating + ' is-was', {text: S['rating_' + s.rating]}), icon('next'),
                    el('span', 'sh-rating sh-rating-' + end, {text: S['rating_' + end]}));
                list.appendChild(li);
            });
            card.appendChild(list);
            if (!REDUCED) {
                confetti(this.figure, 60);
            }
        }
        Sound.play('slide');
        this.feedback.replaceChildren(card);
        this.nextbtn.disabled = false;
        this.nextbtn.focus({preventScroll: true});
    }

    /**
     * Study: the right controls of a hazard in playing order.
     *
     * @param {object} h
     * @returns {object[]}
     */
    studySteps(h) {
        const steps = [];
        for (const k of KINDS) {
            for (const c of h.rows[k] || []) {
                if (c.correct) {
                    steps.push({...c, kind: k});
                }
            }
            if (k === 'eliminate' && steps.some((c) => Number(c.eliminates))) {
                break;
            }
        }
        return steps;
    }

    /**
     * Study: the controls shown so far for a hazard.
     *
     * @param {object} h
     * @returns {object[]}
     */
    studyShown(h) {
        const index = this.scene.spots.indexOf(h);
        const steps = this.studySteps(h);
        if (index < (this.studyHazard || 0)) {
            return steps;
        }
        return index === (this.studyHazard || 0) ? steps.slice(0, this.studystep) : [];
    }

    /**
     * Study: whether every right control of the hazard has been shown.
     *
     * @param {object} h
     * @returns {boolean}
     */
    studyDone(h) {
        return this.studyShown(h).length >= this.studySteps(h).length;
    }

    /**
     * Study: a hazard's risk with the controls shown so far.
     *
     * @param {object} h
     * @returns {object} {likelihood, consequence, eliminated}
     */
    studyRisk(h) {
        let l = h.likelihood;
        let c = h.consequence;
        let gone = false;
        this.studyShown(h).forEach((x) => {
            if (Number(x.eliminates)) {
                gone = true;
            }
            l -= Number(x.dl) || 0;
            c -= Number(x.dc) || 0;
        });
        return {likelihood: Math.max(1, l), consequence: Math.max(1, c), eliminated: gone ? 1 : 0};
    }

    /**
     * Study: shows the next right control (then the next hazard).
     */
    studyStep() {
        const h = this.active();
        if (!h) {
            return;
        }
        const before = this.studyRisk(h);
        const steps = this.studySteps(h);
        if (this.studystep < steps.length) {
            const c = steps[this.studystep];
            this.studystep++;
            Sound.play('drop');
            this.feedbackCard({marked: 1, correct: 1, reason: c.reason, level: c.level, dl: c.dl, dc: c.dc,
                eliminates: c.eliminates}, c.text, c.kind);
            if (c.voice && c.voice.length) {
                this.voice.play(c.voice);
            }
        }
        this.refresh();
        this.showRisk(h, true, before);
        if (this.studyDone(h)) {
            const index = this.scene.spots.indexOf(h);
            if (index < this.scene.spots.length - 1) {
                // On to the next hazard.
                this.studybtn.disabled = false;
                this.studybtn.querySelector('span').textContent = S.study_next;
                this.studybtn.onclick = null;
                window.setTimeout(() => {
                    this.studyHazard = index + 1;
                    this.studystep = 0;
                    this.focus = null;
                    this.refresh();
                }, REDUCED ? 0 : 1600);
            }
        }
    }

    /**
     * Finishes the attempt and shows the results.
     */
    async finish() {
        this.voice.stop();
        let summary;
        try {
            const result = await Ajax.call([{methodname: 'mod_aisafetyhazards_finish_attempt',
                args: {attemptid: this.data.attemptid}}])[0];
            summary = JSON.parse(result.data);
        } catch (err) {
            this.error(err);
            return;
        }
        this.results(summary);
    }

    /**
     * The results: score, safety bar and each scene's hazards from start to end.
     *
     * @param {object} sum
     */
    results(sum) {
        const wrap = el('div', 'sh-results');
        wrap.appendChild(el('h3', 'sh-results-title', {text: S.result_title}));
        if (sum.mode !== 'study') {
            const top = el('div', 'sh-results-top');
            const score = this.bar(S.result_score, '', 'score');
            score.set(sum.score);
            const safety = this.bar(S.result_safety, S.safetybar_help, 'safety');
            safety.set(sum.safety);
            top.append(score.node, safety.node);
            wrap.appendChild(top);
            wrap.appendChild(el('p', 'sh-mini', {text: fmt(S.result_right, sum.right) + ' · ' + fmt(S.result_wrong, sum.wrong)}));
            if (sum.passmark) {
                wrap.appendChild(el('p', 'sh-passline ' + (sum.passed ? 'is-pass' : 'is-fail'),
                    {text: (sum.passed ? S.result_passed : S.result_notpassed) + ' ' + fmt(S.result_passmark, sum.passmark)}));
            }
        }
        sum.recap.forEach((r) => {
            const card = el('section', 'sh-recap');
            const head = el('div', 'sh-recap-head');
            if (r.image) {
                head.appendChild(el('img', '', {src: r.image, alt: ''}));
            }
            head.appendChild(el('h4', '', {dir: 'auto', text: r.number + '. ' + r.title}));
            card.appendChild(head);
            r.hazards.forEach((h) => {
                const row = el('div', 'sh-recap-hazard' + (h.perfect && sum.mode !== 'study' ? ' is-perfect' : ''));
                const line = el('div', 'sh-recap-line');
                line.append(el('strong', '', {dir: 'auto', text: h.name}),
                    el('span', 'sh-rating sh-rating-' + h.start + ' is-was', {text: S['rating_' + h.start]}), icon('next'),
                    el('span', 'sh-rating sh-rating-' + h.end, {text: S['rating_' + h.end]}));
                if (h.perfect && sum.mode !== 'study') {
                    line.appendChild(el('span', 'sh-tag sh-tag-ok', {text: S.result_perfect}));
                }
                row.appendChild(line);
                if (h.labelled) {
                    row.appendChild(el('p', 'sh-recap-sub is-wrong', {dir: 'auto', text: fmt(S.result_labelled, h.labelled)}));
                }
                const list = (title, items, cls) => {
                    if (!items.length) {
                        return;
                    }
                    row.appendChild(el('p', 'sh-recap-sub ' + cls, {text: title}));
                    const ul = el('ul', 'sh-recap-list');
                    items.forEach((i) => {
                        const li = el('li', 'sh-chip-' + i.kind);
                        li.append(icon(i.kind), el('span', '', {dir: 'auto', text: i.text}));
                        if (i.reason) {
                            li.appendChild(el('span', 'sh-recap-why', {dir: 'auto', text: i.reason}));
                        }
                        ul.appendChild(li);
                    });
                    row.appendChild(ul);
                };
                list(S.result_missed, h.missed, 'is-missed');
                list(S.result_wrongchosen, h.wrong, 'is-wrong');
                card.appendChild(row);
            });
            wrap.appendChild(card);
        });
        const actions = el('div', 'sh-results-actions');
        if (sum.next) {
            actions.appendChild(button('sh-btn sh-btn-primary', S['next_' + sum.next], 'next', () => this.start(sum.next),
                {after: true}));
        }
        if (sum.canretake) {
            actions.appendChild(button('sh-btn sh-btn-ghost', S.tryagain, 'reset', () => this.start(sum.mode)));
        }
        if (sum.attemptsleft >= 0) {
            actions.appendChild(el('span', 'sh-mini', {text: fmt(S.attemptsleft, sum.attemptsleft)}));
        }
        actions.appendChild(button('sh-btn sh-btn-ghost', S.backtohome, 'exit', () => this.leave()));
        wrap.appendChild(actions);
        this.stage.replaceChildren(wrap);
        if (sum.mode !== 'study' && (sum.passed || (!sum.passmark && sum.score >= 70))) {
            Sound.play('finish');
            if (!REDUCED) {
                confetti(wrap, 140);
            }
        }
        this.say(S.result_title);
        wrap.scrollIntoView({behavior: REDUCED ? 'auto' : 'smooth', block: 'start'});
    }
}

/**
 * Sets up the player.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    S = await loadStrings(KEYS);
    const player = new Player(root);
    root.querySelectorAll('[data-action="start"]').forEach((btn) => {
        btn.addEventListener('click', () => player.start(btn.dataset.mode || ''));
    });
    root.dataset.shReady = '1';
};
