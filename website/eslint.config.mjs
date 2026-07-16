import docusaurusPlugin from '@docusaurus/eslint-plugin';
import globals from 'globals';

export default [
  {
    ignores: ['build/', 'node_modules/', '.docusaurus/'],
  },
  {
    files: ['**/*.{js,cjs,mjs,jsx}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      parserOptions: {
        ecmaFeatures: {
          jsx: true,
        },
      },
      globals: {
        ...globals.browser,
        ...globals.node,
      },
    },
    plugins: {
      '@docusaurus': docusaurusPlugin,
    },
    rules: {
      ...docusaurusPlugin.configs.recommended.rules,
      // Docusaurus docs are intentionally not fully localized.
      '@docusaurus/string-literal-i18n-messages': 'off',
      // Existing homepage intentionally uses semantic heading tags.
      '@docusaurus/prefer-docusaurus-heading': 'off',
    },
  },
];
