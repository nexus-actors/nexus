module.exports = {
  root: true,
  extends: ['plugin:@docusaurus/recommended'],
  env: {
    browser: true,
    node: true,
    es2022: true,
  },
  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module',
    ecmaFeatures: {
      jsx: true,
    },
  },
  rules: {
    // Docusaurus docs are intentionally not fully localized.
    '@docusaurus/string-literal-i18n-messages': 'off',
    // Existing homepage intentionally uses semantic heading tags.
    '@docusaurus/prefer-docusaurus-heading': 'off',
  },
};
