#!/usr/bin/env node

import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import {
  access,
  mkdir,
  mkdtemp,
  open,
  readFile,
  rename,
  rm,
  stat,
  unlink,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const CHROME_EXECUTABLE =
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const STARTUP_TIMEOUT_MS = 20_000;
const CDP_TIMEOUT_MS = 30_000;
const POLL_INTERVAL_MS = 100;
const USAGE =
  'Usage: node bin/print-audit-pdf.mjs <input-html> <output-pdf>';

export function parseArguments(args) {
  if (args.length !== 2) {
    throw new Error(USAGE);
  }

  const [inputHtml, outputPdf] = args;
  if (inputHtml.trim() === '') {
    throw new Error(`Input HTML path must not be empty.\n${USAGE}`);
  }
  if (outputPdf.trim() === '') {
    throw new Error(`Output PDF path must not be empty.\n${USAGE}`);
  }

  return { inputHtml, outputPdf };
}

export function buildPrintToPdfParams() {
  return {
    printBackground: true,
    preferCSSPageSize: true,
    paperWidth: 210 / 25.4,
    paperHeight: 297 / 25.4,
    scale: 1,
    displayHeaderFooter: false,
    marginTop: 0,
    marginBottom: 0,
    marginLeft: 0,
    marginRight: 0,
  };
}

function delay(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function withTimeout(promise, milliseconds, message) {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(message)), milliseconds);
  });

  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

async function assertInputFile(inputHtml) {
  let metadata;
  try {
    metadata = await stat(inputHtml);
  } catch (error) {
    throw new Error(`Input HTML file is not accessible: ${inputHtml}`, {
      cause: error,
    });
  }

  if (!metadata.isFile()) {
    throw new Error(`Input HTML path is not a file: ${inputHtml}`);
  }
}

function launchChrome(profileDirectory, pageUrl) {
  const stderrChunks = [];
  let spawnError;
  const child = spawn(
    CHROME_EXECUTABLE,
    [
      '--headless=new',
      '--disable-gpu',
      '--no-first-run',
      '--no-default-browser-check',
      '--disable-background-networking',
      '--disable-extensions',
      '--disable-sync',
      '--metrics-recording-only',
      '--mute-audio',
      '--remote-debugging-address=127.0.0.1',
      '--remote-debugging-port=0',
      `--user-data-dir=${profileDirectory}`,
      pageUrl,
    ],
    { stdio: ['ignore', 'ignore', 'pipe'] },
  );

  child.stderr.on('data', (chunk) => {
    stderrChunks.push(chunk);
    if (stderrChunks.length > 100) {
      stderrChunks.shift();
    }
  });
  child.once('error', (error) => {
    spawnError = error;
  });

  const closed = new Promise((resolve) => {
    child.once('close', (code, signal) => resolve({ code, signal }));
  });

  return {
    child,
    closed,
    getSpawnError: () => spawnError,
    getStderr: () => Buffer.concat(stderrChunks).toString('utf8').trim(),
  };
}

async function findPageTarget(chrome, profileDirectory, expectedUrl) {
  const deadline = Date.now() + STARTUP_TIMEOUT_MS;
  const portFile = path.join(profileDirectory, 'DevToolsActivePort');
  let lastError;

  while (Date.now() < deadline) {
    const spawnError = chrome.getSpawnError();
    if (spawnError) {
      throw new Error(`Chrome failed to start: ${spawnError.message}`, {
        cause: spawnError,
      });
    }
    if (chrome.child.exitCode !== null || chrome.child.signalCode !== null) {
      const stderr = chrome.getStderr();
      throw new Error(
        `Chrome exited before its debugging endpoint was ready${stderr ? `:\n${stderr}` : '.'}`,
      );
    }

    try {
      const portContents = await readFile(portFile, 'utf8');
      const port = Number.parseInt(portContents.split(/\r?\n/, 1)[0], 10);
      if (!Number.isInteger(port) || port < 1 || port > 65_535) {
        throw new Error(`Invalid Chrome debugging port: ${portContents.trim()}`);
      }

      const response = await fetch(`http://127.0.0.1:${port}/json/list`, {
        signal: AbortSignal.timeout(1_000),
      });
      if (!response.ok) {
        throw new Error(`Chrome target endpoint returned HTTP ${response.status}`);
      }

      const targets = await response.json();
      const page = targets.find(
        (target) =>
          target.type === 'page' &&
          target.url === expectedUrl &&
          typeof target.webSocketDebuggerUrl === 'string',
      );
      if (page) {
        return page;
      }

      lastError = new Error(`Chrome has not opened the requested page: ${expectedUrl}`);
    } catch (error) {
      lastError = error;
    }

    await delay(POLL_INTERVAL_MS);
  }

  const detail = lastError instanceof Error ? ` Last error: ${lastError.message}` : '';
  throw new Error(
    `Timed out after ${STARTUP_TIMEOUT_MS}ms waiting for Chrome's /json/list target.${detail}`,
  );
}

