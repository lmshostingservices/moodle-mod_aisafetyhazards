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
    'fb_hazardfound', 'fb_testsaved', 'fb_cannot', 'test_gauges', 'scenedone', 'scenedone_test', 'study_show',
    'study_next_step', 'study_finish', 'study_answer', 'study_answers', 'study_guess_right', 'study_guess_wrong',
    'study_nothazard', 'study_empty', 'study_card_empty',
    'study_wrong', 'study_hazards', 'labelbox', 'drag_help', 'picked', 'dropon',
    'result_title', 'result_score', 'result_safety', 'result_right', 'result_wrong', 'result_passed', 'result_notpassed',
    'result_passmark', 'result_missed', 'result_wrongchosen', 'result_labelled', 'result_perfect', 'result_start', 'result_end',
    'tryagain', 'next_study', 'next_practice', 'next_test', 'attemptsleft', 'eliminated', 'matrix',
    'rating_low', 'rating_medium', 'rating_high', 'rating_extreme', 'rating_eliminated', 'gauge_value',
    'hcard_title', 'hcard_unknown', 'hcard_done', 'hcard_next', 'hcard_back', 'step_hazard', 'step_eliminate', 'step_reduce',
    'step_ppe', 'hint_hazard', 'hint_eliminate', 'hint_reduce_practice', 'hint_ppe_practice', 'hint_reduce_test',
    'hint_ppe_test', 'hint_study_guess', 'hint_study_after', 'intro_close', 'intro_lead', 'intro_then', 'card_now',
    'card_skip', 'step_n',
    'found_of', 'praise_1', 'praise_2', 'praise_3', 'praise_4', 'praise_5', 'streak', 'pop_great', 'pop_cut',
    'pop_saved', 'pop_learned', 'scene_pop', 'scene_pop_cut', 'stars_label', 'hazards',
    'hazards_done', 'hazard_here', 'steps_label', 'fb_saved', 'more', 'less', 'close', 'test_gauges_short', 'risk_title',
    'risk_title_ifnot', 'risk_ifnot', 'q_reduce_backup', 'q_ppe_backup',
    ...['study', 'practice', 'test'].flatMap((m) => ['intro_title_' + m, 'intro_note_' + m]),
    ...ROWS.flatMap((r) => ['q_' + r, 'card_q_' + r, 'intro_step_' + r]),
    ...LIKELIHOOD.map((k) => 'l_' + k), ...CONSEQUENCE.map((k) => 'c_' + k), ...LEVELS.map((k) => 'level_' + k)];

let S = {};

/** Free space kept between Moodle's fixed top bar and the slide. */
const NAVGAP = 16;

/**
 * Where the slide sits when scrolled to: 16px under the theme's fixed top bar (measured, so any theme and Moodle
 * version lines up; 16px from the top when the theme has none).
 *
 * @returns {number}
 */
const navTop = () => {
    const bar = document.querySelector('nav.navbar.fixed-top, .navbar.fixed-top, #page-header.fixed-top');
    const bottom = bar && getComputedStyle(bar).position === 'fixed' ? bar.getBoundingClientRect().bottom : 0;
    return Math.max(0, Math.round(bottom)) + NAVGAP;
};
/** Free space kept under the slide. */
const GAP = 16;
/** The picture's shape (16:10) and its smallest height; the side panel's narrowest and widest. */
const RATIO = 1.6;
const PIC_MIN = 220;
const SIDE_MIN = 340;
const SIDE_WIDE = 460;
const SIDE_MAX = 560;
/** Below this width the picture and the side panel stack (the picture would be smaller than about 500px). */
const STACK = 760;
/** Below this screen height the step cards and the scene description are compact. */
const SHORT = 740;
/** Below this row height the answers and a done hazard's summary are compact. */
const TIGHT = 420;
/** From this row height the risk has its own card beside the picture (its height: 30% of the row, 130 to 200px). */
const RISK_SIDE = 520;
const RISK_MIN = 130;
const RISK_MAX = 200;
/** The space between the hazard card and the risk card. */
const SIDE_GAP = 12;
/** The footer's height on one line. */
const FOOT_H = 64;
/** How long feedback over the step cards stays (paused while the pointer is on it). */
const FB_TIME = 8000;

/**
 * A tick (or a cross) in a circle; drawn with a short animation when it has just appeared.
 *
 * @param {boolean} ok
 * @param {boolean} animate
 * @returns {HTMLElement}
 */
