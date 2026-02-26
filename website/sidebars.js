/** @type {import('@docusaurus/plugin-content-docs').SidebarsConfig} */
const sidebars = {
  docsSidebar: [
    'intro',
    {
      type: 'category',
      label: 'Getting Started',
      items: [
        'getting-started/installation',
        'getting-started/quick-start',
        'getting-started/persistent-actors',
        'getting-started/concepts',
      ],
    },
    {
      type: 'category',
      label: 'Core Concepts',
      items: [
        'core-concepts/nexus-thesis',
        'core-concepts/actors',
        'core-concepts/behaviors',
        'core-concepts/props',
        'core-concepts/supervision',
        'core-concepts/mailboxes',
        'core-concepts/lifecycle',
        'core-concepts/ask-pattern',
        'core-concepts/persistence',
      ],
    },
    {
      type: 'category',
      label: 'Runtimes',
      items: [
        'runtimes/overview',
        'runtimes/bootstrap',
        'runtimes/runtime-standalone',
        'runtimes/runtime-without-actors',
        'runtimes/fiber',
        'runtimes/swoole',
        'runtimes/step',
      ],
    },
    {
      type: 'category',
      label: 'Scaling',
      items: [
        'scaling/overview',
        'scaling/configuration',
        'scaling/bootstrap',
      ],
    },
    {
      type: 'category',
      label: 'Packages',
      items: [
        'packages/core',
        'packages/runtime',
        'packages/runtime-fiber',
        'packages/runtime-swoole',
        'packages/runtime-step',
        'packages/cluster',
        'packages/cluster-swoole',
        'packages/serialization',
        'packages/persistence',
        'packages/persistence-dbal',
        'packages/persistence-doctrine',
        'packages/app',
        'packages/psalm',
      ],
    },
    {
      type: 'category',
      label: 'Architecture',
      items: [
        'architecture/design-philosophy',
        'architecture/internals',
        'architecture/performance',
      ],
    },
    {
      type: 'category',
      label: 'Contributing',
      items: [
        'contributing/development',
        'contributing/roadmap',
      ],
    },
  ],
};

module.exports = sidebars;
