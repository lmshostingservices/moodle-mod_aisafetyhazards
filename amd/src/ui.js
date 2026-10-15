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
 * Small shared helpers: DOM, strings, icons, animation and template rendering.
 *
 * @module     mod_aisafetyhazards/ui
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {getStrings} from 'core/str';

export const REDUCED = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
export const COARSE = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
export const EASE = 'cubic-bezier(.22,1,.36,1)';
export const SPRING = 'cubic-bezier(.34,1.56,.64,1)';

/**
 * Loads language strings into an object keyed by string name.
 *
 * @param {string[]} keys
 * @returns {Promise<object>}
 */
export const loadStrings = async(keys) => {
    const values = await getStrings(keys.map((key) => ({key, component: 'mod_aisafetyhazards'})));
    const out = {};
    keys.forEach((key, i) => {
        out[key] = values[i];
    });
    return out;
};

/**
 * Replaces {$a} / {$a->x} placeholders in a string loaded without parameters.
 *
 * @param {string} str
 * @param {object|string|number} a
 * @returns {string}
 */
export const fmt = (str, a) => {
    if (a !== null && typeof a === 'object') {
        return String(str).replace(/\{\$a->(\w+)\}/g, (m, k) => (a[k] ?? ''));
    }
    return String(str).replace(/\{\$a\}/g, a);
};

/**
 * Creates an element. Text is always set with textContent.
 *
 * @param {string} tag
 * @param {string} cls
 * @param {object} attrs
 * @returns {HTMLElement}
 */
export const el = (tag, cls = '', attrs = {}) => {
    const node = document.createElement(tag);
    if (cls) {
        node.className = cls;
    }
    Object.entries(attrs).forEach(([k, v]) => {
        if (k === 'text') {
            node.textContent = v;
        } else if (v !== null && v !== undefined && v !== false) {
            node.setAttribute(k, v === true ? '' : v);
        }
    });
    return node;
};

const SVGNS = 'http://www.w3.org/2000/svg';

const ICONS = {
    exit: ['M15 18l-6-6 6-6'],
    sound: ['M4 10v4h4l5 4V6L8 10z', 'M16 9a4 4 0 010 6M18.5 6.5a8 8 0 010 11'],
    muted: ['M4 10v4h4l5 4V6L8 10z', 'M17 9l5 6M22 9l-5 6'],
    full: ['M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5'],
    unfull: ['M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5'],
    hint: ['M9 18h6M10 21h4', 'M12 3a6 6 0 00-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0012 3z'],
    reset: ['M4 4v6h6', 'M4.5 15a8 8 0 102-8.5L4 10'],
    next: ['M5 12h14M13 6l6 6-6 6'],
    prev: ['M19 12H5M11 6l-6 6 6 6'],
    check: ['M5 12.5l4.5 4.5L19 7.5'],
    cross: ['M6 6l12 12M18 6L6 18'],
    star: ['M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.6 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z'],
    play: ['M7 5l12 7-12 7z'],
    stop: ['M7 7h10v10H7z'],
    mic: ['M12 3a3 3 0 00-3 3v6a3 3 0 006 0V6a3 3 0 00-3-3z', 'M5 11a7 7 0 0014 0M12 18v3'],
    turtle: ['M4 15c0-4 3.6-7 8-7s8 3 8 7z', 'M20 15l1.5-1.5M4 15l-1 2M8 15v2M16 15v2M20 12h1.5a1 1 0 000-2H20'],
    repeat: ['M17 2l4 4-4 4', 'M3 11V9a3 3 0 013-3h15M7 22l-4-4 4-4', 'M21 13v2a3 3 0 01-3 3H3'],
    flag: ['M5 21V4M5 4h11l-2 4 2 4H5'],
    trophy: ['M8 21h8M12 17v4M7 4h10v5a5 5 0 01-10 0z', 'M17 5h3v2a3 3 0 01-3 3M7 5H4v2a3 3 0 003 3'],
    hazard: ['M12 3L2 20h20z', 'M12 10v4', 'M12 17h.01'],
    eliminate: ['M12 3a9 9 0 100 18 9 9 0 000-18z', 'M5.6 5.6l12.8 12.8'],
    reduce: ['M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z', 'M9 12l2 2 4-4'],
    ppe: ['M3 18h18', 'M5 18v-3a7 7 0 0114 0v3', 'M10 8V5h4v3'],
    plus: ['M12 5v14M5 12h14'],
    trash: ['M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13'],
    info: ['M12 3a9 9 0 100 18 9 9 0 000-18z', 'M12 11v5', 'M12 8h.01'],
    pin: ['M12 21s-7-6.2-7-11a7 7 0 0114 0c0 4.8-7 11-7 11z', 'M12 7.5a2.5 2.5 0 100 5 2.5 2.5 0 000-5z'],
};