async function connectCdp(webSocketUrl) {
  const socket = new WebSocket(webSocketUrl);
  const pending = new Map();
  let requestId = 0;

  await withTimeout(
    new Promise((resolve, reject) => {
      socket.addEventListener('open', resolve, { once: true });
      socket.addEventListener(
        'error',
        () => reject(new Error('Chrome DevTools WebSocket connection failed.')),
        { once: true },
      );
    }),
    STARTUP_TIMEOUT_MS,
    `Timed out after ${STARTUP_TIMEOUT_MS}ms connecting to Chrome DevTools.`,
  );

  socket.addEventListener('message', (event) => {
    let message;
    try {
      message = JSON.parse(event.data);
    } catch {
      return;
    }

    if (!Object.hasOwn(message, 'id')) {
      return;
    }

    const handler = pending.get(message.id);
    if (!handler) {
      return;
    }
    pending.delete(message.id);
    clearTimeout(handler.timer);

    if (message.error) {
      handler.reject(
        new Error(
          `Chrome DevTools ${handler.method} failed (${message.error.code}): ${message.error.message}`,
        ),
      );
      return;
    }
    handler.resolve(message.result ?? {});
  });

  const rejectPending = (reason) => {
    for (const handler of pending.values()) {
      clearTimeout(handler.timer);
      handler.reject(reason);
    }
    pending.clear();
  };
  socket.addEventListener('close', () => {
    rejectPending(new Error('Chrome DevTools WebSocket closed unexpectedly.'));
  });
  socket.addEventListener('error', () => {
    rejectPending(new Error('Chrome DevTools WebSocket failed.'));
  });

  return {
    send(method, params = {}) {
      const id = ++requestId;
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
          pending.delete(id);
          reject(
            new Error(`Timed out after ${CDP_TIMEOUT_MS}ms waiting for ${method}.`),
          );
        }, CDP_TIMEOUT_MS);
        pending.set(id, { method, reject, resolve, timer });

        try {
          socket.send(JSON.stringify({ id, method, params }));
        } catch (error) {
          clearTimeout(timer);
          pending.delete(id);
          reject(new Error(`Could not send Chrome DevTools ${method}: ${error.message}`));
        }
      });
    },
    close() {
      if (socket.readyState === WebSocket.OPEN) {
        socket.close();
      }
    },
  };
}

async function waitForDocumentReady(cdp) {
  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Runtime.evaluate', {
    expression: `new Promise((resolve) => {
      const settle = async () => {
        if (document.fonts?.ready) await document.fonts.ready;
        requestAnimationFrame(() => requestAnimationFrame(() => resolve(true)));
      };
      if (document.readyState === 'complete') settle();
      else window.addEventListener('load', settle, { once: true });
    })`,
    awaitPromise: true,
    returnByValue: true,
  });
}

async function writeAtomically(outputPdf, contents) {
  const directory = path.dirname(outputPdf);
  await mkdir(directory, { recursive: true });

  const temporaryPath = path.join(
    directory,
    `.${path.basename(outputPdf)}.${process.pid}.${randomUUID()}.tmp`,
  );
  let file;
  try {
    file = await open(temporaryPath, 'wx', 0o644);
    await file.writeFile(contents);
    await file.sync();
    await file.close();
    file = undefined;
    await rename(temporaryPath, outputPdf);
  } catch (error) {
    await file?.close().catch(() => {});
    await unlink(temporaryPath).catch(() => {});
    throw new Error(`Could not write PDF atomically to ${outputPdf}: ${error.message}`, {
      cause: error,
    });
  }
}

async function terminateChrome(chrome) {
  if (chrome.child.pid === undefined) {
    return;
  }
  if (chrome.child.exitCode !== null || chrome.child.signalCode !== null) {
    await chrome.closed;
    return;
  }

  chrome.child.kill('SIGTERM');
  const terminated = await Promise.race([
    chrome.closed.then(() => true),
    delay(2_000).then(() => false),
  ]);
  if (!terminated) {
    chrome.child.kill('SIGKILL');
    await chrome.closed;
  }
}

async function printPdf(inputHtml, outputPdf) {
  const inputPath = path.resolve(inputHtml);
  const outputPath = path.resolve(outputPdf);
  await assertInputFile(inputPath);
  await access(CHROME_EXECUTABLE);

  const profileDirectory = await mkdtemp(path.join(tmpdir(), 'nexus-audit-pdf-'));
  const pageUrl = pathToFileURL(inputPath).href;
  const chrome = launchChrome(profileDirectory, pageUrl);
  let cdp;

  try {
    const target = await findPageTarget(chrome, profileDirectory, pageUrl);
    cdp = await connectCdp(target.webSocketDebuggerUrl);
    await waitForDocumentReady(cdp);
    const result = await cdp.send('Page.printToPDF', buildPrintToPdfParams());
    if (typeof result.data !== 'string' || result.data.length === 0) {
      throw new Error('Chrome DevTools Page.printToPDF returned no PDF data.');
    }

    await writeAtomically(outputPath, Buffer.from(result.data, 'base64'));
    process.stdout.write(`Wrote ${outputPath}\n`);
  } finally {
    cdp?.close();
    await terminateChrome(chrome).catch(() => {});
    await rm(profileDirectory, { recursive: true, force: true });
  }
}

async function main() {
  const { inputHtml, outputPdf } = parseArguments(process.argv.slice(2));
  await printPdf(inputHtml, outputPdf);
}

const invokedAsScript =
  process.argv[1] !== undefined &&
  import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href;

if (invokedAsScript) {
  main().catch((error) => {
    const cause = error.cause instanceof Error ? `\nCaused by: ${error.cause.message}` : '';
    process.stderr.write(`PDF generation failed: ${error.message}${cause}\n`);
    process.exitCode = 1;
  });
}
