// landing/src/components/IDEEditor.tsx
import { useState } from 'react';

export interface IDEFile {
  name: string;
  language: string;
  code: string;
}

interface Props {
  files: IDEFile[];
  defaultIndex?: number;
  height?: string;
  onActiveChange?: (index: number) => void;
}

function highlightPhp(code: string): React.ReactNode[] {
  // Tokenise line-by-line so line numbers stay in sync
  return code.split('\n').map((line, lineIdx) => {
    const nodes: React.ReactNode[] = [];
    let remaining = line;
    let key = 0;

    const push = (text: string, cls?: string) => {
      if (!text) return;
      nodes.push(cls ? <span key={key++} className={cls}>{text}</span> : <span key={key++}>{text}</span>);
    };

    while (remaining.length > 0) {
      // Single-line comment
      const commentIdx = remaining.indexOf('//');
      const commentInStr =
        commentIdx > 0 &&
        (remaining.slice(0, commentIdx).split("'").length % 2 === 0 ||
          remaining.slice(0, commentIdx).split('"').length % 2 === 0);
      if (commentIdx !== -1 && !commentInStr) {
        processTokens(remaining.slice(0, commentIdx), push);
        push(remaining.slice(commentIdx), 'tok-comment');
        remaining = '';
        continue;
      }

      // String literals (single or double quoted, no escapes for simplicity)
      const strMatch = remaining.match(/^(.*?)(['"][^'"]*['"])/);
      if (strMatch) {
        processTokens(strMatch[1], push);
        push(strMatch[2], 'tok-string');
        remaining = remaining.slice(strMatch[1].length + strMatch[2].length);
        continue;
      }

      processTokens(remaining, push);
      remaining = '';
    }

    return (
      <div key={lineIdx} className="code-line">
        <span className="line-num">{lineIdx + 1}</span>
        <span className="line-body">{nodes}</span>
      </div>
    );
  });
}

const KEYWORDS =
  /\b(use|class|interface|function|return|new|static|readonly|public|private|protected|final|match|fn|null|true|false|if|else|instanceof|declare|namespace|abstract)\b/g;
