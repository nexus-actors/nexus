// landing/src/components/NavDropdown.tsx
import { useState, useEffect, useRef, useCallback } from 'react';

interface NavDropdownItem {
  label: string;
  href: string;
  description?: string;
}

interface Props {
  label: string;
  items: NavDropdownItem[];
}

export default function NavDropdown({ label, items }: Props) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const hoverOpenTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const hoverCloseTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const focusedIndex = useRef<number>(-1);
  const itemRefs = useRef<(HTMLAnchorElement | null)[]>([]);

  const clearTimers = () => {
    if (hoverOpenTimer.current) clearTimeout(hoverOpenTimer.current);
    if (hoverCloseTimer.current) clearTimeout(hoverCloseTimer.current);
  };

  const close = useCallback(() => {
    setOpen(false);
    focusedIndex.current = -1;
  }, []);

  // Close on outside click
  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        close();
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open, close]);

  const handleTriggerMouseEnter = () => {
    clearTimers();
    hoverOpenTimer.current = setTimeout(() => setOpen(true), 100);
  };

  const handleMouseLeave = () => {
    clearTimers();
    hoverCloseTimer.current = setTimeout(() => close(), 200);
  };

  const handleMouseEnterPanel = () => {
    clearTimers();
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (!open) {
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
        e.preventDefault();
        setOpen(true);
        focusedIndex.current = 0;
        setTimeout(() => itemRefs.current[0]?.focus(), 0);
      }
      return;
    }
    if (e.key === 'Escape') {
      close();
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      const next = Math.min(focusedIndex.current + 1, items.length - 1);
      focusedIndex.current = next;
      itemRefs.current[next]?.focus();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      const prev = Math.max(focusedIndex.current - 1, 0);
      focusedIndex.current = prev;
      itemRefs.current[prev]?.focus();
    }
  };

  return (
    <div
      ref={containerRef}
      className="relative"
      onMouseLeave={handleMouseLeave}
    >
      <button
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        onMouseEnter={handleTriggerMouseEnter}
        onKeyDown={handleKeyDown}
        className="flex items-center gap-1 whitespace-nowrap text-sm text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors duration-150 cursor-pointer select-none"
      >
        {label}
        <svg
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 20 20"
          fill="currentColor"
          className={`w-4 h-4 transition-transform duration-150 ${open ? 'rotate-180' : ''}`}
          aria-hidden="true"
        >
          <path
            fillRule="evenodd"
            d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
            clipRule="evenodd"
          />
        </svg>
      </button>

      {open && (
        <div
          role="menu"
          onMouseEnter={handleMouseEnterPanel}
          className="absolute top-full right-0 mt-2 min-w-[220px] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg py-2 z-50"
        >
          {items.map((item, i) => (
            <a
              key={item.href}
              ref={(el) => { itemRefs.current[i] = el; }}
              href={item.href}
              role="menuitem"
              onFocus={() => { focusedIndex.current = i; }}
              className="block px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-700 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors duration-150 outline-none focus:bg-slate-50 dark:focus:bg-slate-700 focus:text-emerald-600 dark:focus:text-emerald-400"
            >
              <span className="block text-sm font-medium text-slate-800 dark:text-slate-200 group-hover:text-emerald-600">
                {item.label}
              </span>
              {item.description && (
                <span className="block text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                  {item.description}
                </span>
              )}
            </a>
          ))}
        </div>
      )}
    </div>
  );
}
