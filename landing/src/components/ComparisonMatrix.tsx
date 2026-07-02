// landing/src/components/ComparisonMatrix.tsx
import { useState } from 'react';
import { docsUrl } from '../lib/urls';

type CellValue = '✓' | '✗' | 'planned — see roadmap' | string;

interface Row {
  feature: string;
  nexus: CellValue;
  amphp: CellValue;
  spiral: CellValue;
  swoole: CellValue;
  queue: CellValue;
}

const ROWS: Row[] = [
  {
    feature: 'Actor model',
    nexus: '✓',
    amphp: '✗',
    spiral: '✗',
    swoole: '✗',
    queue: '✗',
  },
  {
    feature: 'Supervision trees',
    nexus: '✓',
    amphp: '✗',
    spiral: '✗',
    swoole: '✗',
    queue: '✗',
  },
  {
    feature: 'Event sourcing (built-in)',
    nexus: '✓',
    amphp: '✗',
    spiral: '✗',
    swoole: '✗',
    queue: '✗',
  },
  {
    feature: 'Durable state persistence',
    nexus: '✓',
    amphp: '✗',
    spiral: '✗',
    swoole: '✗',
    queue: 'partial — manual',
  },
  {
    feature: 'HTTP integration (typed handlers)',
    nexus: '✓',
    amphp: '✓',
    spiral: '✓',
    swoole: 'partial — manual',
    queue: '✗',
  },
  {
    feature: 'WebSocket support',
    nexus: '✓',
    amphp: '✓',
    spiral: '✓',
    swoole: '✓',
    queue: '✗',
  },
  {
    feature: 'Doctrine ORM integration',
    nexus: '✓',
    amphp: '✗',
    spiral: '✓',
    swoole: '✗',
    queue: 'partial — sync only',
  },
  {
    feature: 'Psalm generics / type safety',
    nexus: '✓',
    amphp: 'partial',
    spiral: 'partial',
    swoole: '✗',
    queue: '✗',
  },
  {
    feature: 'Deterministic test runtime',
    nexus: '✓',
    amphp: '✗',
    spiral: '✗',
    swoole: '✗',
    queue: '✗',
  },
  {
    feature: 'Multi-worker scaling (hash ring)',
    nexus: '✓',
    amphp: '✗',
    spiral: '✓',
    swoole: 'partial — manual',
    queue: 'partial — manual',
  },
  {
    feature: 'Fiber runtime (no Swoole required)',
    nexus: '✓',
    amphp: '✓',
    spiral: '✗',
    swoole: '✗',
    queue: '✗',
  },
  {
    feature: 'Swoole runtime',
    nexus: '✓',
    amphp: '✗',
    spiral: '✓',
    swoole: '✓',
    queue: '✗',
  },
  {
    feature: 'Graceful shutdown semantics',
    nexus: '✓',
    amphp: 'partial',
    spiral: 'partial',
    swoole: 'partial — manual',
    queue: '✗',
  },
  {
    feature: 'Cluster / remote actors',
    nexus: 'planned — see roadmap',
    amphp: '✗',
    spiral: '✓',
    swoole: '✗',
    queue: '✗',
  },
  {
    feature: 'Persistence backends (DBAL, Doctrine)',
    nexus: '✓',
    amphp: '✗',
    spiral: 'partial',
    swoole: '✗',
    queue: 'partial — manual',
  },
];

type ColKey = keyof Row;

const COLUMNS: { key: ColKey; label: string }[] = [
  { key: 'feature', label: 'Feature' },
  { key: 'nexus', label: 'Nexus' },
  { key: 'amphp', label: 'Amphp' },
  { key: 'spiral', label: 'Spiral RoadRunner' },
  { key: 'swoole', label: 'Raw Swoole' },
  { key: 'queue', label: 'Queue + Cron + Workers' },
];

function cellScore(v: CellValue): number {
  if (v === '✓') return 2;
  if (v === 'planned — see roadmap') return 1;
  if (v === '✗') return -1;
  return 0; // partial or other strings
}

function CellBadge({ value }: { value: CellValue }) {
  if (value === '✓') {
    return (
      <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400 font-semibold">
        <svg className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clipRule="evenodd" />
        </svg>
        Yes
      </span>
    );
  }
  if (value === '✗') {
    return (
      <span className="inline-flex items-center gap-1 text-slate-500 dark:text-slate-300">
        <svg className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
        </svg>
        No
      </span>
    );
  }
  if (value === 'planned — see roadmap') {
    return (
      <span className="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400 text-sm">
        <svg className="w-4 h-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fillRule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z" clipRule="evenodd" />
        </svg>
        <a href={docsUrl('/contributing/roadmap')} className="underline underline-offset-2 hover:text-amber-700 dark:hover:text-amber-300">
          planned — see roadmap
        </a>
      </span>
    );
  }
  return <span className="text-slate-600 dark:text-slate-300 text-sm">{value}</span>;
}

