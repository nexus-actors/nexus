const DEFAULT_DOCS_URL = 'https://docs.nexusactors.com/docs';
const DEFAULT_API_URL = 'https://api.nexusactors.com';

const stripTrailingSlash = (url: string): string => url.replace(/\/+$/, '');
const ensureLeadingSlash = (path: string): string =>
  path === '' || path.startsWith('/') ? path : `/${path}`;

const docsBase: string = stripTrailingSlash(
  (import.meta.env.PUBLIC_DOCS_URL as string | undefined) ?? DEFAULT_DOCS_URL,
);
const apiBase: string = stripTrailingSlash(
  (import.meta.env.PUBLIC_API_URL as string | undefined) ?? DEFAULT_API_URL,
);

export const docsUrl = (path: string = ''): string => `${docsBase}${ensureLeadingSlash(path)}`;
export const apiUrl = (path: string = ''): string => `${apiBase}${ensureLeadingSlash(path)}`;
