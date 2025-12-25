// @ts-check
const { themes } = require('prism-react-renderer');

/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'Nexus',
  tagline: 'An actor system for PHP 8.5+ (work in progress)',
  favicon: 'img/favicon.ico',

  url: 'https://monadial.github.io',
  baseUrl: '/nexus/',

  organizationName: 'monadial',
  projectName: 'nexus',

  onBrokenLinks: 'throw',
  onBrokenMarkdownLinks: 'warn',

  staticDirectories: ['static'],

  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },

  presets: [
    [
      'classic',
      /** @type {import('@docusaurus/preset-classic').Options} */
      ({
        docs: {
          sidebarPath: './sidebars.js',
          editUrl: 'https://github.com/monadial/nexus/tree/main/website/',
        },
        theme: {
          customCss: './src/css/custom.css',
        },
      }),
    ],
  ],

  themeConfig:
    /** @type {import('@docusaurus/preset-classic').ThemeConfig} */
    ({
      navbar: {
        title: 'Nexus',
        items: [
          {
            type: 'docSidebar',
            sidebarId: 'docsSidebar',
            position: 'left',
            label: 'Docs',
          },
          {
            to: '/docs/getting-started/quick-start',
            label: 'Quick Start',
            position: 'left',
          },
          {
            to: '/docs/core-concepts/actors',
            label: 'Core Concepts',
            position: 'left',
          },
          {
            to: '/docs/packages/core',
            label: 'API',
            position: 'left',
          },
          {
            href: 'https://github.com/monadial/nexus',
            label: 'GitHub',
            position: 'right',
          },
        ],
      },
      footer: {
        style: 'dark',
        links: [
          {
            title: 'Docs',
            items: [
              { label: 'Getting Started', to: '/docs/getting-started/installation' },
              { label: 'Core Concepts', to: '/docs/core-concepts/actors' },
              { label: 'API Reference', to: '/docs/packages/core' },
            ],
          },
          {
            title: 'Community',
            items: [
              { label: 'GitHub', href: 'https://github.com/monadial/nexus' },
              { label: 'Issues', href: 'https://github.com/monadial/nexus/issues' },
            ],
          },
        ],
        copyright: `Copyright ${new Date().getFullYear()} Monadial. Built with Docusaurus.`,
      },
      prism: {
        theme: themes.github,
        darkTheme: themes.dracula,
        additionalLanguages: ['php'],
      },
    }),
};

module.exports = config;
