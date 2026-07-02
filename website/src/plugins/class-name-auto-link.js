// website/src/plugins/class-name-auto-link.js
// Remark plugin: auto-links backtick-wrapped PHP class names in prose to their
// API reference page on api.nexusactors.com.
//
// Consumes: website/data/api-classes.json (snapshot from sub-spec 4a).
// No-ops gracefully if api-classes.json doesn't exist.
//
// Only touches `inlineCode` nodes in prose (spec §8.5 mechanism B):
//   - NOT inside fenced code blocks
//   - NOT already inside a link
//   - Only exact matches against the catalog short class name
//
// Returns a promise so the CJS docusaurus.config.js can await import()
// this module (unist-util-visit v5 is ESM-only).

'use strict';

const fs = require('node:fs');
const path = require('node:path');

const API_BASE = 'https://api.nexusactors.com';

let catalog = null;

function loadCatalog() {
  if (catalog !== null) return catalog;
  const catalogPath = path.join(process.cwd(), 'data', 'api-classes.json');
  if (!fs.existsSync(catalogPath)) {
    catalog = new Map();
    return catalog;
  }
  const entries = JSON.parse(fs.readFileSync(catalogPath, 'utf-8'));
  catalog = new Map();
  for (const entry of entries) {
    // Map short class name (last FQCN segment) → full API URL
    const fqcn = entry.fqcn;
    const shortName = fqcn.split('\\').pop();
    // First occurrence wins (avoids ambiguous short names being clobbered)
    if (shortName && !catalog.has(shortName)) {
      catalog.set(shortName, `${API_BASE}/${entry.url}`);
    }
  }
  return catalog;
}

/**
 * Returns a promise that resolves to the remark plugin function.
 */
async function createClassNameAutoLink() {
  const {visit} = await import('unist-util-visit');

  /**
   * @returns {import('unified').Transformer}
   */
  return function classNameAutoLink() {
    return (tree) => {
      const cat = loadCatalog();
      if (cat.size === 0) return;

      visit(tree, 'inlineCode', (node, index, parent) => {
        // Only touch inline backticks in prose — not fenced code blocks
        if (!parent || parent.type === 'code') return;
        // Skip nodes already inside a link
        if (parent.type === 'link') return;

        const name = node.value;
        const url = cat.get(name);

        if (url) {
          parent.children[index] = {
            type: 'link',
            url,
            title: `Open ${name} on api.nexusactors.com`,
            children: [node],
          };
        }
      });
    };
  };
}

module.exports = createClassNameAutoLink;