const VARIABLE = /(\$[a-zA-Z_]\w*)/g;
const FUNC_CALL = /\b([a-z_]\w*)(?=\s*\()/g;
const CLASS_NAME = /\b([A-Z][a-zA-Z0-9_]*)\b/g;

function processTokens(text: string, push: (t: string, cls?: string) => void) {
  if (!text) return;

  // We need to walk through combining patterns; use a simple segment approach
  type Segment = { start: number; end: number; cls: string };
  const segments: Segment[] = [];

  const collect = (re: RegExp, cls: string, source: string) => {
    re.lastIndex = 0;
    let m: RegExpExecArray | null;
    while ((m = re.exec(source)) !== null) {
      segments.push({ start: m.index, end: m.index + m[0].length, cls });
    }
  };

  collect(CLASS_NAME, 'tok-class', text);
  collect(FUNC_CALL, 'tok-func', text);
  collect(KEYWORDS, 'tok-keyword', text);
  collect(VARIABLE, 'tok-var', text);

  // Sort by start, resolve overlaps (first wins = last rule = VARIABLE wins because we sort stable and put it last)
  segments.sort((a, b) => a.start - b.start || b.end - a.end);

  let cursor = 0;
  const used: Segment[] = [];
  for (const seg of segments) {
    if (seg.start < cursor) continue; // overlapping — skip
    used.push(seg);
    cursor = seg.end;
  }

  cursor = 0;
  for (const seg of used) {
    if (seg.start > cursor) push(text.slice(cursor, seg.start));
    push(text.slice(seg.start, seg.end), seg.cls);
    cursor = seg.end;
  }
  if (cursor < text.length) push(text.slice(cursor));
}

const FILE_ICON: Record<string, string> = {
  php: '🐘',
};

function fileIcon(lang: string) {
  return FILE_ICON[lang] ?? '📄';
}

export default function IDEEditor({ files, defaultIndex = 0, height = 'auto', onActiveChange }: Props) {
  const [activeIdx, setActiveIdx] = useState(defaultIndex);
  const handleTabClick = (i: number) => {
    setActiveIdx(i);
    onActiveChange?.(i);
  };
  const active = files[activeIdx];
  const lineCount = active.code.split('\n').length;
  const highlighted = highlightPhp(active.code);

  return (
    <div className="ide-root" style={{ height }}>
      {/* Title bar */}
      <div className="ide-titlebar">
        <div className="ide-dots">
          <span className="dot dot-red" />
          <span className="dot dot-amber" />
          <span className="dot dot-green" />
        </div>
        <span className="ide-breadcrumb">nexus-app — {active.name}</span>
        <div className="ide-dots ide-dots-spacer" aria-hidden="true" />
      </div>

      {/* Tab bar */}
      <div className="ide-tabbar" role="tablist" aria-label="Editor files">
        {files.map((f, i) => (
          <button
            key={f.name}
            role="tab"
            id={`ide-tab-${i}`}
            aria-selected={i === activeIdx}
            aria-controls={`ide-panel-${i}`}
            className={`ide-tab${i === activeIdx ? ' ide-tab--active' : ''}`}
            onClick={() => handleTabClick(i)}
          >
            <span className="ide-tab-icon">{fileIcon(f.language)}</span>
            {f.name}
          </button>
        ))}
      </div>

      {/* Editor body */}
      <div
        className="ide-body"
        role="tabpanel"
        id={`ide-panel-${activeIdx}`}
        aria-labelledby={`ide-tab-${activeIdx}`}
      >
        <code className="ide-code">{highlighted}</code>
      </div>

      {/* Status bar */}
      <div className="ide-statusbar">
        <span className="sb-lang">PHP</span>
        <span className="sb-center">Ln {lineCount}, Col 1</span>
        <span className="sb-right">UTF-8</span>
      </div>

      <style>{`
        .ide-root {
          display: flex;
          flex-direction: column;
          border-radius: 10px;
          overflow: hidden;
          border: 1px solid #2a2d35;
          font-family: var(--nx-font-mono, 'JetBrains Mono', 'Fira Code', 'Cascadia Code', monospace);
          background: #0F172A;
        }

        /* ── Title bar ── */
        .ide-titlebar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          padding: 0.55rem 0.9rem;
          background: #111827;
          border-bottom: 1px solid #1e2433;
          user-select: none;
        }
        .ide-dots {
          display: flex;
          gap: 0.4rem;
          align-items: center;
          min-width: 52px;
        }
        .ide-dots-spacer { visibility: hidden; }
        .dot {
          width: 12px;
          height: 12px;
          border-radius: 50%;
        }
        .dot-red   { background: #FF5F57; }
        .dot-amber { background: #FFBD2E; }
        .dot-green { background: #28C840; }
        .ide-breadcrumb {
          font-size: 0.72rem;
          color: #6B7280;
          letter-spacing: 0.01em;
        }

        /* ── Tab bar ── */
        .ide-tabbar {
          display: flex;
          background: #111827;
          border-bottom: 1px solid #1e2433;
          overflow-x: auto;
          /* mobile-final-2: fade right edge to signal horizontal scrollability */
          -webkit-mask-image: linear-gradient(to right, #000 calc(100% - 24px), transparent);
          mask-image: linear-gradient(to right, #000 calc(100% - 24px), transparent);
        }
        .ide-tab {
          display: flex;
          align-items: center;
          gap: 0.35rem;
          padding: 0.5rem 1rem;
          font-family: inherit;
          font-size: 0.78rem;
          color: #6B7280;
          background: transparent;
          border: none;
          border-top: 2px solid transparent;
          cursor: pointer;
          white-space: nowrap;
          transition: color 0.15s, background 0.15s, border-color 0.15s;
        }
        .ide-tab:hover {
          color: #9CA3AF;
          background: rgba(255,255,255,0.04);
        }
        .ide-tab--active {
          color: #E6EDF3;
          background: #0F172A;
          border-top-color: #10b981;
        }
        .ide-tab-icon { font-size: 0.7rem; }

        /* ── Editor body ── */
        .ide-body {
          flex: 1;
          overflow: auto;
          background: #0F172A;
          padding: 0.85rem 0;
        }
        .ide-code {
          display: block;
          font-family: inherit;
          font-size: 0.84rem;
          line-height: 1.65;
          color: #E6EDF3;
        }
        .code-line {
          display: flex;
          min-height: 1.65em;
          padding: 0 1.25rem 0 0;
        }
        .code-line:hover {
          background: rgba(255,255,255,0.025);
        }
        .line-num {
          min-width: 2.8rem;
          text-align: right;
          padding-right: 1.25rem;
          color: #374151;
          font-size: 0.78rem;
          user-select: none;
          flex-shrink: 0;
        }
        .line-body {
          white-space: pre;
          flex: 1;
        }

        /* ── Syntax tokens ── */
        .tok-keyword { color: #FF7B72; }
        .tok-string  { color: #A5D6FF; }
        .tok-comment { color: #8B949E; font-style: italic; }
        .tok-class   { color: #FFA657; }
        .tok-func    { color: #7EE787; }
        .tok-var     { color: #79C0FF; }

        /* ── Status bar ── */
        .ide-statusbar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          padding: 0.25rem 1rem;
          background: #0B1120;
          border-top: 1px solid #1e2433;
          font-size: 0.7rem;
          color: #4B5563;
        }
        .sb-center { flex: 1; text-align: center; }
        .sb-right  { text-align: right; }
      `}</style>
    </div>
  );
}
