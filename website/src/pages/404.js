// website/src/pages/404.js
// Custom Dead-Letter themed 404 page for docs.nexusactors.com.
// References the DeadLetterRef concept — undeliverable messages end up there,
// just like this unresolvable URL.

import React from 'react';
import Layout from '@theme/Layout';
import Link from '@docusaurus/Link';

export default function NotFound() {
  return (
    <Layout title="Dead Letter — 404">
      <main
        style={{
          textAlign: 'center',
          padding: '4rem 1rem',
          maxWidth: 600,
          margin: '0 auto',
        }}
      >
        <div style={{ fontSize: '5rem', lineHeight: 1, margin: 0 }} role="img" aria-label="mailbox">
          📭
        </div>
        <h2 style={{ margin: '1rem 0 0.5rem' }}>404 — Dead Letter</h2>
        <p style={{ color: 'var(--nexus-text-muted, #6b7280)', fontSize: '1.1rem', marginTop: '1rem' }}>
          The page you asked for does not exist. In Nexus, undeliverable messages
          end up in the{' '}
          <Link to="/docs/core-concepts/dead-letters">
            <code>DeadLetterRef</code>
          </Link>{' '}
          for inspection — same idea here.
        </p>
        <p style={{ color: 'var(--nexus-text-muted, #6b7280)', fontSize: '0.95rem' }}>
          Check the URL, or head back to the docs.
        </p>
        <p style={{ marginTop: '2.5rem' }}>
          <Link
            to="/docs/welcome"
            style={{
              background: 'var(--nexus-primary, #6366f1)',
              color: 'white',
              padding: '0.75rem 1.5rem',
              borderRadius: '0.5rem',
              textDecoration: 'none',
              fontWeight: 600,
              display: 'inline-block',
            }}
          >
            Back to docs
          </Link>
        </p>
      </main>
    </Layout>
  );
}
