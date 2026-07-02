// @ts-check
const path = require('path');
const { themes } = require('prism-react-renderer');

/** @type {import('@docusaurus/types').Config} */
const config = async () => {
  // Load ESM remark plugins (unist-util-visit v5 is ESM-only)
  const glossaryAutoLink = await require('./src/plugins/glossary-auto-link')();
  const classNameAutoLink = await require('./src/plugins/class-name-auto-link')();

  return {
  title: 'Nexus — Actor System for PHP',
  tagline: 'Type-safe actors, supervision trees, event sourcing, and pluggable runtimes for PHP 8.5+',
  favicon: 'img/favicon.svg',

  url: 'https://docs.nexusactors.com',
  baseUrl: '/',

  organizationName: 'nexus-actors',
  projectName: 'nexus',

  onBrokenLinks: 'throw',

  staticDirectories: ['static'],

  markdown: {
    mermaid: true,
    hooks: {
      onBrokenMarkdownLinks: 'warn',
    },
  },

  themes: [
    '@docusaurus/theme-mermaid',
    [
      '@easyops-cn/docusaurus-search-local',
      {
        hashed: true,
        docsRouteBasePath: '/docs',
        indexBlog: false,
        highlightSearchTermsOnTargetPage: true,
        explicitSearchResultPath: true,
        searchBarShortcut: true,
        searchBarShortcutHint: true,
      },
    ],
  ],
  plugins: [
    require.resolve('./src/plugins/og-images.js'),
    [
      '@docusaurus/plugin-client-redirects',
      {
        redirects: [
          // Subsequent tasks append entries here as they move pages.
          { from: '/docs/examples', to: '/docs/tutorials/overview' },
          { from: '/docs/examples/overview', to: '/docs/tutorials/overview' },
          { from: '/docs/examples/wallet-app', to: '/docs/tutorials/wallet-app' },
          { from: '/docs/best-practices/message-design', to: '/docs/guides/message-design' },
          { from: '/docs/best-practices/ask-vs-tell', to: '/docs/guides/ask-vs-tell' },
          { from: '/docs/best-practices/single-writer-aggregates', to: '/docs/guides/single-writer-aggregates' },
          { from: '/docs/core-concepts/persistence', to: '/docs/persistence/overview' },
          { from: '/docs/http/observability', to: '/docs/operations/observability' },
          { from: '/docs/http/production', to: '/docs/operations/deployment' },
          { from: '/docs/http/performance', to: '/docs/operations/performance-tuning' },
          { from: '/docs/architecture/performance', to: '/docs/operations/performance-tuning' },
          { from: '/docs/runtimes/runtime-without-actors', to: '/docs/runtimes/standalone' },
          { from: '/docs/runtimes/runtime-standalone', to: '/docs/runtimes/standalone' },
          { from: '/docs/intro', to: '/docs/welcome' },
        ],
      },
    ],
    function preferVscodeLsTypesEsm() {
      return {
        name: 'prefer-vscode-ls-types-esm',
        configureWebpack() {
          const esmEntry = path.resolve(
            __dirname,
            'node_modules/vscode-languageserver-types/lib/esm/main.js',
          );

          return {
            resolve: {
              alias: {
                'vscode-languageserver-types$': esmEntry,
                'vscode-languageserver-types/lib/umd/main.js': esmEntry,
              },
            },
          };
        },
      };
    },
  ],

  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },

  headTags: [
    {
      tagName: 'script',
      attributes: { type: 'application/ld+json' },
      innerHTML: JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'SoftwareSourceCode',
        'name': 'Nexus',
        'description': 'A production-grade typed actor system for PHP 8.5+ inspired by Akka and Erlang/OTP. Type-safe actors, supervision trees, event sourcing, and pluggable runtimes.',
        'url': 'https://nexusactors.com',
        'codeRepository': 'https://github.com/nexus-actors/nexus',
        'programmingLanguage': {
          '@type': 'ComputerLanguage',
          'name': 'PHP',
        },
        'license': 'https://opensource.org/licenses/MIT',
        'author': {
          '@type': 'Organization',
          'name': 'Monadial',
          'url': 'https://monadial.com',
        },
        'keywords': [
          'actor model',
          'PHP',
          'concurrency',
          'supervision trees',
          'event sourcing',
          'Akka',
          'Erlang OTP',
          'Swoole',
          'PHP Fibers',
          'typed actors',
        ],
      }),
    },
    {
      tagName: 'script',
      attributes: { type: 'application/ld+json' },
      innerHTML: JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'WebSite',
        'name': 'Nexus',
        'url': 'https://nexusactors.com',
        'description': 'Documentation for Nexus, a typed actor system for PHP 8.5+',
        'publisher': {
          '@type': 'Organization',
          'name': 'Monadial',
          'url': 'https://monadial.com',
        },
      }),
    },
  ],

  presets: [
    [
      'classic',
      /** @type {import('@docusaurus/preset-classic').Options} */
      ({
        docs: {
          sidebarPath: './sidebars.js',
          editUrl: 'https://github.com/nexus-actors/nexus/tree/main/website/',
          remarkPlugins: [
            glossaryAutoLink,   // Task 8: auto-link glossary terms
            classNameAutoLink,  // Task 9: auto-link PHP class names to api.nexusactors.com
          ],
        },
        blog: false,
        theme: {
          customCss: './src/css/custom.css',
        },
        sitemap: {
          lastmod: null,
          changefreq: 'weekly',
          priority: 0.5,
          filename: 'sitemap.xml',
        },
      }),
    ],
  ],

  themeConfig:
    /** @type {import('@docusaurus/preset-classic').ThemeConfig} */
    ({
      colorMode: {
        defaultMode: 'dark',
        respectPrefersColorScheme: true,
      },
      image: 'img/og-default.svg',
      metadata: [
        { name: 'keywords', content: 'actor model, PHP, concurrency, supervision trees, event sourcing, Akka, Erlang OTP, Swoole, PHP Fibers, typed actors, PHP framework' },
        { name: 'author', content: 'Monadial' },
        { property: 'og:type', content: 'website' },
        { property: 'og:site_name', content: 'Nexus' },
        { property: 'og:locale', content: 'en_US' },
        { name: 'twitter:card', content: 'summary_large_image' },
        { name: 'twitter:title', content: 'Nexus — Actor System for PHP' },
        { name: 'twitter:description', content: 'Type-safe actors, supervision trees, event sourcing, and pluggable runtimes for PHP 8.5+. Akka/OTP patterns brought to PHP.' },
      ],
      navbar: {
        title: 'Nexus',
        logo: {
          alt: 'Nexus',
          src: 'img/logo.svg',
          width: 48,
          height: 48,
        },
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
            to: '/docs/http/overview',
            label: 'HTTP',
            position: 'left',
          },
          {
            to: '/docs/packages/core',
            label: 'API',
            position: 'left',
          },
          {
            href: 'https://github.com/nexus-actors/nexus',
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
              { label: 'GitHub', href: 'https://github.com/nexus-actors/nexus' },
              { label: 'Issues', href: 'https://github.com/nexus-actors/nexus/issues' },
            ],
          },
        ],
        copyright: `Copyright ${new Date().getFullYear()} Monadial. Built with ❤️ at <a href="https://monadial.com" target="_blank" rel="noopener noreferrer">Monadial</a>.`,
      },
      prism: {
        theme: themes.github,
        darkTheme: themes.oneDark,
        additionalLanguages: ['php', 'bash', 'yaml', 'json'],
      },
    }),
  };
};

module.exports = config;
