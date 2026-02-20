#!/usr/bin/env node

/**
 * Reads benchmark-results.jsonl, groups results by runtime,
 * and appends a new entry to website/static/benchmarks/history.json.
 *
 * Usage: BENCHMARK_JSON is not needed here — the script reads
 * benchmark-results.jsonl from the project root.
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const JSONL_PATH = path.join(__dirname, '..', 'benchmark-results.jsonl');
const HISTORY_PATH = path.join(__dirname, '..', 'website', 'static', 'benchmarks', 'history.json');
const MAX_ENTRIES = 50;

// Map benchmark name patterns to stable keys.
// Each pattern is tested as a substring match against the benchmark name.
const NAME_MAP = [
  // Fiber benchmarks
  { pattern: 'Fiber:', contains: 'messages to single actor', key: 'messageThroughput' },
  { pattern: 'Fiber:', contains: 'message burst', key: 'burst' },
  { pattern: 'Fiber:', contains: 'burst of', key: 'burst' },
  { pattern: 'Fiber:', contains: 'stateful transitions', key: 'statefulTransitions' },
  { pattern: 'Fiber:', contains: 'fan-out', key: 'fanOut' },
  { pattern: 'Fiber:', contains: 'ping-pong', key: 'pingPong' },
  { pattern: 'Fiber:', contains: 'multi-dispatch', key: 'multiDispatch' },
  { pattern: 'Fiber:', contains: 'spawn-kill', key: 'spawnKillCycles' },
  { pattern: 'Fiber:', contains: 'kill', key: 'kill' },

  // Swoole benchmarks
  { pattern: 'Swoole:', contains: 'messages to single actor', key: 'messageThroughput' },
  { pattern: 'Swoole:', contains: 'burst of', key: 'burst' },
  { pattern: 'Swoole:', contains: 'stateful transitions', key: 'statefulTransitions' },
  { pattern: 'Swoole:', contains: 'fan-out', key: 'fanOut' },
  { pattern: 'Swoole:', contains: 'ping-pong', key: 'pingPong' },
  { pattern: 'Swoole:', contains: 'multi-dispatch', key: 'multiDispatch' },
  { pattern: 'Swoole:', contains: 'spawn-kill', key: 'spawnKillCycles' },
  { pattern: 'Swoole:', contains: 'kill', key: 'kill' },
  { pattern: 'Swoole:', contains: 'concurrent actors', key: 'concurrentActors' },

  // Cluster benchmarks
  { pattern: 'Cluster:', contains: 'serialize+deserialize', key: 'serializationThroughput' },
];

function detectRuntime(name) {
  if (name.startsWith('Fiber:')) return 'fiber';
  if (name.startsWith('Swoole:')) return 'swoole';
  if (name.startsWith('Cluster:')) return 'cluster';
  return 'unknown';
}

function mapToKey(name) {
  for (const entry of NAME_MAP) {
    if (name.startsWith(entry.pattern) && name.includes(entry.contains)) {
      return entry.key;
    }
  }
  // Fallback: slugify the name
  return name
    .replace(/^(Fiber|Swoole|Cluster):\s*/, '')
    .replace(/[^a-zA-Z0-9]+/g, '_')
    .replace(/^_|_$/g, '')
    .toLowerCase();
}

function main() {
  if (!fs.existsSync(JSONL_PATH)) {
    console.log('No benchmark-results.jsonl found, skipping.');
    process.exit(0);
  }

  const lines = fs.readFileSync(JSONL_PATH, 'utf-8').trim().split('\n').filter(Boolean);
  if (lines.length === 0) {
    console.log('benchmark-results.jsonl is empty, skipping.');
    process.exit(0);
  }

  // Parse all results
  const results = { fiber: {}, swoole: {}, cluster: {} };
  for (const line of lines) {
    const data = JSON.parse(line);
    const runtime = detectRuntime(data.name);
    const key = mapToKey(data.name);

    if (runtime === 'unknown') {
      console.warn(`Unknown runtime for benchmark: ${data.name}`);
      continue;
    }

    results[runtime][key] = {
      opsPerSecond: Math.round(data.opsPerSecond),
      operations: data.operations,
      elapsedMs: Math.round(data.elapsedMs * 10) / 10,
      peakMemoryBytes: data.peakMemoryBytes,
      memoryDeltaBytes: data.memoryDeltaBytes,
    };
  }

  // Get metadata
  let commit = 'unknown';
  try {
    commit = execSync('git rev-parse --short HEAD', { encoding: 'utf-8' }).trim();
  } catch {
    // ignore
  }

  const entry = {
    date: new Date().toISOString().split('T')[0],
    commit,
    results,
  };

  // Load existing history
  let history = [];
  if (fs.existsSync(HISTORY_PATH)) {
    try {
      history = JSON.parse(fs.readFileSync(HISTORY_PATH, 'utf-8'));
    } catch {
      history = [];
    }
  }

  // Append and cap
  history.push(entry);
  if (history.length > MAX_ENTRIES) {
    history = history.slice(history.length - MAX_ENTRIES);
  }

  // Write
  fs.mkdirSync(path.dirname(HISTORY_PATH), { recursive: true });
  fs.writeFileSync(HISTORY_PATH, JSON.stringify(history, null, 2) + '\n');

  console.log(`Benchmark data updated: ${lines.length} results from ${Object.keys(results.fiber).length} fiber, ${Object.keys(results.swoole).length} swoole, ${Object.keys(results.cluster).length} cluster benchmarks.`);
}

main();
