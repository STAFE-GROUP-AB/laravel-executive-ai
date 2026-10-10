#!/usr/bin/env node
/**
 * WCAG 2.2 contrast check for resources/css/tokens.css (S0-24, blueprint §13 workstream C).
 *
 * Pure Node, no dependencies. Parses the :root (light) block, the [data-theme="dark"]
 * block and the prefers-color-scheme dark block, resolves one level of var() references,
 * and prints the contrast ratio for every documented foreground/background pair.
 *
 *   node packaging/fixtures/design/contrast-check.mjs            # table + exit code
 *   node packaging/fixtures/design/contrast-check.mjs --markdown # table for docs/PR
 *
 * Exit 1 if any pair is below its threshold (4.5:1 text, 3:1 large text / UI), or if the
 * two dark blocks differ.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const tokensPath = resolve(here, '../../../resources/css/tokens.css');
const markdown = process.argv.includes('--markdown');

/** Pairs: [foreground, background, role]. role: 'text' => 4.5, 'ui' => 3.0 */
const PAIRS = [
    ['--color-text-primary', '--color-canvas', 'text'],
    ['--color-text-primary', '--color-surface', 'text'],
    ['--color-text-primary', '--color-surface-raised', 'text'],
    ['--color-text-primary', '--color-surface-sunken', 'text'],
    ['--color-text-secondary', '--color-canvas', 'text'],
    ['--color-text-secondary', '--color-surface', 'text'],
    ['--color-text-secondary', '--color-surface-raised', 'text'],
    ['--color-text-secondary', '--color-surface-sunken', 'text'],
    ['--color-text-muted', '--color-canvas', 'text'],
    ['--color-text-muted', '--color-surface', 'text'],
    ['--color-text-muted', '--color-surface-raised', 'text'],
    ['--color-text-muted', '--color-surface-sunken', 'text'],
    ['--color-text-inverse', '--color-surface-inverse', 'text'],
    ['--color-text-on-accent', '--color-accent', 'text'],
    ['--color-text-on-accent', '--color-accent-hover', 'text'],
    ['--color-accent', '--color-canvas', 'text'],
    ['--color-accent', '--color-surface', 'text'],
    ['--color-accent-text', '--color-accent-subtle', 'text'],
    ['--color-success', '--color-surface', 'text'],
    ['--color-success-text', '--color-success-surface', 'text'],
    ['--color-warning', '--color-surface', 'text'],
    ['--color-warning-text', '--color-warning-surface', 'text'],
    ['--color-danger', '--color-surface', 'text'],
    ['--color-danger-text', '--color-danger-surface', 'text'],
    ['--color-info', '--color-surface', 'text'],
    ['--color-info-text', '--color-info-surface', 'text'],
    ['--color-border-strong', '--color-surface', 'ui'],
    ['--color-border-strong', '--color-canvas', 'ui'],
    ['--color-focus-ring', '--color-canvas', 'ui'],
    ['--color-focus-ring', '--color-surface', 'ui'],
    ['--color-accent', '--color-surface', 'ui'],
    ['--color-success', '--color-surface', 'ui'],
    ['--color-warning', '--color-surface', 'ui'],
    ['--color-danger', '--color-surface', 'ui'],
    ['--color-info', '--color-surface', 'ui'],
];

const THRESHOLD = { text: 4.5, ui: 3.0 };

function extractBlock(css, selectorPattern) {
    const match = css.match(selectorPattern);

    if (! match) {
        throw new Error(`Block not found: ${selectorPattern}`);
    }

    let depth = 0;
    let start = -1;

    for (let i = match.index + match[0].length - 1; i < css.length; i++) {
        if (css[i] === '{') {
            if (depth === 0) {
                start = i + 1;
            }
            depth++;
        }

        if (css[i] === '}') {
            depth--;
            if (depth === 0) {
                return css.slice(start, i);
            }
        }
    }

    throw new Error(`Unbalanced block: ${selectorPattern}`);
}

