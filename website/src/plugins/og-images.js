// website/src/plugins/og-images.js
// Docusaurus postBuild plugin — generates per-section OG SVG images
// by invoking nexus-brand/scripts/og-gen.mjs for ~8 top-of-section pages.
// Output: build/img/og/<slug>.svg
// Default OG (themeConfig.image) covers every other page.

'use strict';

const { execSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');

module.exports = function ogImagesPlugin(context, _options) {
  const ogScript = path.join(context.siteDir, '..', 'nexus-brand', 'scripts', 'og-gen.mjs');

  return {
    name: 'og-images-plugin',

    async postBuild({ outDir }) {
      if (!fs.existsSync(ogScript)) {
        console.warn('[og-images] og-gen.mjs not found at', ogScript, '— skipping');
        return;
      }

      const ogDir = path.join(outDir, 'img', 'og');
      fs.mkdirSync(ogDir, { recursive: true });

      // Top-of-section docs pages to generate specific OG images for
      const targets = [
        { slug: 'welcome',                      title: 'Nexus',        desc: 'Actor System for PHP 8.5+',                 section: ''              },
        { slug: 'getting-started/installation', title: 'Installation', desc: 'Install Nexus in minutes',                  section: 'Getting Started' },
        { slug: 'getting-started/quick-start',  title: 'Quick Start',  desc: 'Build your first actor in 5 minutes',      section: 'Getting Started' },
        { slug: 'core-concepts/actors',          title: 'Actors',       desc: 'The heart of the Nexus actor model',       section: 'Core Concepts'   },
        { slug: 'http/overview',                 title: 'HTTP',         desc: 'Handle HTTP requests with actors',         section: 'HTTP'            },
        { slug: 'persistence/overview',          title: 'Persistence',  desc: 'Event sourcing and durable state',         section: 'Persistence'     },
        { slug: 'doctrine/overview',             title: 'Doctrine',     desc: 'Doctrine DBAL + ORM persistence adapters', section: 'Doctrine'        },
        { slug: 'reference/overview',            title: 'Reference',    desc: 'Full API reference',                       section: ''                },
      ];

      let generated = 0;
      for (const t of targets) {
        const filename = t.slug.replace(/\//g, '-') + '.svg';
        const out = path.join(ogDir, filename);

        const args = [
          '--title', `"${t.title}"`,
          '--out',   `"${out}"`,
        ];
        if (t.desc)    args.push('--desc',    `"${t.desc}"`);
        if (t.section) args.push('--section', `"${t.section}"`);

        try {
          execSync(`node "${ogScript}" ${args.join(' ')}`, { stdio: 'pipe' });
          generated++;
        } catch (err) {
          console.warn(`[og-images] Failed to generate OG for ${t.slug}:`, err.message);
        }
      }

      console.log(`[og-images] Generated ${generated}/${targets.length} OG SVGs in ${ogDir}`);
    },
  };
};
