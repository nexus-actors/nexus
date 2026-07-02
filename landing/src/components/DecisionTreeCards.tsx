// landing/src/components/DecisionTreeCards.tsx
import { useState } from 'react';
import { docsUrl } from '../lib/urls';

const PATHS = [
  {
    id: 'service',
    label: 'Build a service',
    detail: 'You have HTTP routes that need typed handlers, validation, and actor-backed workflows.',
    href: docsUrl('/http/getting-started'),
  },
  {
    id: 'persist',
    label: 'Persist state',
    detail: 'You want event sourcing or durable state with single-writer guarantees and replay.',
    href: docsUrl('/persistence/overview'),
  },
  {
    id: 'scale',
    label: 'Scale across cores',
    detail: "You're hitting one-process limits; multi-thread worker pool with hash-ring routing.",
    href: docsUrl('/scaling/overview'),
  },
  {
    id: 'explore',
    label: 'Just explore',
    detail: 'You want to learn the actor model from PHP-flavored docs and runnable examples.',
    href: docsUrl('/welcome'),
  },
];

export default function DecisionTreeCards() {
  const [hovered, setHovered] = useState<string | null>(null);
  return (
    <div className="grid sm:grid-cols-2 gap-4">
      {PATHS.map(p => (
        <a
          key={p.id}
          href={p.href}
          onMouseEnter={() => setHovered(p.id)}
          onMouseLeave={() => setHovered(null)}
          className={`block p-6 rounded-lg border transition ${
            hovered === p.id
              ? 'border-primary bg-slate-50 dark:bg-slate-800'
              : 'border-slate-200 dark:border-slate-700'
          }`}
        >
          <h3 className="text-lg font-bold mb-1">{p.label}</h3>
          <p className="text-sm text-slate-600 dark:text-slate-400">{p.detail}</p>
        </a>
      ))}
    </div>
  );
}