function parseDeclarations(block) {
    const declarations = new Map();
    const stripped = block.replace(/\/\*[\s\S]*?\*\//g, '');

    for (const statement of stripped.split(';')) {
        const idx = statement.indexOf(':');

        if (idx === -1) {
            continue;
        }

        const name = statement.slice(0, idx).trim();
        const value = statement.slice(idx + 1).trim();

        if (name.startsWith('--')) {
            declarations.set(name, value);
        }
    }

    return declarations;
}

function resolveColor(name, theme) {
    let value = theme.get(name);

    if (value === undefined) {
        throw new Error(`Unknown token ${name}`);
    }

    const ref = value.match(/^var\((--[\w-]+)\)$/);

    if (ref) {
        value = theme.get(ref[1]);
    }

    const hex = value.match(/^#([0-9a-f]{6})$/i);

    if (! hex) {
        throw new Error(`${name} is not an opaque 6-digit hex color: "${value}"`);
    }

    const int = parseInt(hex[1], 16);

    return [(int >> 16) & 255, (int >> 8) & 255, int & 255];
}

function channel(c) {
    const s = c / 255;

    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
}

function luminance([r, g, b]) {
    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function contrast(fg, bg) {
    const l1 = luminance(fg);
    const l2 = luminance(bg);
    const [hi, lo] = l1 > l2 ? [l1, l2] : [l2, l1];

    return (hi + 0.05) / (lo + 0.05);
}

function toHex([r, g, b]) {
    return '#' + [r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('');
}

const css = readFileSync(tokensPath, 'utf8');

const light = parseDeclarations(extractBlock(css, /^:root\s*\{/m));
const darkExplicit = parseDeclarations(extractBlock(css, /^\[data-theme="dark"\]\s*\{/m));
const darkMedia = parseDeclarations(
    extractBlock(css, /:root:not\(\[data-theme="light"\]\)\s*\{/m)
);

let failures = 0;

const explicitEntries = JSON.stringify([...darkExplicit.entries()].sort());
const mediaEntries = JSON.stringify([...darkMedia.entries()].sort());

if (explicitEntries !== mediaEntries) {
    console.error('FAIL: [data-theme="dark"] and prefers-color-scheme dark blocks differ.');
    failures++;
}

const themes = {
    light,
    dark: new Map([...light, ...darkExplicit]),
};

const rows = [];

for (const [themeName, theme] of Object.entries(themes)) {
    for (const [fgName, bgName, role] of PAIRS) {
        const fg = resolveColor(fgName, theme);
        const bg = resolveColor(bgName, theme);
        const ratio = contrast(fg, bg);
        const threshold = THRESHOLD[role];
        const pass = ratio >= threshold;

        if (! pass) {
            failures++;
        }

        rows.push({
            theme: themeName,
            fg: fgName.replace('--color-', ''),
            fgHex: toHex(fg),
            bg: bgName.replace('--color-', ''),
            bgHex: toHex(bg),
            role,
            threshold,
            ratio: ratio.toFixed(2),
            pass,
        });
    }
}

if (markdown) {
    console.log('| Theme | Foreground | Background | Role | Min | Ratio | Result |');
    console.log('|---|---|---|---|---|---|---|');

    for (const r of rows) {
        console.log(
            `| ${r.theme} | \`${r.fg}\` ${r.fgHex} | \`${r.bg}\` ${r.bgHex} | ${r.role} | ${r.threshold}:1 | **${r.ratio}:1** | ${r.pass ? 'pass' : 'FAIL'} |`
        );
    }
} else {
    const width = Math.max(...rows.map((r) => r.fg.length + r.bg.length)) + 6;

    for (const r of rows) {
        const pair = `${r.fg} on ${r.bg}`.padEnd(width);
        console.log(
            `${r.pass ? 'PASS' : 'FAIL'}  ${r.theme.padEnd(5)} ${pair} ${r.role.padEnd(4)} ${r.ratio.padStart(5)}:1  (min ${r.threshold}:1)`
        );
    }
}

const summary = `${rows.length - failures}/${rows.length} pairs pass; ${failures} failure(s).`;

if (failures > 0) {
    console.error(`\n${summary}`);
    process.exit(1);
}

console.log(`\n${summary}`);