export default function ComparisonMatrix() {
  const [sortCol, setSortCol] = useState<ColKey>('feature');
  const [sortAsc, setSortAsc] = useState(true);

  function handleSort(col: ColKey) {
    if (sortCol === col) {
      setSortAsc(a => !a);
    } else {
      setSortCol(col);
      setSortAsc(true);
    }
  }

  const sorted = [...ROWS].sort((a, b) => {
    const av = a[sortCol];
    const bv = b[sortCol];
    let cmp: number;
    if (sortCol === 'feature') {
      cmp = (av as string).localeCompare(bv as string);
    } else {
      cmp = cellScore(bv as CellValue) - cellScore(av as CellValue); // higher score first by default
    }
    return sortAsc ? cmp : -cmp;
  });

  function SortIcon({ col }: { col: ColKey }) {
    if (sortCol !== col) {
      return (
        <svg className="w-3 h-3 text-slate-400 inline ml-1" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fillRule="evenodd" d="M10 3a.75.75 0 0 1 .55.24l3.25 3.5a.75.75 0 1 1-1.1 1.02L10 4.852 7.3 7.76a.75.75 0 0 1-1.1-1.02l3.25-3.5A.75.75 0 0 1 10 3Zm-3.76 9.2a.75.75 0 0 1 1.06.04l2.7 2.908 2.7-2.908a.75.75 0 1 1 1.1 1.02l-3.25 3.5a.75.75 0 0 1-1.1 0l-3.25-3.5a.75.75 0 0 1 .04-1.06Z" clipRule="evenodd" />
        </svg>
      );
    }
    return sortAsc ? (
      <svg className="w-3 h-3 text-primary inline ml-1" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fillRule="evenodd" d="M10 17a.75.75 0 0 1-.55-.24l-3.25-3.5a.75.75 0 1 1 1.1-1.02L10 15.148l2.7-2.908a.75.75 0 0 1 1.1 1.02l-3.25 3.5A.75.75 0 0 1 10 17Z" clipRule="evenodd" />
      </svg>
    ) : (
      <svg className="w-3 h-3 text-primary inline ml-1" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fillRule="evenodd" d="M10 3a.75.75 0 0 1 .55.24l3.25 3.5a.75.75 0 1 1-1.1 1.02L10 4.852 7.3 7.76a.75.75 0 0 1-1.1-1.02l3.25-3.5A.75.75 0 0 1 10 3Z" clipRule="evenodd" />
      </svg>
    );
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
      <table className="min-w-full text-sm">
        <thead>
          <tr className="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
            {COLUMNS.map(col => (
              <th
                key={col.key}
                scope="col"
                aria-sort={sortCol === col.key ? (sortAsc ? 'ascending' : 'descending') : 'none'}
                className={`px-4 py-3 text-left font-semibold whitespace-nowrap ${
                  col.key === 'nexus' ? 'text-primary' : 'text-slate-700 dark:text-slate-300'
                }`}
              >
                <button
                  type="button"
                  onClick={() => handleSort(col.key)}
                  className="inline-flex items-center gap-1 font-semibold hover:text-emerald-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 rounded"
                  aria-label={`Sort by ${col.label}, currently ${sortCol === col.key ? (sortAsc ? 'sorted ascending' : 'sorted descending') : 'unsorted'}`}
                >
                  {col.label}
                  <SortIcon col={col.key} />
                </button>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {sorted.map((row, i) => (
            <tr
              key={row.feature}
              className={`border-b border-slate-100 dark:border-slate-800 ${
                i % 2 === 0 ? '' : 'bg-slate-50/50 dark:bg-slate-800/30'
              }`}
            >
              <td className="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">
                {row.feature}
              </td>
              <td className="px-4 py-3 bg-emerald-50/30 dark:bg-emerald-950/20">
                <CellBadge value={row.nexus} />
              </td>
              <td className="px-4 py-3">
                <CellBadge value={row.amphp} />
              </td>
              <td className="px-4 py-3">
                <CellBadge value={row.spiral} />
              </td>
              <td className="px-4 py-3">
                <CellBadge value={row.swoole} />
              </td>
              <td className="px-4 py-3">
                <CellBadge value={row.queue} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="px-4 py-2 text-xs text-slate-500 dark:text-slate-500 border-t border-slate-100 dark:border-slate-800">
        Activate any column header to sort. "planned — see roadmap" = capability not yet shipped in a stable release.
        Competitor data reflects public documentation as of June 2026.
      </p>
    </div>
  );
}
