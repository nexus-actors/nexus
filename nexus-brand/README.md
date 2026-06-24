# nexus-brand

Shared design tokens for the Nexus documentation triumvirate:
- `nexusactors.com` — Astro landing (sub-spec 1)
- `docs.nexusactors.com` — Docusaurus docs (sub-spec 2)
- `api.nexusactors.com` — phpDocumentor API ref (sub-spec 4b)

## Contents

- `tokens.css` — CSS custom properties (`:root { --nexus-* }`). Import directly.
- `tokens.json` — same data for JS consumption (Tailwind themes, scripts).
- `logo.svg` / `logo-mark.svg` / `favicon.svg` — vector marks.
- `scripts/og-gen.mjs` — OG-image generator used by Astro landing + Docusaurus.

## Consumption

**Docusaurus** (`website/src/css/custom.css`):
```css
@import '../../../nexus-brand/tokens.css';
```

**Astro** (when sub-spec 1 ships):
```ts
import tokens from '../../../nexus-brand/tokens.json';
```

**phpDocumentor theme** (sub-spec 4b):
```bash
cp ../../../nexus-brand/tokens.css ./theme-overlay/tokens.css
```

## Status

V1. Logo + favicon are placeholders pending final design.

## Versioning

Repo-local; not published. Version-locks via the monorepo's git SHA.
