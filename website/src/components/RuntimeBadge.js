import React from 'react';

const COLORS = {
  fiber: '#10b981',
  swoole: '#6366f1',
  step: '#94a3b8',
};

export default function RuntimeBadge({ runtimes = [] }) {
  if (!runtimes.length) return null;
  return (
    <div style={{display: 'flex', gap: '0.5rem', margin: '0.5rem 0'}} aria-label="Runtime applicability">
      <span style={{fontSize: '0.85rem', color: 'var(--nexus-text-muted, var(--ifm-color-content-secondary))'}}>Runs on:</span>
      {runtimes.map(r => (
        <span key={r} style={{
          background: COLORS[r] || 'var(--nexus-primary, var(--ifm-color-primary))',
          color: 'white',
          padding: '0.125rem 0.5rem',
          borderRadius: '0.25rem',
          fontSize: '0.85rem',
          fontWeight: 500,
        }}>{r}</span>
      ))}
    </div>
  );
}
