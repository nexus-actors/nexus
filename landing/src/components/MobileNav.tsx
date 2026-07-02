// landing/src/components/MobileNav.tsx
import { useState } from 'react';

interface Props {
  docsHref: string;
}

export default function MobileNav({ docsHref }: Props) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        type="button"
        aria-label={open ? 'Close menu' : 'Open menu'}
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="md:hidden flex items-center justify-center w-9 h-9 rounded-md text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors duration-150"
      >
        {open ? (
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        ) : (
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
          </svg>
        )}
      </button>

      {open && (
        <div className="md:hidden absolute top-full left-0 right-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 shadow-lg z-50 px-4 py-4">
          <nav className="flex flex-col gap-1">
            <a
              href="/bootstrap"
              className="inline-flex items-center px-4 py-2 rounded-full border border-emerald-500 text-emerald-600 dark:text-emerald-400 font-semibold text-sm hover:bg-emerald-500 hover:text-white transition-colors duration-150 w-fit mb-2"
            >
              Bootstrap
            </a>

            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500 mt-3 mb-1 px-2">
              Integrations
            </p>
            <a href="/http" className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
              HTTP
            </a>
            <a href="/doctrine" className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
              Doctrine
            </a>

            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500 mt-3 mb-1 px-2">
              Learn
            </p>
            <a href="/why-nexus" className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
              Why Nexus
            </a>
            <a href="/security" className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
              Security
            </a>
            <a href="/comparison" className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
              Comparison
            </a>
            <a href="/stability" className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
              Stability
            </a>
            <a href="/use-cases" className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
              Use Cases
            </a>
            <a href="/adoption" className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
              Adoption
            </a>

            <div className="border-t border-slate-200 dark:border-slate-700 mt-3 pt-3 flex flex-col gap-1">
              <a href={docsHref} className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
                Docs
              </a>
              <a href="https://github.com/nexus-actors/nexus" className="px-2 py-1.5 text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-800 rounded transition-colors duration-150">
                GitHub
              </a>
            </div>
          </nav>
        </div>
      )}
    </>
  );
}
