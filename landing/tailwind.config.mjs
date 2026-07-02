import tokens from '../nexus-brand/tokens.json' with { type: 'json' };

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{astro,html,js,jsx,md,mdx,ts,tsx}'],
  theme: {
    extend: {
      colors: {
        // Pin the landing-site emerald primary independently of brand tokens
        primary: '#10B981',
        'primary-dark': '#059669',
        accent: tokens.color.accent,
        success: tokens.color.success,
        warning: tokens.color.warning,
        danger: tokens.color.danger,
        info: tokens.color.info,
      },
      fontFamily: {
        sans: tokens.font.sans.split(',').map(f => f.trim()),
        mono: tokens.font.mono.split(',').map(f => f.trim()),
      },
      spacing: tokens.space,
    },
  },
  darkMode: 'class',
};
