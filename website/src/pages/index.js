import React from 'react';
import { Redirect } from '@docusaurus/router';
import useBaseUrl from '@docusaurus/useBaseUrl';

// The marketing landing lives at nexusactors.com (Astro). The docs root
// goes straight to the documentation.
export default function Home() {
  return <Redirect to={useBaseUrl('/docs/welcome')} />;
}
