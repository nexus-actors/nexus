// nexus-brand/scripts/og-gen.mjs
// Generate an OG image (1200x630) as SVG. Most social platforms accept SVG.
// Polish step: rasterize to PNG via sharp/satori when needed.
//
// Usage: node og-gen.mjs --title "Page Title" --desc "Description" --out path/to/og.svg
//        node og-gen.mjs -t "Title" -d "Desc" -s "Section" -o path.svg

import { parseArgs } from 'node:util';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const tokens = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'tokens.json'), 'utf-8'));

const { values } = parseArgs({
  options: {
    title:   { type: 'string', short: 't' },
    desc:    { type: 'string', short: 'd' },
    out:     { type: 'string', short: 'o' },
    section: { type: 'string', short: 's' },
  },
});

if (!values.title || !values.out) {
  console.error('Usage: og-gen.mjs --title "X" --desc "Y" --out path.svg [--section Z]');
  process.exit(2);
}

function escape(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]));
}

const titleY   = values.section ? 200 : 160;
const descY    = values.section ? 290 : 250;

const svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630" role="img" aria-label="${escape(values.title)}">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="${tokens.color.primary}" stop-opacity="0.1"/>
      <stop offset="100%" stop-color="${tokens.color.accent}" stop-opacity="0.1"/>
    </linearGradient>
  </defs>
  <rect width="1200" height="630" fill="#0f172a"/>
  <rect width="1200" height="630" fill="url(#bg)"/>
  ${values.section ? `<text x="80" y="100" font-family="ui-sans-serif, system-ui, sans-serif" font-size="28" font-weight="500" fill="${tokens.color.primary}">${escape(values.section)}</text>` : ''}
  <text x="80" y="${titleY}" font-family="ui-sans-serif, system-ui, sans-serif" font-size="80" font-weight="700" fill="#f1f5f9">${escape(values.title)}</text>
  ${values.desc ? `<text x="80" y="${descY}" font-family="ui-sans-serif, system-ui, sans-serif" font-size="32" font-weight="400" fill="#94a3b8">${escape(values.desc).slice(0, 80)}</text>` : ''}
  <g transform="translate(80, 540)">
    <circle cx="0" cy="0" r="8" fill="${tokens.color.primary}"/>
    <circle cx="32" cy="20" r="8" fill="${tokens.color.accent}"/>
    <circle cx="32" cy="0" r="4" fill="#f1f5f9"/>
    <text x="56" y="6" font-family="ui-sans-serif, system-ui, sans-serif" font-size="28" font-weight="700" fill="#f1f5f9">Nexus</text>
  </g>
</svg>`;

fs.mkdirSync(path.dirname(values.out), { recursive: true });
fs.writeFileSync(values.out, svg);
console.log(`Wrote ${values.out}`);
