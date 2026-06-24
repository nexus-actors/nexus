import React from 'react';
import Footer from '@theme-original/DocItem/Footer';
import {useDoc} from '@docusaurus/plugin-content-docs/client';
import useBaseUrl from '@docusaurus/useBaseUrl';

export default function FooterWrapper(props) {
  const {frontMatter} = useDoc();
  const related = frontMatter.related || [];
  const base = useBaseUrl('/docs/');
  return (
    <>
      {related.length > 0 && (
        <div style={{margin: '2rem 0', padding: '1.5rem', background: 'var(--nexus-bg-elevated, var(--ifm-background-surface-color))', borderRadius: '0.5rem'}}>
          <div style={{fontWeight: 700, marginBottom: '0.75rem'}}>Related pages</div>
          <ul style={{margin: 0, paddingLeft: '1.25rem'}}>
            {related.map(slug => (
              <li key={slug}>
                <a href={`${base}${slug}`}>{slug.replace(/-/g, ' ')}</a>
              </li>
            ))}
          </ul>
        </div>
      )}
      <Footer {...props} />
    </>
  );
}
