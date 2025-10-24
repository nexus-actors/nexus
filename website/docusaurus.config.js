// @ts-check

/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'Nexus',
  tagline: 'A production-grade actor system for PHP 8.5+',
  favicon: 'img/favicon.ico',

  url: 'https://nexus.monadial.com',
  baseUrl: '/',

  organizationName: 'monadial',
  projectName: 'nexus',

  onBrokenLinks: 'throw',
  onBrokenMarkdownLinks: 'warn',

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
            label: 'Documentation',
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
        theme: require('prism-react-renderer').themes.github,
        darkTheme: require('prism-react-renderer').themes.dracula,
        additionalLanguages: ['php'],
      },
    }),
};

module.exports = config;