const tickMark = (ok, animate = false) => {
    const node = el('span', 'sh-tick ' + (ok ? 'is-ok' : 'is-no') + (animate && !REDUCED ? ' is-drawn' : ''),
        {'aria-hidden': 'true'});
    const svg = icon(ok ? 'check' : 'cross');
    svg.querySelectorAll('path').forEach((p) => p.setAttribute('pathLength', '1'));
    node.appendChild(svg);
    return node;
};

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
        Sound.setAllowed(!!this.config.sounds);
        this.onFullChange = () => this.syncFull();
        // The Moodle course drawer changes the width without a window resize, so the host is watched too.
        let frame = 0;
        const refit = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => this.fit());
        };
        window.addEventListener('resize', refit);
        if (window.ResizeObserver) {
            new ResizeObserver(refit).observe(this.host);
        }
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(() => this.fit()).catch(() => null);
        }
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
        requestAnimationFrame(() => {
            this.fit();
            if (!full && this.stage) {
                // Back from full screen: the slide comes back up under Moodle's top bar.
                this.align();
            }
        });
    }

    /**
     * Sizes the slide to the screen, so it never goes below the fold, in the page or in full screen.
     *
     * Everything is measured, nothing is guessed: the height left for the picture row is the screen height, less 16px
     * under Moodle's top bar (or the full-screen padding), the scene title, the step cards, the footer and 16px at the
     * bottom. The picture (16:10) takes that height unless the column is too narrow; the side panel may use the whole
     * row and scrolls inside only if its content is taller still. A very wide screen centres the slide instead of
     * stretching the side panel.
     */
    fit() {
        const play = this.stage && this.stage.querySelector('.sh-play');
        if (!play || !play.isConnected) {
            return;
        }
        const left = play.querySelector('.sh-play-left');
        const side = play.querySelector('.sh-side');
        const main = side.parentNode;
        const foot = play.querySelector('.sh-play-foot');
        if (this.layoutMode(play)) {
            // Too narrow for the picture and the panel side by side (tablets, phones, a small window with the course
            // drawer open): one column, and the page scrolls.
            [play, left, side].forEach((n) => n.style.removeProperty('max-width'));
            ['max-height', 'height', 'min-width'].forEach((p) => side.style.removeProperty(p));
            this.host.style.removeProperty('margin-bottom');
            this.cards.classList.toggle('is-narrow', this.cards.firstChild.getBoundingClientRect().width < 175);
            this.placeFeedback();
            this.placeRisk(false);
            return;
        }
        const shellnode = this.host.querySelector('.sh-shell');
        const cs = getComputedStyle(shellnode);
        const full = this.isFull();
        const hostcs = getComputedStyle(this.host);
        const top = full ? parseFloat(hostcs.paddingTop) : navTop();
        this.host.style.scrollMarginTop = navTop() + 'px';
        const bottom = full ? parseFloat(hostcs.paddingBottom) : GAP;
        const gap = parseFloat(getComputedStyle(main).columnGap) || 14;
        const leftgap = parseFloat(getComputedStyle(left).rowGap) || 10;
        const sidemin = this.sideMin || SIDE_MIN;
        side.style.minWidth = sidemin + 'px';
        let row = 0;
        for (let i = 0; i < 4; i++) {
            const shell = shellnode.getBoundingClientRect();
            const m = main.getBoundingClientRect();
            const above = m.top - shell.top;
            const below = foot.getBoundingClientRect().bottom - m.bottom + parseFloat(cs.paddingBottom)
                + parseFloat(cs.borderBottomWidth);
            row = Math.max(PIC_MIN + 100, Math.floor(window.innerHeight - top - bottom - above - below));
            // A tall row: the risk gets its own card under the hazard card, sized to the row; otherwise it sits in
            // the footer. Moving it changes the footer, so the row is measured again.
            if (this.placeRisk(row >= RISK_SIDE)) {
                continue;
            }
            const riskh = this.riskSide ? Math.round(Math.min(RISK_MAX, Math.max(RISK_MIN, row * 0.3))) : 0;
            side.style.setProperty('--sh-riskh', riskh + 'px');
            this.boxH = row - (riskh ? riskh + SIDE_GAP : 0);
            const tcs = getComputedStyle(this.track);
            const track = this.track.getBoundingClientRect().height + parseFloat(tcs.marginTop)
                + parseFloat(tcs.marginBottom);
            const width = play.parentNode.getBoundingClientRect().width;
            const ph = Math.max(PIC_MIN, row - track - leftgap);
            const pw = Math.floor(Math.max(PIC_MIN * RATIO * 0.8, Math.min(ph * RATIO, width - sidemin - gap)));
            left.style.maxWidth = pw + 'px';
            // A hazard's name on its spot never runs off the picture.
            this.figure.style.setProperty('--sh-spotname', Math.max(90, Math.round(pw * 0.36)) + 'px');
            // A wide screen: the side panel stops at SIDE_MAX and the slide is centred.
            const capped = pw + gap + SIDE_MAX;
            const was = play.style.maxWidth;
            play.style.maxWidth = capped < width ? capped + 'px' : '';
            // The side panel always takes the whole row, so the slide keeps one height from answer to answer (the
            // page never jumps); a long list of answers scrolls inside it.
            side.style.height = row + 'px';
            this.rowH = this.boxH;
            // A low row: the answers and a done hazard's steps take the compact form.
            play.classList.toggle('is-tight', this.boxH < TIGHT);
            // With a big button under the answers (Study, Test's Done), a low row drops the tip line.
            if (this.doneBar.firstChild && !this.q.hidden) {
                this.qs.hidden = this.boxH < TIGHT;
            }
            side.style.maxHeight = row + 'px';
            if (play.style.maxWidth === was && Math.abs(main.getBoundingClientRect().top - m.top) < 1) {
                break;
            }
        }
        if (!full) {
            this.roomBelow();
        }
        this.placeFeedback();
        this.cards.classList.toggle('is-narrow', this.cards.firstChild.getBoundingClientRect().width < 175);
        // The footer on one line: on a narrow slide the gauges give way to the matrix and the rating.
        foot.classList.remove('is-tight');
        foot.classList.toggle('is-tight', foot.scrollWidth > foot.clientWidth + 1
            || foot.getBoundingClientRect().height > FOOT_H + 1);
    }

    /**
     * Puts the risk (gauges, matrix, rating) in its own card under the hazard card, or in the footer.
     *
     * @param {boolean} inside
     * @returns {boolean} whether it moved
     */
    placeRisk(inside) {
        if (this.riskSide === inside && this.riskpanel.isConnected) {
            return false;
        }
        this.riskSide = inside;
        this.riskpanel.classList.toggle('is-card', inside);
        if (inside) {
            this.box.parentNode.appendChild(this.riskpanel);
        } else {
            this.progress.after(this.riskpanel);
        }
        return true;
    }

    /**
     * Picks the layout for the screen: stacked (one column), short (compact header and cards) or normal.
     *
     * @param {HTMLElement} play
     * @returns {boolean} whether the slide is stacked
     */
    layoutMode(play) {
        const stacked = window.matchMedia('(max-width: 991.98px)').matches
            || play.parentNode.getBoundingClientRect().width < STACK;
        play.classList.toggle('is-stacked', stacked);
        const short = !stacked && window.innerHeight < SHORT;
        play.classList.toggle('is-short', short);
        this.host.querySelector('.sh-shell').classList.toggle('is-short', short);
        const ctx = play.querySelector('.sh-scene-context');
        const more = play.querySelector('.sh-scene-more');
        if (ctx && more && !ctx.classList.contains('is-open')) {
            more.hidden = ctx.scrollHeight <= ctx.clientHeight + 1;
        }
        return stacked;
    }

    /**
     * Where the feedback shows: under the answers when the hazard card has room for it, otherwise over the bottom of
     * the picture, where it closes by itself after a while (or with ×), so the answers never need scrolling.
     */
    placeFeedback() {
        const stacked = !!this.stage.querySelector('.sh-play.is-stacked');
        this.dock(false);
        if (stacked || !this.feedback.children.length || this.box.classList.contains('is-finished')) {
            return;
        }
        // Docked if, under the answers, it would make the answers (or the box itself) scroll.
        const over = (n) => n && !n.hidden && n.scrollHeight > n.clientHeight + 1;
        if (over(this.box) || over(this.opts)) {
            this.dock(true);
        }
    }

    /**
     * Moves the feedback under the answers or over the bottom of the picture.
     *
     * @param {boolean} docked
     */
    dock(docked) {
        if (this.fbDocked === docked && this.feedback.isConnected) {
            return;
        }
        this.fbDocked = docked;
        this.feedback.classList.toggle('is-docked', docked);
        clearTimeout(this.fbTimer);
        this.feedback.querySelectorAll('.sh-fb-close').forEach((n) => n.remove());
        if (docked) {
            // Over the bottom of the picture, never over the step cards.
            this.figure.querySelector('.sh-pic').appendChild(this.feedback);
            const first = this.feedback.firstElementChild;
            if (first) {
                first.appendChild(iconButton('sh-fb-close', S.close, 'cross', () => this.hideFeedback()));
                this.fbTimer = setTimeout(() => this.hideFeedback(), FB_TIME);
            }
        } else {
            this.box.insertBefore(this.feedback, this.hNext);
        }
    }

    /**
     * Shows feedback (or clears it).
     *
     * @param {...HTMLElement} nodes
     */
    setFeedback(...nodes) {
        clearTimeout(this.fbTimer);
        this.feedback.classList.remove('is-out');
        this.feedback.replaceChildren(...nodes);
        this.fbDocked = null;
        this.placeFeedback();
    }

    /**
     * Hides feedback shown over the step cards.
     */
    hideFeedback() {
        clearTimeout(this.fbTimer);
        if (!this.feedback.children.length) {
            return;
        }
        this.feedback.classList.add('is-out');
        this.fbTimer = setTimeout(() => {
            this.feedback.replaceChildren();
            this.feedback.classList.remove('is-out');
        }, 300);
    }

    /**
     * Makes sure the page can scroll far enough to bring the slide up under Moodle's top bar (a short page cannot),
     * by adding the missing space under the player.
     */
    roomBelow() {
        const doc = document.scrollingElement || document.documentElement;
        const had = parseFloat(this.host.style.marginBottom) || 0;
        const shelltop = this.host.getBoundingClientRect().top + window.scrollY;
        const need = shelltop - navTop() + window.innerHeight;
        const short = need - (doc.scrollHeight - had);
        const add = Math.max(0, Math.ceil(short));
        if (Math.abs(add - had) > 1) {
            this.host.style.marginBottom = add ? add + 'px' : '';
        }
    }

    /**
     * Brings the slide back up under Moodle's top bar if it has moved (a new scene, or the page was scrolled).
     */
    align() {
        if (this.isFull() || this.stage.querySelector('.sh-play.is-stacked')) {
            return;
        }
        const top = this.host.getBoundingClientRect().top;
        const want = navTop();
        if (Math.abs(top - want) > 2) {
            window.scrollBy({top: top - want, behavior: 'auto'});
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
        this.intro();
        this.host.scrollIntoView({behavior: REDUCED ? 'auto' : 'smooth', block: 'start'});
    }

    /**
     * How this mode works, in a few simple steps, before the first scene. Closed with the button, Esc or a tap outside.
     */
    intro() {
        const overlay = el('div', 'sh-intro', {role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'sh-intro-title'});
        const card = el('div', 'sh-intro-card');
        card.appendChild(el('span', 'sh-modebadge sh-modebadge-' + this.mode, {text: S['mode_' + this.mode]}));
        card.appendChild(el('h3', '', {id: 'sh-intro-title', text: S['intro_title_' + this.mode]}));
        card.appendChild(el('p', 'sh-intro-lead', {text: S.intro_lead}));
        // The four steps for each hazard, as a row of icons.
        const list = el('ol', 'sh-intro-steps');
        ROWS.forEach((r, i) => {
            const li = el('li', 'sh-intro-step sh-intro-' + r, {style: '--d:' + (150 + i * 90) + 'ms'});
            const ic = el('span', 'sh-intro-icon');
            ic.appendChild(icon(r));
            li.append(ic, el('span', 'sh-intro-num', {text: String(i + 1)}), el('strong', '', {text: S['step_' + r]}),
                el('span', 'sh-intro-text', {text: S['intro_step_' + r]}));
            list.appendChild(li);
        });
        card.appendChild(list);
        const then = el('p', 'sh-intro-then');
        then.append(icon('next'), el('span', '', {text: S.intro_then}));
        card.appendChild(then);
        const note = el('p', 'sh-intro-note');
        note.append(icon('info'), el('span', '', {text: S['intro_note_' + this.mode]}));
        card.appendChild(note);
        const close = () => {
            overlay.remove();
            document.removeEventListener('keydown', esc);
            const h = this.active();
            if (h && this.mode !== 'study') {
                this.lookHere(h);
            }
            this.box?.querySelector('.sh-opt:not([disabled])')?.focus({preventScroll: true});
        };
        const esc = (e) => {
            if (e.key === 'Escape') {
                close();
            }
        };
        const ok = button('sh-btn sh-btn-primary sh-btn-go', S.intro_close, 'next', close, {after: true});
        card.appendChild(ok);
        overlay.appendChild(card);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                close();
            }
        });
        document.addEventListener('keydown', esc);
        this.host.appendChild(overlay);
        ok.focus({preventScroll: true});
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
        this.studyHazard = 0;
        this.studyReset();
        this.view = null;
        this.hold = null;
        this.tried = new Set();
        this.streak = 0;
        this.misses = this.misses || {};
        this.fresh = null;
        this.doneFor = null;
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
        // Long answers get a wider side panel (fewer lines each), so the panel fits beside the picture.
        const texts = scene.chips.map((c) => c.text).concat(...scene.spots.map((h) => KINDS.flatMap((k) =>
            (h.rows[k] || []).map((c) => c.text))));
        const longest = Math.max(0, ...texts.map((t) => String(t || '').length));
        this.sideMin = Math.round(Math.min(SIDE_WIDE, Math.max(SIDE_MIN, SIDE_MIN + (longest - 40) * 3.5)));
        // What is happening in this step of the task.
        const head = el('div', 'sh-scene-head');
        const titles = el('div', 'sh-scene-titles');
        titles.append(el('span', 'sh-eyebrow', {text: fmt(S.scene_of, {number: scene.number, total: this.data.scenes.length})}),
            el('h3', 'sh-scene-title', {dir: 'auto', text: scene.title}));
        if (scene.context) {
            // What is happening in this step of the task, under the title (kept short to leave room for the picture).
            const ctx = el('p', 'sh-scene-context', {dir: 'auto', text: scene.context});
            titles.appendChild(ctx);
            // Two lines at most, so a long description never pushes the slide below the fold; "More" shows it all.
            const more = el('button', 'sh-scene-more', {type: 'button', 'aria-expanded': 'false', text: S.more, hidden: true});
            more.addEventListener('click', () => {
                const open = ctx.classList.toggle('is-open');
                more.setAttribute('aria-expanded', open ? 'true' : 'false');
                more.textContent = open ? S.less : S.more;
                this.fit();
            });
            titles.appendChild(more);
            requestAnimationFrame(() => {
                more.hidden = ctx.scrollHeight <= ctx.clientHeight + 1;
            });
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
        // The picture with its numbered spots.
        this.figure = el('div', 'sh-figure');
        const pic = el('div', 'sh-pic');
        pic.appendChild(el('img', '', {src: scene.image, alt: '', draggable: 'false'}));
        this.spotnodes = new Map();
        scene.spots.forEach((spot) => pic.appendChild(this.spotNode(spot)));
        this.figure.appendChild(pic);
        // Under the picture: the four steps of the hazard being worked on, as cards that slide to the next hazard.
        const left = el('div', 'sh-play-left');
        left.appendChild(this.figure);
        this.track = el('div', 'sh-steptrack');
        this.set = el('div', 'sh-stepset');
        this.setLabel = el('span', 'sh-stepset-label');
        this.cards = el('ol', 'sh-stepcards', {'aria-label': S.steps_label});
        this.cardsFor = null;
        this.cardnodes = {};
        ROWS.forEach((row, i) => {
            const li = el('li', 'sh-stepcard sh-stepcard-' + row, {'data-row': row});
            const k = el('div', 'sh-stepcard-head');
            k.append(el('span', 'sh-stepcard-icon', {}), el('span', 'sh-stepcard-name', {text: S['step_' + row]}));
            k.firstChild.appendChild(icon(row));
            const tag = el('span', 'sh-stepcard-tag', {text: String(i + 1)});
            k.appendChild(tag);
            const body = el('div', 'sh-stepcard-body', {dir: 'auto'});
            li.append(k, body);
            this.cards.appendChild(li);
            this.cardnodes[row] = {node: li, tag, body};
        });
        this.set.append(this.setLabel, this.cards);
        this.track.appendChild(this.set);
        left.appendChild(this.track);
        main.appendChild(left);

        // Beside the picture: the hazard, the question for the step, its answers, the feedback and the risk.
        const side = el('aside', 'sh-side', {'aria-label': S.activehazard});
        this.box = el('section', 'sh-hcard', {'aria-label': S.labelbox});
        const top = el('div', 'sh-hcard-top');
        const heads = el('div', 'sh-hcard-heads');
        this.hTitle = el('h4', 'sh-hcard-title');
        this.hName = el('span', 'sh-hcard-name', {dir: 'auto'});
        heads.append(this.hTitle, this.hName);
        this.tabs = el('div', 'sh-hztabs', {role: 'tablist'});
        top.append(heads, this.tabs);
        this.q = el('div', 'sh-question', {dir: 'auto'});
        this.qs = el('p', 'sh-question-help', {dir: 'auto'});
        this.opts = el('div', 'sh-options', {role: 'group'});
        this.feedback = el('div', 'sh-feedback', {'aria-live': 'polite'});
        this.fbDocked = false;
        this.feedback.addEventListener('pointerenter', () => clearTimeout(this.fbTimer));
        this.feedback.addEventListener('pointerleave', () => {
            if (this.fbDocked && this.feedback.children.length) {
                this.fbTimer = setTimeout(() => this.hideFeedback(), FB_TIME / 2);
            }
        });
        this.hNext = el('div', 'sh-hcard-next');
        // Test: "Done with this row" stays under the answers, outside their list, so it is always in view.
        this.doneBar = el('div', 'sh-donebar');
        this.box.append(top, this.q, this.qs, this.opts, this.doneBar, this.feedback, this.hNext);
        side.appendChild(this.box);

        // The risk of the hazard shown: gauges, rating, matrix and the risk removed (hidden in a test).
        const risk = el('div', 'sh-riskpanel');
        const gauges = el('div', 'sh-gauges');
        this.gauges = gauges;
        this.gL = gauge(S.likelihood, LIKELIHOOD.map((k) => S['l_' + k]));
        this.gC = gauge(S.consequence, CONSEQUENCE.map((k) => S['c_' + k]));
        gauges.append(this.gL.node, this.gC.node);
        this.matrix = this.buildMatrix();
        this.riskTitle = el('span', 'sh-riskpanel-title');
        risk.append(this.riskTitle, gauges, this.matrix);
        this.ratingNode = el('div', 'sh-riskrow');
        risk.appendChild(this.ratingNode);
        this.safety = this.bar(S.safetybar, S.safetybar_help, 'safety');
        this.applied = el('div', 'sh-applied');
        const lock = el('p', 'sh-riskpanel-test', {hidden: this.mode !== 'test', title: S.test_gauges});
        lock.append(icon('info'), el('span', '', {text: S.test_gauges_short}));
        this.testnote = lock;
        risk.hidden = this.mode === 'test';
        this.riskpanel = risk;
        main.appendChild(side);
        wrap.appendChild(main);

        const foot = el('div', 'sh-play-foot');
        const last = index === this.data.scenes.length - 1;
        this.nextbtn = button('sh-btn sh-btn-primary', last ? S.finish : S.nextscene, last ? 'flag' : 'next', () => {
            if (last) {
                this.finish();
            } else {
                this.show(index + 1);
            }
        }, {after: !last});
        this.progress = el('span', 'sh-play-progress');
        // The footer is the slide's status bar: the hazards, the risk of the one worked on, the risk removed, and Next.
        foot.append(this.progress, risk, this.testnote, this.safety.node, this.nextbtn);
        wrap.appendChild(foot);
        this.stage.replaceChildren(wrap);

        this.refresh();
        if (this.stage.isConnected && index > 0) {
            this.align();
        }
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
            if (spot.found || this.mode === 'study') {
                this.focusHazard(spot);
            }
        });
        this.spotnodes.set(spot.id, node);
        return node;
    }

    /**
     * The hazard being worked on: one at a time, in order (Study: the one being studied). A hazard just finished
     * stays until the learner moves on with "Next: Hazard n".
     *
     * @returns {object|null}
     */
    active() {
        const spots = this.scene.spots;
        if (this.mode === 'study') {
            return spots[Math.min(this.studyHazard || 0, spots.length - 1)];
        }
        if (this.hold && spots.includes(this.hold)) {
            return this.hold;
        }
        return spots.find((s) => !s.complete) || null;
    }

    /**
     * The hazard shown in the card and on the gauges: the active one, or a finished one the learner looks back at.
     *
     * @returns {object|null}
     */
    shown() {
        return this.view && this.scene.spots.includes(this.view) ? this.view : this.active();
    }

    /**
     * Shows a hazard (Study: makes it the hazard studied; otherwise looks back at a finished one).
     *
     * @param {object} spot
     */
    focusHazard(spot) {
        if (this.mode === 'study') {
            if (this.scene.spots.indexOf(spot) !== this.studyHazard) {
                this.studyHazard = this.scene.spots.indexOf(spot);
                this.studyReset();
            }
            this.view = null;
        } else {
            this.view = spot === this.active() ? null : spot;
        }
        this.refresh();
        if (spot.voice && spot.voice.length) {
            this.voice.play(spot.voice);
        }
    }

    /**
     * Moves on to the next hazard: the card slides and the next spot on the picture bounces.
     */
    nextHazard() {
        // The cards of the hazard just finished slide out to the left as the next hazard's cards slide in.
        const old = REDUCED ? null : this.set.cloneNode(true);
        this.view = null;
        if (this.mode === 'study') {
            this.studyHazard = Math.min((this.studyHazard || 0) + 1, this.scene.spots.length - 1);
            this.studyReset();
        } else {
            this.hold = null;
        }
        this.setFeedback();
        this.refresh();
        const h = this.active();
        if (old) {
            old.classList.add('is-leaving');
            old.setAttribute('aria-hidden', 'true');
            this.track.appendChild(old);
            setTimeout(() => old.remove(), 700);
            [this.set, this.box].forEach((n) => {
                n.classList.remove('is-entering');
                void n.offsetWidth;
                n.classList.add('is-entering');
            });
        }
        if (h) {
            this.lookHere(h);
            Sound.play('swoop');
            this.say(fmt(S.hcard_title, {number: h.number, total: this.scene.spots.length}));
        }
        const study = this.mode === 'study' && this.root.querySelector('.sh-btn-study');
        if (study) {
            study.focus({preventScroll: true});
        }
    }

    /**
     * Draws the eye to a spot on the picture: it bounces, sends out rings and says "Hazard n is here" for a moment.
     *
     * @param {object} h
     */
    lookHere(h) {
        const node = this.spotnodes.get(h.id);
        if (!node || REDUCED) {
            return;
        }
        node.classList.remove('is-new');
        void node.offsetWidth;
        node.classList.add('is-new');
        setTimeout(() => Sound.play('ping'), 380);
        this.figure.querySelectorAll('.sh-callout').forEach((n) => n.remove());
        const call = el('span', 'sh-callout' + (h.y < 22 ? ' is-below' : ''), {text: fmt(S.hazard_here, h.number),
            'aria-hidden': 'true'});
        call.style.left = h.x + '%';
        call.style.top = h.y + '%';
        node.parentNode.appendChild(call);
        setTimeout(() => {
            call.remove();
            node.classList.remove('is-new');
        }, 2600);
    }

    /**
     * The step the learner works on now.
     *
     * @returns {string|null} hazard, eliminate, reduce, ppe or null (Study, or the hazard is finished)
     */
    currentRow() {
        const h = this.active();
        if (this.mode === 'study' || !h || h.complete) {
            return null;
        }
        if (!h.found) {
            return 'hazard';
        }
        return KINDS.find((k) => !Number(h.done[k])) || null;
    }

    /**
     * Whether a hazard has been eliminated so far (Practice and Study; a test does not tell).
     *
     * @param {object} h
     * @returns {boolean}
     */
    isEliminated(h) {
        if (this.mode === 'study') {
            return !!this.studyRisk(h).eliminated;
        }
        return this.mode !== 'test' && !!(h.now && Number(h.now.eliminated));
    }

    /**
     * The instruction for a step.
     *
     * @param {string} r
     * @param {object} h
     * @returns {string}
     */
    hint(r, h) {
        if (this.mode === 'study') {
            if (this.studyEmpty(h, r)) {
                return S.study_empty;
            }
            return this.srev ? S.hint_study_after : S.hint_study_guess;
        }
        if (r === 'hazard') {
            return fmt(S.hint_hazard, h.number);
        }
        if (r === 'eliminate') {
            return S.hint_eliminate;
        }
        if (this.mode === 'test') {
            return S['hint_' + r + '_test'];
        }
        const found = (h.rows[r] || []).filter((c) => Number(c.chosen) && Number(c.correct)).length;
        return fmt(S['hint_' + r + '_practice'], {found, total: (h.need && h.need[r]) || found});
    }

    /**
     * Draws the spots, the step cards, the question, the gauges and the buttons from the scene's state.
     */
    refresh() {
        const scene = this.scene;
        const study = this.mode === 'study';
        const cur = this.active();
        const h = this.shown();
        const viewing = !!h && h !== cur;
        let row = null;
        if (h && study) {
            row = this.studyRow(h);
        } else if (h && !viewing) {
            row = this.currentRow();
        }
        // Spots: the one to work on pulses; the ones still to come wait quietly.
        scene.spots.forEach((s) => {
            const node = this.spotnodes.get(s.id);
            const named = study ? this.studyRevealed(s, 'hazard') : s.found;
            node.classList.toggle('is-found', !!named);
            node.classList.toggle('is-complete', !!s.complete && !study);
            node.classList.toggle('is-active', s === h);
            node.classList.toggle('is-target', !study && s === cur && !s.complete);
            node.classList.toggle('is-later', !study && !s.found && s !== cur);
            node.classList.toggle('is-left', s.x > 62);
            node.querySelector('.sh-spot-num').textContent = s.complete && !study ? '✓' : String(s.number);
            // Only the hazard shown carries its name on the picture, so the spots never crowd the image.
            node.querySelector('.sh-spot-name').textContent = named && s === h ? (s.name || '') : '';
            node.title = named ? (s.name || '') : '';
            node.setAttribute('aria-label', named ? fmt(S.spot_found, {number: s.number, name: s.name || ''})
                : fmt(S.spot, s.number));
        });
        // The hazard: its number, its name once known, and the hazard tabs.
        const title = h ? fmt(S.hcard_title, {number: h.number, total: scene.spots.length}) : '';
        this.hTitle.textContent = title;
        this.setLabel.textContent = title;
        this.hName.textContent = h && (study ? this.studyRevealed(h, 'hazard') : h.found) ? (h.name || '') : '';
        this.hName.hidden = !this.hName.textContent;
        this.box.dataset.row = row || 'done';
        this.box.classList.toggle('is-finished', !row);
        // When a hazard is done its box shows the risk, so the gauges make room for it.

        this.drawCards(h, row, viewing);
        this.drawQuestion(h, row);
        this.drawNext(cur, h, viewing, row);
        // Hazard numbers: look back at a finished hazard (Study: go to any hazard).
        this.tabs.replaceChildren(...scene.spots.map((s) => {
            const done = s.complete && !study;
            const tab = el('button', 'sh-hztab' + (s === h ? ' is-active' : '') + (done ? ' is-complete' : ''),
                {type: 'button', role: 'tab', 'aria-selected': s === h ? 'true' : 'false',
                    'aria-label': fmt(S.hcard_title, {number: s.number, total: scene.spots.length}),
                    disabled: !(study || s.complete || s === cur) ? true : null});
            tab.appendChild(done && s !== h ? tickMark(true) : el('span', '', {text: String(s.number)}));
            tab.addEventListener('click', () => this.focusHazard(s));
            return tab;
        }));
        this.riskTitle.textContent = h ? fmt(this.isEliminated(h) ? S.risk_title_ifnot : S.risk_title, h.number) : '';
        this.showRisk(h, false);
        // Safety bar: the share of the starting risk removed so far in this scene (Practice and Study).
        this.safety.node.hidden = this.mode === 'test';
        this.safety.set(this.sceneSafety());
        // Under the slide: a pill for each hazard of the scene, ticked when done.
        const finished = scene.spots.filter((s) => s.complete).length;
        this.progress.setAttribute('aria-label', fmt(S.hazards_done, {done: finished, total: scene.spots.length}));
        this.progress.replaceChildren(el('span', 'sh-play-progress-label', {text: S.hazards, 'aria-hidden': 'true'}),
            ...scene.spots.map((s) => {
                const pill = el('span', 'sh-progpill' + (s.complete && !study ? ' is-done' : '') + (s === cur ? ' is-current' : ''),
                    {'aria-hidden': 'true'});
                pill.appendChild(s.complete && !study ? tickMark(true) : el('span', '', {text: String(s.number)}));
                return pill;
            }));
        this.progress.hidden = study;
        this.nextbtn.disabled = !study && !scene.complete;
        this.fit();
    }

    /**
     * The four step cards under the picture for the hazard shown.
     *
     * @param {object|null} h
     * @param {string|null} row the step worked on now
     * @param {boolean} viewing
     */
    drawCards(h, row, viewing) {
        const same = h && this.cardsFor === h;
        this.cardsFor = h;
        ROWS.forEach((r, i) => {
            const {node, tag, body} = this.cardnodes[r];
            const state = h ? this.rowState(r, row, h, viewing) : 'wait';
            const was = node.dataset.state;
            node.dataset.state = state;
            node.className = 'sh-stepcard sh-stepcard-' + r + ' is-' + state;
            tag.className = 'sh-stepcard-tag';
            tag.replaceChildren();
            if (state === 'done') {
                // A tick that draws itself when the step has just been finished.
                tag.appendChild(tickMark(true, same && was && was !== 'done'));
                tag.classList.add('is-done');
            } else if (state === 'now') {
                tag.textContent = S.card_now;
                tag.classList.add('is-now');
            } else {
                tag.textContent = state === 'skip' ? '–' : String(i + 1);
            }
            body.replaceChildren(...(h ? this.summary(r, h, state) : []));
        });
    }

    /**
     * What a step card says: the answers chosen as pills, the question while it is worked on, or nothing yet.
     *
     * @param {string} r
     * @param {object} h
     * @param {string} state
     * @param {number} max how many answers to show before "+n"
     * @returns {HTMLElement[]}
     */
    summary(r, h, state, max = 1) {
        const pill = (text, cls = '') => el('span', 'sh-pill sh-pill-' + r + (cls ? ' ' + cls : ''), {dir: 'auto', text,
            title: text});
        if (state === 'skip') {
            return [el('span', 'sh-stepcard-note', {text: S.card_skip})];
        }
        if (r === 'hazard') {
            if (state === 'done') {
                return [pill(h.name || '')];
            }
            return state === 'now' ? [el('span', 'sh-stepcard-note', {text: S.card_q_hazard})] : [];
        }
        let chosen = (h.rows[r] || []).filter((c) => Number(c.chosen) && (Number(c.correct) || this.mode === 'test'));
        if (this.mode === 'study') {
            chosen = this.studyShown(h).filter((c) => c.kind === r);
            if (this.studyEmpty(h, r) && state !== 'wait') {
                return [el('span', 'sh-stepcard-note', {text: S.study_card_empty})];
            }
        }
        if (state === 'wait' && !chosen.length) {
            return [];
        }
        if (!chosen.length) {
            return [el('span', 'sh-stepcard-note', {text: state === 'now' ? S['card_q_' + r] : S.rowdone})];
        }
        const out = chosen.slice(0, max).map((c) => pill(c.text));
        if (chosen.length > max) {
            out.push(pill('+' + (chosen.length - max), 'is-more'));
        }
        return out;
    }

    /**
     * The question for the step worked on, its tip and its answers.
     *
     * @param {object|null} h
     * @param {string|null} row
     */
    drawQuestion(h, row) {
        const on = !!(h && row);
        this.q.hidden = !on;
        this.qs.hidden = !on;
        this.opts.hidden = !on;
        this.opts.replaceChildren();
        this.doneBar.replaceChildren();
        if (!on) {
            return;
        }
        this.drawAsk(h, row);
        // The tip, and in Practice how many right answers are found so far, as dots.
        this.qs.replaceChildren(el('span', 'sh-question-tip', {text: this.hint(row, h)}));
        if (this.mode === 'practice' && (row === 'reduce' || row === 'ppe')) {
            const found = (h.rows[row] || []).filter((c) => Number(c.chosen) && Number(c.correct)).length;
            const need = Math.max(found, (h.need && h.need[row]) || 0);
            const meter = el('span', 'sh-found', {'aria-label': fmt(S.found_of, {found, total: need})});
            for (let i = 0; i < need; i++) {
                meter.appendChild(el('span', 'sh-found-dot' + (i < found ? ' is-on' : ''), {'aria-hidden': 'true'}));
            }
            meter.appendChild(el('span', 'sh-found-text', {text: found + '/' + need, 'aria-hidden': 'true'}));
            this.qs.appendChild(meter);
        }
        const labels = row === 'hazard' ? this.scene.chips.filter((c) => !c.placed) : (h.rows[row] || []);
        labels.forEach((c, i) => this.opts.appendChild(this.chip(c, row, i, h)));
        if (this.mode === 'test' && row !== 'eliminate' && row !== 'hazard') {
            const chosen = labels.filter((c) => Number(c.chosen)).length;
            const done = button('sh-btn sh-btn-primary sh-btn-done', S.rowdone_button, 'check', () => this.doneRow(h, row));
            done.disabled = !chosen;
            this.doneBar.appendChild(done);
            // A low panel: the button says what to do, so the tip line makes room for the answers.
            this.qs.hidden = (this.rowH || 999) < TIGHT;
        }
        if (this.mode === 'study') {
            this.doneBar.appendChild(this.studyButton(h));
            // A low panel: the button says what to do, so the tip line makes room for the answers.
            this.qs.hidden = (this.rowH || 999) < TIGHT;
        }
    }

    /**
     * The question box: the step's colour, "Step n" and the question (it pops in when the step changes).
     *
     * @param {object} h
     * @param {string} row
     */
    drawAsk(h, row) {
        const n = ROWS.indexOf(row) + 1;
        const key = h.id + ':' + row;
        const changed = this.qkey !== key;
        this.qkey = key;
        this.q.className = 'sh-question sh-question-' + row;
        if (changed && !REDUCED) {
            void this.q.offsetWidth;
            this.q.classList.add('is-new');
        }
        this.opts.classList.toggle('is-new', changed);
        const step = el('span', 'sh-question-step');
        step.append(icon(row), el('span', '', {text: fmt(S.step_n, n)}));
        // After an elimination, Reduce and PPE are what keeps people safe if it is not approved.
        const backup = (row === 'reduce' || row === 'ppe') && this.isEliminated(h);
        this.q.replaceChildren(step, el('span', 'sh-question-text', {text: fmt(S['q_' + row + (backup ? '_backup' : '')],
            h.number)}));
    }

    /**
     * When a hazard is finished: a green box with what was chosen and the button to the next hazard.
     *
     * @param {object|null} cur the hazard worked on
     * @param {object|null} h the hazard shown
     * @param {boolean} viewing
     * @param {string|null} row
     */
    drawNext(cur, h, viewing, row) {
        this.hNext.replaceChildren();
        const study = this.mode === 'study';
        if (!h || (row && !viewing)) {
            return;
        }
        const finished = viewing || (study ? this.studyDone(h) : h.complete);
        if (!finished) {
            return;
        }
        const box = el('div', 'sh-donebox' + (this.mode === 'test' ? ' is-test' : ''));
        const head = el('div', 'sh-donebox-head');
        head.append(tickMark(true, !viewing && this.doneFor !== h), el('h4', '', {text: fmt(S.hcard_done, h.number)}));
        if (this.mode !== 'test') {
            // The risk itself is in the footer; the box shows how much of it this hazard's controls removed.
            head.appendChild(el('span', 'sh-cut', {text: '−' + this.hazardCut(h) + '%'}));
        }
        if (this.mode === 'practice') {
            head.appendChild(this.starRow(this.stars(h), 'sh-donebox-stars'));
        }
        this.doneFor = viewing ? this.doneFor : h;
        box.appendChild(head);
        const list = el('ul', 'sh-donegrid');
        ROWS.forEach((r) => {
            const state = this.rowState(r, null, h, true);
            const li = el('li', 'sh-donecard sh-donecard-' + r, {style: '--d:' + (120 + ROWS.indexOf(r) * 70) + 'ms'});
            const k = el('span', 'sh-donecard-key');
            k.append(icon(r), el('span', '', {text: S['step_' + r]}));
            const v = el('span', 'sh-donecard-val');
            v.append(...this.summary(r, h, state === 'skip' ? 'skip' : 'done', (this.rowH || 999) < 480 ? 1 : 2));
            li.append(k, v);
            list.appendChild(li);
        });
        box.appendChild(list);
        if (viewing) {
            box.appendChild(button('sh-btn sh-btn-ghost', fmt(S.hcard_back, cur ? cur.number : 1), 'prev',
                () => this.focusHazard(cur)));
        } else {
            const later = this.scene.spots.slice(this.scene.spots.indexOf(h) + 1);
            const next = study ? later[0] : later.find((s) => !s.complete);
            if (next) {
                box.appendChild(button('sh-btn sh-btn-primary sh-btn-go', fmt(S.hcard_next, next.number), 'next',
                    () => this.nextHazard(), {after: true}));
            }
        }
        this.hNext.appendChild(box);
    }

    /**
     * A step's state for a hazard: now (to work on), done, wait (later) or skip (not needed: eliminated).
     *
     * @param {string} r the step
     * @param {string|null} row the current step
     * @param {object} h the hazard shown
     * @param {boolean} viewing whether a finished hazard is looked back at
     * @returns {string}
     */
    rowState(r, row, h, viewing = false) {
        if (this.mode === 'study') {
            if (r === row) {
                return 'now';
            }
            return this.studyRevealed(h, r) ? 'done' : 'wait';
        }
        if (r === 'hazard') {
            if (h.found) {
                return 'done';
            }
            return row === 'hazard' ? 'now' : 'wait';
        }
        if (Number(h.done[r])) {
            // Only a row with no labels (older content) is skipped; every other row is played, even once eliminated.
            return (h.rows[r] || []).length ? 'done' : 'skip';
        }
        return !viewing && row === r ? 'now' : 'wait';
    }

    /**
     * An answer: a button with a letter (A, B, …), ticked when right, crossed when wrong. Tapped, or dragged onto the
     * picture.
     *
     * @param {object} c the label {id, text, ...}
     * @param {string} row
     * @param {number} index
     * @param {object} h the hazard
     * @returns {HTMLElement}
     */
    chip(c, row, index, h) {
        const study = this.mode === 'study';
        const chosen = row !== 'hazard' && Number(c.chosen);
        const tried = row === 'hazard' && this.tried && this.tried.has(h.id + ':' + c.id);
        const right = row === 'hazard' ? c.id === h.id : !!Number(c.correct);
        const state = study ? this.studyChipState(h, row, right) : this.chipState(c, chosen, tried);
        let cls = 'sh-chip sh-opt sh-chip-' + row + (state ? ' ' + state : '');
        if (this.pending && this.pending.has(row + ':' + c.id)) {
            cls += ' is-checking';
        }
        const node = el('button', cls, {type: 'button', dir: 'auto', 'data-label': row + ':' + c.id,
            style: '--d:' + (index * 45) + 'ms', disabled: !study && (chosen || tried) ? true : null});
        const key = el('span', 'sh-opt-key', {'aria-hidden': 'true'});
        const fresh = study ? this.sfresh : this.fresh === row + ':' + c.id;
        if (state === 'is-right' || state === 'is-picked') {
            key.appendChild(tickMark(true, fresh));
        } else if (state === 'is-wrongans') {
            key.appendChild(tickMark(false, fresh));
        } else {
            key.textContent = String.fromCharCode(65 + (index % 26));
        }
        node.append(key, el('span', 'sh-chip-text', {text: c.text}));
        if (study) {
            if (this.sguess === c.id) {
                node.classList.add('is-guess');
            }
            node.addEventListener('click', () => this.studyTap(h, row, c, right));
            return node;
        }
        if (node.disabled) {
            return node;
        }
        // A tap places the label on the hazard being worked on.
        node.addEventListener('click', () => {
            Sound.play('pickup');
            this.dropOn(this.active(), {id: c.id, row, text: c.text});
        });
        node.addEventListener('pointerdown', (e) => this.drag(e, node, {id: c.id, row, text: c.text}));
        return node;
    }

    /**
     * How an answer looks: right (ticked), wrong (crossed), picked in a test, or still to choose.
     *
     * @param {object} c
     * @param {boolean} chosen
     * @param {boolean} tried a wrong hazard label already tried on this spot
     * @returns {string}
     */
    chipState(c, chosen, tried) {
        if (chosen) {
            if (this.mode === 'test') {
                return 'is-picked';
            }
            return Number(c.correct) ? 'is-right' : 'is-wrongans';
        }
        return tried ? 'is-wrongans' : '';
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
            const cur = this.active();
            if (label.row === 'hazard') {
                // A hazard label goes on the spot being worked on.
                const spot = under.closest('.sh-spot');
                return spot && cur && spot.dataset.spot === String(cur.id) ? spot : null;
            }
            // A control goes on the hazard being worked on: dropped anywhere on the picture.
            return this.figure.contains(under) ? this.figure : null;
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
        if (!spot || this.mode === 'study' || (label.row === 'hazard' && spot.found) || spot !== this.active()) {
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
                this.setFeedback(el('p', 'sh-fb sh-fb-test', {text: err.message}));
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
        const was = !!spot.complete;
        const step = this.currentRow();
        spot.found = !!result.found;
        spot.done = result.done;
        spot.complete = !!result.complete;
        spot.name = result.name || spot.name;
        spot.now = result.now || spot.now;
        this.fresh = label ? label.row + ':' + label.id : null;
        if (label) {
            this.markLabel(spot, label, result);
            this.labelFeedback(spot, label, result);
        }
        this.view = null;
        if (spot.complete && !was) {
            // The hazard is finished: it stays in the card until the learner moves on.
            this.hold = spot;
        }
        this.refresh();
        this.fresh = null;
        if (this.praiseDue && !spot.complete) {
            this.praise();
        }
        this.praiseDue = false;
        // A small sound as the cards move on: to the next step, or the hazard is done.
        if (spot.complete && !was) {
            this.celebrate(spot, !!result.scenecomplete);
        } else if (!spot.complete && this.currentRow() !== step) {
            setTimeout(() => Sound.play('step'), 260);
        }
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
        if (label.row === 'hazard' && result.marked && !right) {
            // Practice: a wrong hazard label is crossed out for this spot (it may be right for another one).
            this.tried.add(spot.id + ':' + label.id);
        }
        if (!result.marked) {
            Sound.play('drop');
            const saved = el('div', 'sh-fb is-saved', {role: 'status'});
            const head = el('div', 'sh-fb-head');
            head.append(tickMark(true, true), el('strong', '', {text: S.fb_saved}),
                el('span', 'sh-fb-label', {dir: 'auto', text: label.text}));
            saved.appendChild(head);
            this.setFeedback(saved);
        } else {
            Sound.play(right ? 'correct' : 'wrong');
            this.feedbackCard(result, label.text, label.row);
            this.streak = right ? this.streak + 1 : 0;
            if (!right) {
                this.misses[spot.id] = (this.misses[spot.id] || 0) + 1;
            } else {
                this.praiseDue = true;
            }
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
        // Eliminated: the gauges and the matrix show the risk if the elimination is not approved, so the controls and
        // PPE still chosen keep lowering it.
        const l = now.likelihood;
        const c = now.consequence;
        this.gL.set(l, animate && before && before.likelihood !== l);
        this.gC.set(c, animate && before && before.consequence !== c);
        const rate = rating(now.likelihood, now.consequence);
        const start = rating(h.likelihood, h.consequence);
        this.ratingNode.replaceChildren();
        if (now.eliminated) {
            this.ratingNode.append(el('span', 'sh-rating sh-rating-eliminated', {text: S.rating_eliminated}),
                el('span', 'sh-mini sh-ifnot', {text: S.risk_ifnot}));
        } else {
            this.ratingNode.appendChild(el('span', 'sh-mini', {text: S.riskrating}));
        }
        this.ratingNode.appendChild(el('span', 'sh-rating sh-rating-' + start + (rate !== start ? ' is-was' : ''),
            {text: S['rating_' + start]}));
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
     * What a right answer did, as small pills: its level and how far it lowered the likelihood and consequence.
     *
     * @param {HTMLElement} tags where the pills go
     * @param {object} result {level, dl, dc, eliminates}
     * @param {string} row
     */
    effectPills(tags, result, row) {
        if (row === 'hazard') {
            tags.appendChild(el('span', 'sh-tagpill is-found', {text: S.fb_hazardfound}));
            return;
        }
        if ((row === 'eliminate' || result.level === 'elimination') && !Number(result.eliminates)) {
            tags.appendChild(el('span', 'sh-tagpill', {text: S.fb_cannot}));
            return;
        }
        if (result.level) {
            tags.appendChild(el('span', 'sh-tagpill is-level', {text: S['level_' + result.level] || result.level}));
        }
        if (Number(result.eliminates)) {
            tags.appendChild(el('span', 'sh-tagpill is-gone', {text: S.fb_removes}));
        }
        if (Number(result.dl) && !Number(result.eliminates)) {
            tags.appendChild(el('span', 'sh-tagpill is-down', {text: fmt(S.fb_lowers_l, result.dl)}));
        }
        if (Number(result.dc) && !Number(result.eliminates)) {
            tags.appendChild(el('span', 'sh-tagpill is-down', {text: fmt(S.fb_lowers_c, result.dc)}));
        }
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
        head.append(tickMark(right, true), el('strong', '', {text: right ? S.fb_right : S.fb_wrong}));
        if (this.mode === 'study') {
            // Practice: the answer itself is ticked or crossed in the list, so the card does not repeat it.
            head.appendChild(el('span', 'sh-fb-label', {dir: 'auto', text: text}));
        }
        card.appendChild(head);
        // What a right answer did, as small pills: its level and how far it lowered the likelihood and consequence.
        if (right) {
            this.effectPills(head, result, row);
        }
        if (result.reason) {
            card.appendChild(el('p', 'sh-fb-reason', {dir: 'auto', text: result.reason}));
        }
        this.setFeedback(card);
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
        // The pop-up over the picture celebrates; the last hazard's box stays beside it.
        this.nextbtn.disabled = false;
        this.nextbtn.classList.add('sh-btn-go');
        this.nextbtn.focus({preventScroll: true});
    }

    /**
     * A short word of praise over the picture after a right answer, with the streak of right answers in a row.
     */
    praise() {
        if (REDUCED) {
            return;
        }
        this.figure.querySelectorAll('.sh-praise').forEach((n) => n.remove());
        const node = el('div', 'sh-praise', {'aria-hidden': 'true'});
        node.append(tickMark(true, true), el('span', '', {text: S['praise_' + (1 + Math.floor(Math.random() * 5))]}));
        if (this.streak >= 3) {
            node.appendChild(el('span', 'sh-praise-streak', {text: fmt(S.streak, this.streak)}));
        }
        this.figure.appendChild(node);
        setTimeout(() => node.remove(), 1700);
    }

    /**
     * How much of a hazard's starting risk is removed, in percent.
     *
     * @param {object} h
     * @returns {number}
     */
    hazardCut(h) {
        const start = h.likelihood * h.consequence;
        const r = this.mode === 'study' ? this.studyRisk(h) : h.now;
        if (!r || start <= 1) {
            return 0;
        }
        const now = riskScore(r);
        return Math.round(Math.max(0, Math.min(100, 100 * (start - Math.max(1, now)) / (start - 1))));
    }

    /**
     * Practice: stars for a hazard, from the wrong answers on the way (none: 3, one: 2, more: 1).
     *
     * @param {object} h
     * @returns {number}
     */
    stars(h) {
        const m = this.misses[h.id] || 0;
        return Math.max(1, 3 - m);
    }

    /**
     * Three stars, the earned ones gold.
     *
     * @param {number} n
     * @param {string} cls
     * @returns {HTMLElement}
     */
    starRow(n, cls) {
        const row = el('span', cls, {role: 'img', 'aria-label': fmt(S.stars_label, n)});
        [0, 1, 2].forEach((i) => {
            const star = el('span', i < n ? 'is-on' : '', {style: '--d:' + (200 + i * 120) + 'ms'});
            star.appendChild(icon('star'));
            row.appendChild(star);
        });
        return row;
    }

    /**
     * A hazard (or the whole scene) is done: a pop-up over the picture with the risk cut, stars and confetti.
     *
     * @param {object} h
     * @param {boolean} scene whether the scene is finished too
     */
    celebrate(h, scene) {
        const test = this.mode === 'test';
        if (this.fbDocked) {
            this.hideFeedback();
        }
        const pic = this.figure.querySelector('.sh-pic');
        pic.querySelectorAll('.sh-pop').forEach((n) => n.remove());
        const pop = el('div', 'sh-pop' + (test ? ' is-test' : ''), {role: 'status'});
        const card = el('div', 'sh-pop-card');
        card.appendChild(tickMark(true, true));
        let title = test ? fmt(S.hcard_done, h.number) : S.pop_great;
        if (this.mode === 'study') {
            title = fmt(S.pop_learned, h.number);
        }
        if (scene) {
            title = S.scene_pop;
        }
        card.appendChild(el('h4', 'sh-pop-title', {text: title}));
        if (test) {
            card.appendChild(el('p', 'sh-pop-text', {text: S.pop_saved}));
        } else {
            const cut = scene ? Math.round(this.sceneSafety()) : this.hazardCut(h);
            card.appendChild(el('span', 'sh-pop-big', {text: '−' + cut + '%'}));
            card.appendChild(el('p', 'sh-pop-text', {text: fmt(scene ? S.scene_pop_cut : S.pop_cut, cut)}));
            if (this.mode === 'practice') {
                card.appendChild(this.starRow(scene ? Math.round(this.scene.spots.reduce((a, s) => a + this.stars(s), 0)
                    / this.scene.spots.length) : this.stars(h), 'sh-pop-stars'));
            }
        }
        const close = () => {
            pop.classList.add('is-out');
            setTimeout(() => pop.remove(), 300);
        };
        if (scene) {
            const last = this.index === this.data.scenes.length - 1;
            card.appendChild(button('sh-btn sh-btn-primary sh-btn-go', last ? S.finish : S.nextscene, last ? 'flag' : 'next',
                () => this.nextbtn.click(), {after: !last}));
        } else {
            setTimeout(close, 2600);
        }
        pop.addEventListener('click', (e) => {
            if (!e.target.closest('.sh-btn')) {
                close();
            }
        });
        pop.appendChild(card);
        pic.appendChild(pop);
        this.say(title);
        setTimeout(() => Sound.play(scene ? 'finish' : 'hazarddone'), 200);
        if (!REDUCED && !test) {
            confetti(pic, scene ? 140 : 50);
        }
    }

    /**
     * Study: starts the hazard studied from its first step, nothing revealed yet.
     */
    studyReset() {
        this.sidx = 0;
        this.srev = false;
        this.sfin = false;
        this.sguess = null;
        this.sfresh = false;
    }

    /**
     * Study: the steps of every hazard in order: name it, eliminate, reduce, PPE.
     *
     * @returns {string[]}
     */
    studyPlan() {
        // Every hazard goes through all four steps, even once eliminated: the elimination may not be approved.
        return ROWS;
    }

    /**
     * Study: whether a step of a hazard has no answers written (older content).
     *
     * @param {object} h
     * @param {string} r
     * @returns {boolean}
     */
    studyEmpty(h, r) {
        return r !== 'hazard' && !(h.rows[r] || []).length;
    }

    /**
     * Study: whether a step's answers are shown for a hazard (earlier hazards: all; later ones: none).
     *
     * @param {object} h
     * @param {string} r
     * @returns {boolean}
     */
    studyRevealed(h, r) {
        const index = this.scene.spots.indexOf(h);
        const cur = this.studyHazard || 0;
        if (index !== cur) {
            return index < cur;
        }
        if (this.sfin) {
            return true;
        }
        const at = this.studyPlan().indexOf(r);
        return at >= 0 && (at < this.sidx || (at === this.sidx && this.srev));
    }

    /**
     * Study: the step worked on now, or null once the hazard is finished.
     *
     * @param {object} h
     * @returns {string|null}
     */
    studyRow(h) {
        if (this.scene.spots.indexOf(h) !== (this.studyHazard || 0) || this.sfin) {
            return null;
        }
        return this.studyPlan()[this.sidx] || null;
    }

    /**
     * Study: the right controls of the steps revealed so far.
     *
     * @param {object} h
     * @returns {object[]}
     */
    studyShown(h) {
        const shown = [];
        for (const k of KINDS) {
            if (this.studyRevealed(h, k)) {
                (h.rows[k] || []).filter((c) => Number(c.correct)).forEach((c) => shown.push({...c, kind: k}));
            }
        }
        return shown;
    }

    /**
     * Study: whether the hazard has been walked through.
     *
     * @param {object} h
     * @returns {boolean}
     */
    studyDone(h) {
        const index = this.scene.spots.indexOf(h);
        const cur = this.studyHazard || 0;
        return index < cur || (index === cur && this.sfin);
    }

    /**
     * Study: a hazard's risk with the controls revealed so far.
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
     * Study: how an answer looks: plain until the step is revealed, then ticked (right) or crossed (wrong).
     *
     * @param {object} h
     * @param {string} row
     * @param {boolean} right
     * @returns {string}
     */
    studyChipState(h, row, right) {
        if (!this.studyRevealed(h, row)) {
            return '';
        }
        return right ? 'is-right' : 'is-wrongans';
    }

    /**
     * Study: the big button in the hazard card: Show the answer, then Next: (step), then Finish hazard.
     *
     * @param {object} h
     * @returns {HTMLElement}
     */
    studyButton(h) {
        const plan = this.studyPlan();
        let text = S.study_show;
        let ic = 'eye';
        if (this.srev || this.studyEmpty(h, plan[this.sidx])) {
            const next = plan[this.sidx + 1];
            text = next ? fmt(S.study_next_step, S['step_' + next]) : S.study_finish;
            ic = next ? 'next' : 'check';
        }
        const b = button('sh-btn sh-btn-primary sh-btn-done sh-btn-study', text, ic, () => this.studyPress(),
            {after: ic === 'next'});
        b.dataset.study = this.srev ? 'next' : 'show';
        return b;
    }

    /**
     * Study: an answer tapped. Before the reveal it is the learner's guess; after it, it says why it is right or wrong.
     *
     * @param {object} h
     * @param {string} row
     * @param {object} c
     * @param {boolean} right
     */
    studyTap(h, row, c, right) {
        if (!this.studyRevealed(h, row)) {
            this.sguess = c.id;
            this.studyPress();
            return;
        }
        let reason = c.reason || '';
        if (row === 'hazard') {
            reason = right ? (h.why || '') : S.study_nothazard;
        }
        this.feedbackCard({marked: 1, correct: right, reason, level: c.level, dl: c.dl, dc: c.dc,
            eliminates: c.eliminates}, c.text, row);
    }

    /**
     * Study: the big button pressed: reveals the step's answers, or moves on to the next step, or finishes the hazard.
     */
    studyPress() {
        const h = this.active();
        const row = h && this.studyRow(h);
        if (!row) {
            return;
        }
        const before = this.studyRisk(h);
        if (!this.srev && !this.studyEmpty(h, row)) {
            this.srev = true;
            this.sfresh = true;
            Sound.play('drop');
            this.studyCard(h, row);
        } else {
            this.sguess = null;
            if (this.sidx + 1 < this.studyPlan().length) {
                this.sidx++;
                this.srev = false;
                this.setFeedback();
                setTimeout(() => Sound.play('step'), 120);
            } else {
                this.sfin = true;
                this.setFeedback();
            }
        }
        this.refresh();
        this.sfresh = false;
        this.showRisk(h, true, before);
        if (this.sfin) {
            this.celebrate(h, false);
        }
        // Keyboard users stay on the big button (or on Next: Hazard n once the hazard is finished).
        const go = this.sfin ? this.root.querySelector('.sh-btn-go') || this.nextbtn : this.root.querySelector('.sh-btn-study');
        if (go) {
            go.focus({preventScroll: true});
            // On a phone the button can move as the answers change: it scrolls back into view only if it left it.
            const box = go.getBoundingClientRect();
            if (box.bottom > window.innerHeight || box.top < 0) {
                go.scrollIntoView({block: 'nearest', behavior: REDUCED ? 'auto' : 'smooth'});
            }
        }
    }

    /**
     * Study: the card shown on reveal: the right answers, what each does and why.
     *
     * @param {object} h
     * @param {string} row
     */
    studyCard(h, row) {
        const rights = row === 'hazard' ? [{id: h.id, text: h.name || '', reason: h.why || ''}]
            : (h.rows[row] || []).filter((c) => Number(c.correct));
        const card = el('div', 'sh-fb is-right sh-fb-study', {role: 'status'});
        const head = el('div', 'sh-fb-head');
        head.append(tickMark(true, true), el('strong', '', {text: rights.length > 1 ? S.study_answers : S.study_answer}));
        if (this.sguess !== null) {
            const hit = rights.some((c) => c.id === this.sguess);
            head.appendChild(el('span', 'sh-tagpill ' + (hit ? 'is-gone' : 'is-miss'),
                {text: hit ? S.study_guess_right : S.study_guess_wrong}));
        }
        card.appendChild(head);
        const list = el('ul', 'sh-fb-answers');
        rights.forEach((c) => {
            const li = el('li', 'sh-fb-answer');
            const top = el('div', 'sh-fb-answer-head');
            top.append(el('span', 'sh-fb-label', {dir: 'auto', text: c.text}));
            this.effectPills(top, c, row);
            li.appendChild(top);
            if (c.reason) {
                li.appendChild(el('p', 'sh-fb-reason', {dir: 'auto', text: c.reason}));
            }
            list.appendChild(li);
        });
        card.appendChild(list);
        this.setFeedback(card);
        this.say(rights.map((c) => c.text + '. ' + (c.reason || '')).join(' '));
        const voice = rights.flatMap((c) => c.voice || []);
        if (voice.length) {
            this.voice.play(voice);
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