/**
 * Returns an inline SVG icon element.
 *
 * @param {string} name
 * @returns {SVGElement}
 */
export const icon = (name) => {
    const svg = document.createElementNS(SVGNS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    (ICONS[name] || []).forEach((d) => {
        const path = document.createElementNS(SVGNS, 'path');
        path.setAttribute('d', d);
        svg.appendChild(path);
    });
    return svg;
};

/**
 * Creates a button with an icon and a text label.
 *
 * @param {string} cls
 * @param {string} label
 * @param {string} iconname
 * @param {Function} handler
 * @param {object} opts after (icon after the text), disabled, title
 * @returns {HTMLButtonElement}
 */
export const button = (cls, label, iconname, handler, opts = {}) => {
    const b = el('button', cls, {type: 'button', title: opts.title || null});
    const span = el('span', '', {text: label});
    if (iconname && !opts.after) {
        b.appendChild(icon(iconname));
    }
    b.appendChild(span);
    if (iconname && opts.after) {
        b.appendChild(icon(iconname));
    }
    if (opts.disabled) {
        b.disabled = true;
    }
    if (handler) {
        b.addEventListener('click', handler);
    }
    return b;
};

/**
 * Creates an icon-only button.
 *
 * @param {string} cls
 * @param {string} label accessible name
 * @param {string} iconname
 * @param {Function} handler
 * @returns {HTMLButtonElement}
 */
export const iconButton = (cls, label, iconname, handler) => {
    const b = el('button', cls, {type: 'button', 'aria-label': label, title: label});
    b.appendChild(icon(iconname));
    if (handler) {
        b.addEventListener('click', handler);
    }
    return b;
};

/**
 * Replaces an icon button's icon.
 *
 * @param {HTMLElement} btn
 * @param {string} iconname
 */
export const setIcon = (btn, iconname) => {
    const old = btn.querySelector('svg');
    if (old) {
        old.replaceWith(icon(iconname));
    } else {
        btn.prepend(icon(iconname));
    }
};

/**
 * Renders a component template into a new element.
 *
 * @param {string} name template name without component
 * @param {object} context
 * @returns {Promise<HTMLElement>} the first element of the rendered HTML
 */
export const render = async(name, context) => {
    const {html, js} = await Templates.renderForPromise(`mod_aisafetyhazards/${name}`, context);
    const holder = document.createElement('div');
    Templates.replaceNodeContents(holder, html, js);
    return holder.firstElementChild;
};

/**
 * Renders a component template and returns all of its top-level nodes.
 *
 * @param {string} name template name without component
 * @param {object} context
 * @returns {Promise<Node[]>}
 */
export const renderAll = async(name, context) => {
    const {html, js} = await Templates.renderForPromise(`mod_aisafetyhazards/${name}`, context);
    const holder = document.createElement('div');
    Templates.replaceNodeContents(holder, html, js);
    return Array.from(holder.childNodes);
};

/**
 * Formats seconds as m:ss.
 *
 * @param {number} secs
 * @returns {string}
 */
export const clock = (secs) => {
    secs = Math.max(0, Math.round(secs));
    return `${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, '0')}`;
};

/**
 * Animates an element from one rect to its current position (FLIP).
 *
 * @param {HTMLElement} node
 * @param {DOMRect} from
 * @param {object} opts duration, easing
 * @returns {Promise}
 */
export const flip = (node, from, opts = {}) => {
    const to = node.getBoundingClientRect();
    if (REDUCED || !node.animate || !to.width || !from) {
        return Promise.resolve();
    }
    const dx = from.left - to.left;
    const dy = from.top - to.top;
    const sx = from.width / to.width;
    const sy = from.height / to.height;
    return node.animate([
        {transform: `translate(${dx}px, ${dy}px) scale(${sx}, ${sy})`, transformOrigin: '0 0'},
        {transform: 'none', transformOrigin: '0 0'},
    ], {duration: opts.duration || 380, easing: opts.easing || SPRING}).finished.catch(() => null);
};

/**
 * Confetti burst on a canvas covering the host.
 *
 * @param {HTMLElement} host
 * @param {number} amount
 */
export const confetti = (host, amount = 140) => {
    if (REDUCED || !host) {
        return;
    }
    const canvas = el('canvas', 'sh-confetti', {'aria-hidden': 'true'});
    host.appendChild(canvas);
    const rect = host.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    const c = canvas.getContext('2d');
    c.scale(dpr, dpr);
    const colors = ['#6366F1', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#EC4899', '#8B5CF6'];
    const parts = Array.from({length: amount}, () => ({
        x: rect.width / 2 + (Math.random() - 0.5) * rect.width * 0.3,
        y: rect.height * 0.35,
        vx: (Math.random() - 0.5) * 14,
        vy: -Math.random() * 13 - 4,
        r: Math.random() * 6 + 4,
        a: Math.random() * Math.PI,
        va: (Math.random() - 0.5) * 0.3,
        color: colors[Math.floor(Math.random() * colors.length)],
        shape: Math.random() > 0.5,
    }));
    const start = performance.now();
    const frame = (now) => {
        const t = now - start;
        c.clearRect(0, 0, rect.width, rect.height);
        parts.forEach((p) => {
            p.vy += 0.35;
            p.vx *= 0.985;
            p.x += p.vx;
            p.y += p.vy;
            p.a += p.va;
            c.save();
            c.globalAlpha = Math.max(0, 1 - t / 2600);
            c.translate(p.x, p.y);
            c.rotate(p.a);
            c.fillStyle = p.color;
            if (p.shape) {
                c.fillRect(-p.r / 2, -p.r / 4, p.r, p.r / 2);
            } else {
                c.beginPath();
                c.arc(0, 0, p.r / 2.6, 0, Math.PI * 2);
                c.fill();
            }
            c.restore();
        });
        if (t < 2600) {
            requestAnimationFrame(frame);
        } else {
            canvas.remove();
        }
    };
    requestAnimationFrame(frame);
};

/**
 * Small particle burst around a point.
 *
 * @param {HTMLElement} host positioned container
 * @param {number} x px within host
 * @param {number} y px within host
 * @param {string} color
 */
export const burst = (host, x, y, color) => {
    if (REDUCED) {
        return;
    }
    for (let i = 0; i < 12; i++) {
        const p = el('span', 'sh-particle');
        const angle = (Math.PI * 2 * i) / 12 + Math.random() * 0.4;
        const dist = 26 + Math.random() * 22;
        p.style.left = `${x}px`;
        p.style.top = `${y}px`;
        p.style.background = i % 3 === 0 ? '#FACC15' : color;
        host.appendChild(p);
        p.animate([
            {transform: 'translate(-50%, -50%) scale(1)', opacity: 1},
            {transform: `translate(calc(-50% + ${Math.cos(angle) * dist}px), calc(-50% + ${Math.sin(angle) * dist}px))
                scale(0.2)`, opacity: 0},
        ], {duration: 620 + Math.random() * 200, easing: 'cubic-bezier(.2,.8,.3,1)'}).finished
            .then(() => p.remove()).catch(() => p.remove());
    }
};

/**
 * A shade of a colour dark enough for white text (WCAG AA 4.5:1).
 *
 * @param {string} hex #RRGGBB
 * @returns {string}
 */
export const readable = (hex) => {
    const m = /^#([0-9a-f]{6})$/i.exec(hex || '');
    if (!m) {
        return '#4338CA';
    }
    let [r, g, b] = [0, 2, 4].map((i) => parseInt(m[1].substr(i, 2), 16));
    const lum = (rr, gg, bb) => {
        const f = (v) => {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * f(rr) + 0.7152 * f(gg) + 0.0722 * f(bb);
    };
    for (let i = 0; i < 20 && (1.05 / (lum(r, g, b) + 0.05)) < 4.6; i++) {
        r = Math.round(r * 0.9);
        g = Math.round(g * 0.9);
        b = Math.round(b * 0.9);
    }
    return '#' + [r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('');
};
