# Nexus landing — nexusactors.com

Astro 5 static site for the apex marketing domain. Sibling to `website/`
(Docusaurus docs at `docs.nexusactors.com`).

## Develop

```bash
cd landing
npm install
npm run dev   # http://localhost:4321
```

## Build

```bash
npm run build       # output → dist/
npm run preview     # serve dist/ for preview
```

## Deploy

Cloudflare Pages project `nexus-actors-landing`. CI workflow at
`.github/workflows/landing-deploy.yml`.
