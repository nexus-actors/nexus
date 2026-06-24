// website/src/plugins/glossary-auto-link.js
// Remark plugin: auto-links backtick-wrapped terms in prose to their glossary anchor.
//
// Consumes: website/docs/faq/glossary.md (created by sub-spec 3).
// No-ops gracefully if glossary.md doesn't exist yet.
//
// Only touches `inlineCode` nodes in prose — never inside fenced code blocks.
//
// Note: exported as async factory so the CJS docusaurus.config.js can await
// import() this ESM module (unist-util-visit v5 is ESM-only).

'use strict';

const fs = require('node:fs');
const path = require('node:path');

function loadGlossary(glossaryPath) {
  if (!fs.existsSync(glossaryPath)) return {};
  const content = fs.readFileSync(glossaryPath, 'utf-8');
  const terms = {};
  // Parse H3 entries: "### Term name\n\nDefinition…"
  const matches = content.matchAll(/^### (.+?)$\n+([^\n#][^\n]*)/gm);
  for (const m of matches) {
    terms[m[1].toLowerCase()] = m[2].trim();
  }
  return terms;
}

let glossary = null;

/**
 * Returns a promise that resolves to the remark plugin function.
 * Docusaurus passes remark plugins as [pluginFn] or [[pluginFn, options]].
 * When the config is async, we can await this and pass the resolved fn.
 */
async function createGlossaryAutoLink() {
  const {visit} = await import('unist-util-visit');

  /**
   * @returns {import('unified').Transformer}
   */
  return function glossaryAutoLink() {
    return (tree, file) => {
      if (glossary === null) {
        const root = (file && file.cwd) ? file.cwd : process.cwd();
        const glossaryPath = path.join(root, 'docs', 'faq', 'glossary.md');
        glossary = loadGlossary(glossaryPath);
      }

      if (Object.keys(glossary).length === 0) return;

      visit(tree, 'inlineCode', (node, index, parent) => {
        // Only touch inline backticks in prose — not inside fenced code blocks
        if (!parent || parent.type === 'code') return;
        // Skip nodes already inside a link
        if (parent.type === 'link') return;

        const term = node.value.toLowerCase();
        const definition = glossary[term];

        if (definition) {
          // Replace inlineCode node with a link wrapping it
          const anchor = term.replace(/\s+/g, '-');
          parent.children[index] = {
            type: 'link',
            url: `/docs/faq/glossary#${anchor}`,
            title: definition,
            children: [node],
          };
        }
      });
    };
  };
}

module.exports = createGlossaryAutoLink;
