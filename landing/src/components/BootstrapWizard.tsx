// landing/src/components/BootstrapWizard.tsx
import { useState, useEffect } from 'react';
import IDEEditor from './IDEEditor';
import { generate, generateCreateCommand, DEFAULT_SELECTIONS } from '../lib/bootstrapConfig';
import type { Selections, Runtime, Persistence } from '../lib/bootstrapConfig';

// ── docsUrl mirror (can't import server-only helpers in a React island) ───────
// Astro inlines PUBLIC_* env vars into client bundles at build time.
const DOCS_BASE = import.meta.env.PUBLIC_DOCS_URL || 'https://docs.nexusactors.com/docs';
function docsUrl(path = '') {
  const base = (DOCS_BASE as string).replace(/\/+$/, '');
  return `${base}${path.startsWith('/') ? path : path ? `/${path}` : ''}`;
}

// ── Step labels ──────────────────────────────────────────────────────────────
const STEPS = ['Runtime', 'I/O & integrations', 'Persistence', 'Review & copy'];

// ── Small UI helpers ─────────────────────────────────────────────────────────

function StepBar({ current, onClick }: { current: number; onClick: (i: number) => void }) {
  return (
    <nav aria-label="Bootstrap progress" className="wiz-stepbar">
      {STEPS.map((label, i) => {
        const done = i < current;
        const active = i === current;
        return (
          <button
            key={i}
            className={`wiz-step ${active ? 'wiz-step--active' : done ? 'wiz-step--done' : 'wiz-step--future'}`}
            onClick={() => (done || active) && onClick(i)}
            disabled={!done && !active}
            aria-current={active ? 'step' : undefined}
          >
            <span className="wiz-step-num">
              {done ? (
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                  <path d="M2.5 7l3 3 6-6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              ) : (
                i + 1
              )}
            </span>
            <span className="wiz-step-label">{label}</span>
            {i < STEPS.length - 1 && <span className="wiz-step-sep" aria-hidden="true" />}
          </button>
        );
      })}
    </nav>
  );
}

function RadioCard({
  selected,
  onSelect,
  title,
  description,
}: {
  selected: boolean;
  onSelect: () => void;
  title: string;
  description: string;
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      className={`wiz-radio-card ${selected ? 'wiz-radio-card--selected' : ''}`}
    >
      <span className="wiz-radio-dot" aria-hidden="true">
        {selected && (
          <svg width="10" height="10" viewBox="0 0 10 10">
            <circle cx="5" cy="5" r="3.5" fill="currentColor" />
          </svg>
        )}
      </span>
      <span className="wiz-card-text">
        <span className="wiz-card-title">{title}</span>
        <span className="wiz-card-desc">{description}</span>
      </span>
    </button>
  );
}

function CheckCard({
  checked,
  disabled,
  onToggle,
  title,
  description,
}: {
  checked: boolean;
  disabled?: boolean;
  onToggle: () => void;
  title: string;
  description: string;
}) {
  return (
    <button
      type="button"
      onClick={onToggle}
      disabled={disabled}
      className={`wiz-radio-card ${checked ? 'wiz-radio-card--selected' : ''} ${disabled ? 'wiz-radio-card--disabled' : ''}`}
    >
      <span className="wiz-check-box" aria-hidden="true">
        {checked && (
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
            <path d="M2 6l3 3 5-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        )}
      </span>
      <span className="wiz-card-text">
        <span className="wiz-card-title">{title}</span>
        <span className="wiz-card-desc">{description}</span>
      </span>
    </button>
  );
}

// ── Step components ──────────────────────────────────────────────────────────

function Step1({ sel, setSel }: { sel: Selections; setSel: (s: Selections) => void }) {
  const set = (runtime: Runtime) => setSel({ ...sel, runtime });
  return (
    <div className="wiz-step-body">
      <h2 className="wiz-step-heading">Choose a runtime</h2>
      <div className="wiz-cards">
        <RadioCard
          selected={sel.runtime === 'fiber'}
          onSelect={() => set('fiber')}
          title="Fiber"
          description="Local dev, single process. Cooperative scheduling on PHP fibers."
        />
        <RadioCard
          selected={sel.runtime === 'swoole'}
          onSelect={() => set('swoole')}
          title="Swoole"
          description="Production: coroutines + true async I/O. Requires ext-swoole ≥ 6.2.1."
        />
        <RadioCard
          selected={sel.runtime === 'worker-pool'}
          onSelect={() => set('worker-pool')}
          title="Swoole worker pool"
          description="Production at scale: N Swoole threads sharing a hash ring. Requires ZTS PHP + Swoole ≥ 6.2.1 with --enable-swoole-thread."
        />
      </div>
    </div>
  );
}

function Step2({ sel, setSel }: { sel: Selections; setSel: (s: Selections) => void }) {
  const toggle = (key: 'http' | 'websockets' | 'doctrine' | 'otel') => {
    const next = { ...sel, [key]: !sel[key] };
    // WebSockets requires HTTP
    if (key === 'http' && !next.http) next.websockets = false;
    setSel(next);
  };
  return (
    <div className="wiz-step-body">
      <h2 className="wiz-step-heading">Select I/O &amp; integrations</h2>
      <div className="wiz-cards">
        <CheckCard
          checked={sel.http}
          onToggle={() => toggle('http')}
          title="HTTP server"
          description="PSR-15 routing + ask()-based request handling."
        />
        <CheckCard
          checked={sel.websockets}
          disabled={!sel.http}
          onToggle={() => sel.http && toggle('websockets')}
          title="WebSockets"
          description={sel.http ? 'Long-lived connections via the same actor topology.' : 'Requires HTTP server (enable above first).'}
        />
        <CheckCard
          checked={sel.doctrine}
          onToggle={() => toggle('doctrine')}
          title="Doctrine ORM bridge"
          description="EntityBehavior + per-actor EntityManager helpers."
        />
        <CheckCard
          checked={false}
          onToggle={() => {}}
          disabled
          title="OpenTelemetry tracing"
          description="Available soon — package not yet on Packagist. Track at github.com/nexus-actors/nexus."
        />
      </div>
    </div>
  );
}

function Step3({ sel, setSel }: { sel: Selections; setSel: (s: Selections) => void }) {
  const set = (persistence: Persistence) => setSel({ ...sel, persistence });
  return (
    <div className="wiz-step-body">
      <h2 className="wiz-step-heading">Choose persistence</h2>
      <div className="wiz-cards">
        <RadioCard
          selected={sel.persistence === 'none'}
          onSelect={() => set('none')}
          title="None"
          description="Pure in-memory actors. Pick this for stateless services or pipelines."
        />
        <RadioCard
          selected={sel.persistence === 'es-dbal'}
          onSelect={() => set('es-dbal')}
          title="Event sourcing (DBAL)"
          description="Append-only event log via Doctrine DBAL. Best for greenfield."
        />
        <RadioCard
          selected={sel.persistence === 'es-doctrine'}
          onSelect={() => set('es-doctrine')}
          title="Event sourcing (Doctrine ORM)"
          description="Same model, but uses your existing Doctrine ORM connection."
        />
        <RadioCard
          selected={sel.persistence === 'ds-dbal'}
          onSelect={() => set('ds-dbal')}
          title="Durable state (DBAL)"
          description="Persists state snapshots only. Simpler than event sourcing."
        />
        <RadioCard
          selected={sel.persistence === 'ds-doctrine'}
          onSelect={() => set('ds-doctrine')}
          title="Durable state (Doctrine ORM)"
          description="Snapshots via existing Doctrine ORM."
        />
      </div>
    </div>
  );
}

const RUNTIME_CHIP: Record<string, string> = {
  'fiber': 'Fiber',
  'swoole': 'Swoole',
  'worker-pool': 'Swoole worker pool',
};
const PERSISTENCE_CHIP: Record<string, string> = {
  'none': '',
  'es-dbal': 'Event sourcing (DBAL)',
  'es-doctrine': 'Event sourcing (Doctrine ORM)',
  'ds-dbal': 'Durable state (DBAL)',
  'ds-doctrine': 'Durable state (Doctrine ORM)',
};

function RecapChips({ sel }: { sel: Selections }) {
  const chips: string[] = [`Runtime: ${RUNTIME_CHIP[sel.runtime]}`];
  if (sel.http) chips.push('HTTP');
  if (sel.websockets) chips.push('WebSockets');
  if (sel.doctrine) chips.push('Doctrine ORM');
  // OTel package not yet published — omit chip until functional
  if (sel.persistence !== 'none') chips.push(`Persistence: ${PERSISTENCE_CHIP[sel.persistence]}`);
  return (
    <div className="wiz-chips">
      {chips.map((c) => (
        <span key={c} className="wiz-chip">{c}</span>
      ))}
    </div>
  );
}

function CopyButton({ getText }: { getText: () => string }) {
  const [label, setLabel] = useState('Copy');
  const copy = () => {
    navigator.clipboard.writeText(getText()).then(() => {
      setLabel('Copied!');
      setTimeout(() => setLabel('Copy'), 2000);
    });
  };
  return (
    <button type="button" onClick={copy} className="wiz-copy-btn">
      {label}
    </button>
  );
}

const TAB_NAMES = ['composer-require.sh', 'bootstrap.php', 'docker-compose.yml', 'README.md'];
const TAB_LANGS = ['bash', 'php', 'yaml', 'markdown'];

function Step4({ sel, onReset }: { sel: Selections; onReset: () => void }) {
  const arts = generate(sel);
  const createCmd = generateCreateCommand(sel);
  const contents = [arts.composer, arts.bootstrap, arts.compose, arts.readme];
  const [activeIdx, setActiveIdx] = useState(0);
  const [cmdCopyLabel, setCmdCopyLabel] = useState('Copy');

  const copyCmd = () => {
    navigator.clipboard.writeText(createCmd).then(() => {
      setCmdCopyLabel('Copied!');
      setTimeout(() => setCmdCopyLabel('Copy'), 2000);
    });
  };

  const files = TAB_NAMES.map((name, i) => ({
    name,
    language: TAB_LANGS[i],
    code: contents[i],
  }));

  return (
    <div className="wiz-step-body">
      <h2 className="wiz-step-heading">Your generated artifacts</h2>
      <RecapChips sel={sel} />

      {/* ── Packagist availability note ── */}
      <div className="wiz-packagist-note">
        <strong>Heads up:</strong> <code>nexus-actors/*</code> packages are not yet on Packagist — this is the install path that will work once V1 ships. Want early access?{' '}
        <a href="https://github.com/nexus-actors/nexus/issues" target="_blank" rel="noopener noreferrer">Open an issue</a>.
      </div>

      {/* ── WebSockets skeleton warning ── */}
      {sel.websockets && (
        <div className="wiz-packagist-note" style={{ borderColor: '#f59e0b', background: 'rgba(120,53,15,0.4)' }}>
          <strong>Note:</strong> WebSockets is not yet supported by the one-line installer.
          Use the advanced <em>wire-it-yourself</em> artifacts below instead — they include the correct <code>nexus-actors/http-ws</code> wiring.
        </div>
      )}

      {/* ── Primary callout: composer create-project ── */}
      <div className="wiz-create-card">
        <div className="wiz-create-header">
          <span className="wiz-create-badge">Recommended</span>
          <span className="wiz-create-title">One-line install</span>
        </div>
        <pre className="wiz-create-pre"><code>{createCmd}</code></pre>
        <button type="button" onClick={copyCmd} className="wiz-create-copy">
          {cmdCopyLabel}
        </button>
      </div>

      {/* ── Secondary: raw artifacts behind a disclosure ── */}
      <details className="wiz-details">
        <summary className="wiz-details-summary">
          Or wire it yourself — advanced (composer require, bootstrap.php, docker-compose, README)
        </summary>
        <div className="wiz-details-body">
          <div className="wiz-ide-wrap">
            <div className="wiz-ide-toolbar">
              <span className="wiz-ide-label">Generated files</span>
              <CopyButton getText={() => contents[activeIdx]} />
            </div>
            <IDEEditor
              files={files}
              defaultIndex={activeIdx}
              height="420px"
              onActiveChange={setActiveIdx}
            />
          </div>
        </div>
      </details>

      <div className="wiz-step4-actions">
        <button type="button" onClick={onReset} className="wiz-reset-link">
          ← Start over
        </button>
        <a
          href={docsUrl('/getting-started/installation')}
          className="wiz-cta-btn"
          target="_blank"
          rel="noopener noreferrer"
        >
          Open the install guide →
        </a>
      </div>
    </div>
  );
}

// ── Main wizard ──────────────────────────────────────────────────────────────

export default function BootstrapWizard() {
  const [step, setStep] = useState(0);
  const [sel, setSel] = useState<Selections>(DEFAULT_SELECTIONS);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);

    const requested = Number(params.get('step'));
    if (Number.isFinite(requested) && requested >= 1 && requested <= STEPS.length) {
      setStep(requested - 1);
    }

    const runtimeParam = params.get('runtime');
    const persistenceParam = params.get('persistence');
    const next: Selections = { ...DEFAULT_SELECTIONS };
    let changed = false;

    if (runtimeParam === 'fiber' || runtimeParam === 'swoole' || runtimeParam === 'worker-pool') {
      next.runtime = runtimeParam;
      changed = true;
    }
    if (
      persistenceParam === 'none' ||
      persistenceParam === 'es-dbal' ||
      persistenceParam === 'es-doctrine' ||
      persistenceParam === 'ds-dbal' ||
      persistenceParam === 'ds-doctrine'
    ) {
      next.persistence = persistenceParam;
      changed = true;
    }
    for (const flag of ['http', 'websockets', 'doctrine'] as const) {
      const v = params.get(flag);
      if (v === '1' || v === 'true') {
        next[flag] = true;
        changed = true;
      } else if (v === '0' || v === 'false') {
        next[flag] = false;
        changed = true;
      }
    }
    if (changed) setSel(next);
  }, []);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams();
    params.set('step', String(step + 1));
    if (sel.runtime !== DEFAULT_SELECTIONS.runtime) params.set('runtime', sel.runtime);
    if (sel.persistence !== DEFAULT_SELECTIONS.persistence) params.set('persistence', sel.persistence);
    for (const flag of ['http', 'websockets', 'doctrine'] as const) {
      if (sel[flag]) params.set(flag, '1');
    }
    const newUrl = `${window.location.pathname}?${params.toString()}`;
    window.history.replaceState(null, '', newUrl);
  }, [step, sel]);

  const next = () => setStep((s) => Math.min(s + 1, STEPS.length - 1));
  const back = () => setStep((s) => Math.max(s - 1, 0));
  const reset = () => {
    setSel(DEFAULT_SELECTIONS);
    setStep(0);
  };

  return (
    <div className="wiz-root">
      <StepBar current={step} onClick={setStep} />

      <div className="wiz-card">
        {step === 0 && <Step1 sel={sel} setSel={setSel} />}
        {step === 1 && <Step2 sel={sel} setSel={setSel} />}
        {step === 2 && <Step3 sel={sel} setSel={setSel} />}
        {step === 3 && <Step4 sel={sel} onReset={reset} />}

        {step < 3 && (
          <div className="wiz-nav">
            <button
              type="button"
              onClick={back}
              disabled={step === 0}
              className="wiz-back-btn"
            >
              Back
            </button>
            <button type="button" onClick={next} className="wiz-next-btn">
              Next →
            </button>
          </div>
        )}
      </div>

      <style>{`
        /* ── Layout ── */
        .wiz-root {
          max-width: 820px;
          margin: 0 auto;
        }

        /* ── Step bar ── */
        .wiz-stepbar {
          display: flex;
          align-items: center;
          gap: 0;
          margin-bottom: 2rem;
          flex-wrap: wrap;
          gap: 0.25rem;
        }
        .wiz-step {
          display: flex;
          align-items: center;
          gap: 0.5rem;
          background: none;
          border: none;
          padding: 0.4rem 0;
          font-size: 0.85rem;
          font-family: inherit;
          cursor: default;
          transition: color 0.15s;
        }
        .wiz-step--active  { color: #10b981; font-weight: 600; cursor: default; }
        .wiz-step--done    { color: #10b981; cursor: pointer; }
        .wiz-step--done:hover { opacity: 0.8; }
        .wiz-step--future  { color: #64748b; cursor: default; }
        .wiz-step-num {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 1.6rem;
          height: 1.6rem;
          border-radius: 50%;
          font-size: 0.72rem;
          font-weight: 700;
          flex-shrink: 0;
        }
        .wiz-step--active  .wiz-step-num { background: #10b981; color: #fff; }
        .wiz-step--done    .wiz-step-num { background: #064e3b; color: #10b981; }
        .wiz-step--future  .wiz-step-num { background: #1e293b; color: #64748b; }
        .wiz-step-label { white-space: nowrap; }
        .wiz-step-sep {
          display: inline-block;
          width: 1.5rem;
          height: 1px;
          background: #334155;
          margin: 0 0.5rem;
          flex-shrink: 0;
        }

        /* ── Card wrapper ── */
        .wiz-card {
          background: #0f172a;
          border: 1px solid #1e293b;
          border-radius: 12px;
          overflow: hidden;
        }

        /* ── Step body ── */
        .wiz-step-body {
          padding: 2rem;
        }
        .wiz-step-heading {
          font-size: 1.15rem;
          font-weight: 700;
          color: #e2e8f0;
          margin: 0 0 1.5rem;
        }

        /* ── Radio / check cards ── */
        .wiz-cards {
          display: flex;
          flex-direction: column;
          gap: 0.75rem;
        }
        .wiz-radio-card {
          display: flex;
          align-items: flex-start;
          gap: 0.9rem;
          min-width: 0;
          padding: 1rem 1.2rem;
          background: #1e293b;
          border: 2px solid #334155;
          border-radius: 10px;
          cursor: pointer;
          text-align: left;
          font-family: inherit;
          transition: border-color 0.15s, background 0.15s;
          width: 100%;
        }
        .wiz-radio-card:hover:not(:disabled) {
          border-color: #4ade80;
          background: #0f2a1e;
        }
        .wiz-radio-card--selected {
          border-color: #10b981 !important;
          background: #0b1f14 !important;
        }
        .wiz-radio-card--disabled {
          opacity: 0.45;
          cursor: not-allowed;
        }
        .wiz-radio-dot {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 1.1rem;
          height: 1.1rem;
          border-radius: 50%;
          border: 2px solid #475569;
          flex-shrink: 0;
          margin-top: 0.1rem;
          color: #10b981;
          transition: border-color 0.15s;
        }
        .wiz-radio-card--selected .wiz-radio-dot {
          border-color: #10b981;
        }
        .wiz-check-box {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 1.1rem;
          height: 1.1rem;
          border-radius: 4px;
          border: 2px solid #475569;
          flex-shrink: 0;
          margin-top: 0.1rem;
          color: #10b981;
          transition: border-color 0.15s;
        }
        .wiz-radio-card--selected .wiz-check-box {
          border-color: #10b981;
          background: #064e3b;
        }
        .wiz-card-text {
          display: flex;
          flex-direction: column;
          gap: 0.25rem;
          min-width: 0;
        }
        .wiz-card-title {
          font-size: 0.95rem;
          font-weight: 600;
          color: #e2e8f0;
        }
        .wiz-card-desc {
          font-size: 0.82rem;
          color: #94a3b8;
          line-height: 1.5;
          overflow-wrap: anywhere;
        }

        /* ── Nav (Back / Next) ── */
        .wiz-nav {
          display: flex;
          align-items: center;
          justify-content: space-between;
          padding: 1.25rem 2rem;
          border-top: 1px solid #1e293b;
          background: #0a1628;
        }
        .wiz-back-btn {
          padding: 0.55rem 1.4rem;
          border: 1px solid #334155;
          border-radius: 8px;
          background: transparent;
          color: #94a3b8;
          font-size: 0.9rem;
          font-family: inherit;
          cursor: pointer;
          transition: border-color 0.15s, color 0.15s;
        }
        .wiz-back-btn:hover:not(:disabled) {
          border-color: #64748b;
          color: #e2e8f0;
        }
        .wiz-back-btn:disabled {
          opacity: 0.35;
          cursor: not-allowed;
        }
        .wiz-next-btn {
          padding: 0.55rem 1.6rem;
          background: #10b981;
          border: none;
          border-radius: 8px;
          color: #fff;
          font-size: 0.9rem;
          font-weight: 600;
          font-family: inherit;
          cursor: pointer;
          box-shadow: 0 0 18px rgba(16,185,129,0.35);
          transition: background 0.15s, box-shadow 0.15s;
        }
        .wiz-next-btn:hover {
          background: #059669;
          box-shadow: 0 0 24px rgba(16,185,129,0.5);
        }

        /* ── Recap chips ── */
        .wiz-chips {
          display: flex;
          flex-wrap: wrap;
          gap: 0.5rem;
          margin-bottom: 1.5rem;
        }
        .wiz-chip {
          padding: 0.3rem 0.8rem;
          background: #064e3b;
          border: 1px solid #10b981;
          border-radius: 99px;
          font-size: 0.78rem;
          color: #6ee7b7;
          font-weight: 500;
        }

        /* ── IDE toolbar ── */
        .wiz-ide-wrap {
          border-radius: 10px;
          overflow: hidden;
        }
        .wiz-ide-toolbar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          padding: 0.6rem 1rem;
          background: #111827;
          border: 1px solid #1e2433;
          border-bottom: none;
          border-radius: 10px 10px 0 0;
        }
        .wiz-ide-label {
          font-size: 0.75rem;
          color: #6b7280;
          font-family: var(--nx-font-mono, monospace);
        }
        .wiz-copy-btn {
          padding: 0.28rem 0.85rem;
          background: #064e3b;
          border: 1px solid #10b981;
          border-radius: 6px;
          color: #6ee7b7;
          font-size: 0.75rem;
          font-weight: 600;
          font-family: inherit;
          cursor: pointer;
          transition: background 0.15s;
        }
        .wiz-copy-btn:hover {
          background: #065f46;
        }

        /* ── Step 4 actions ── */
        .wiz-step4-actions {
          display: flex;
          align-items: center;
          justify-content: space-between;
          margin-top: 1.5rem;
          flex-wrap: wrap;
          gap: 1rem;
        }
        .wiz-reset-link {
          background: none;
          border: none;
          color: #64748b;
          font-size: 0.88rem;
          font-family: inherit;
          cursor: pointer;
          padding: 0;
          transition: color 0.15s;
        }
        .wiz-reset-link:hover { color: #94a3b8; }
        .wiz-cta-btn {
          display: inline-flex;
          align-items: center;
          gap: 0.4rem;
          padding: 0.6rem 1.5rem;
          background: #10b981;
          border-radius: 8px;
          color: #fff;
          font-size: 0.9rem;
          font-weight: 600;
          text-decoration: none;
          box-shadow: 0 0 18px rgba(16,185,129,0.35);
          transition: background 0.15s, box-shadow 0.15s;
        }
        .wiz-cta-btn:hover {
          background: #059669;
          box-shadow: 0 0 24px rgba(16,185,129,0.5);
          text-decoration: none;
        }

        /* ── Packagist availability note ── */
        .wiz-packagist-note {
          background: #1c1200;
          border: 1px solid #92400e;
          border-left: 3px solid #f59e0b;
          border-radius: 6px;
          color: #fde68a;
          font-size: 0.85rem;
          line-height: 1.5;
          margin-bottom: 1rem;
          padding: 0.6rem 0.9rem;
        }
        .wiz-packagist-note strong { color: #fcd34d; }
        .wiz-packagist-note code {
          background: rgba(245,158,11,0.15);
          border-radius: 3px;
          color: #fcd34d;
          font-size: 0.82rem;
          padding: 0.1em 0.3em;
        }
        .wiz-packagist-note a { color: #fcd34d; text-decoration: underline; }
        .wiz-packagist-note a:hover { color: #fff; }

        /* ── create-project callout ── */
        .wiz-create-card {
          position: relative;
          background: #071a0f;
          border: 1px solid #10b981;
          border-top: 3px solid #10b981;
          border-radius: 10px;
          padding: 1.25rem 1.4rem 1rem;
          margin-bottom: 1.25rem;
        }
        .wiz-create-header {
          display: flex;
          align-items: center;
          gap: 0.6rem;
          margin-bottom: 0.75rem;
        }
        .wiz-create-badge {
          padding: 0.15rem 0.6rem;
          background: #064e3b;
          border: 1px solid #10b981;
          border-radius: 99px;
          font-size: 0.7rem;
          font-weight: 700;
          color: #6ee7b7;
          text-transform: uppercase;
          letter-spacing: 0.05em;
        }
        .wiz-create-title {
          font-size: 0.88rem;
          font-weight: 600;
          color: #a7f3d0;
        }
        .wiz-create-pre {
          margin: 0 0 0.85rem;
          padding: 0.85rem 1rem;
          background: #020d07;
          border-radius: 7px;
          overflow-x: auto;
          font-family: var(--nx-font-mono, monospace);
          font-size: 0.82rem;
          color: #d1fae5;
          line-height: 1.65;
          white-space: pre;
        }
        .wiz-create-copy {
          padding: 0.28rem 0.85rem;
          background: #064e3b;
          border: 1px solid #10b981;
          border-radius: 6px;
          color: #6ee7b7;
          font-size: 0.75rem;
          font-weight: 600;
          font-family: inherit;
          cursor: pointer;
          transition: background 0.15s;
        }
        .wiz-create-copy:hover { background: #065f46; }

        /* ── disclosure / details ── */
        .wiz-details {
          margin-bottom: 1.5rem;
        }
        .wiz-details-summary {
          font-size: 0.82rem;
          color: #64748b;
          cursor: pointer;
          user-select: none;
          padding: 0.4rem 0;
          list-style: none;
          display: flex;
          align-items: center;
          gap: 0.4rem;
          transition: color 0.15s;
        }
        .wiz-details-summary::-webkit-details-marker { display: none; }
        .wiz-details-summary::before {
          content: '▶';
          font-size: 0.65rem;
          transition: transform 0.15s;
          display: inline-block;
        }
        details[open] .wiz-details-summary::before {
          transform: rotate(90deg);
        }
        .wiz-details-summary:hover { color: #94a3b8; }
        .wiz-details-body {
          margin-top: 0.85rem;
        }

        @media (max-width: 640px) {
          .wiz-step-body { padding: 1.25rem; }
          .wiz-nav { padding: 1rem 1.25rem; }
          .wiz-stepbar { gap: 0.1rem; }
          .wiz-step-label { display: none; }
          .wiz-step-sep { width: 0.75rem; }
          .wiz-create-pre { font-size: 0.72rem; }
          /* mobile-final-1: extend tap target to meet 44px WCAG 2.5.8 minimum */
          .wiz-step { min-height: 44px; padding: 0.6rem 0.4rem; }
          .wiz-step-num { width: 2.25rem; height: 2.25rem; font-size: 0.85rem; }
        }
      `}</style>
    </div>
  );
}
