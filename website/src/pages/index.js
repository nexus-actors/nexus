import React from 'react';
import Layout from '@theme/Layout';
import Link from '@docusaurus/Link';
import styles from './index.module.css';

/* Docs hub — the docs.nexusactors.com homepage. The marketing landing
   lives at nexusactors.com; this page is the map of the documentation. */

const startHere = [
  {
    title: 'Installation',
    to: '/docs/getting-started/installation',
    desc: 'Composer packages, PHP 8.5 requirements, and picking a runtime.',
  },
  {
    title: 'Quick Start',
    to: '/docs/getting-started/quick-start',
    desc: 'Your first actor system in five minutes — install, spawn, run.',
  },
  {
    title: 'Concepts',
    to: '/docs/getting-started/concepts',
    desc: 'Actors, behaviors, messages, and supervision in a nutshell.',
  },
  {
    title: 'Tutorials',
    to: '/docs/tutorials/overview',
    desc: 'Build a wallet app and a game — complete, runnable walkthroughs.',
  },
];

const sections = [
  {
    title: 'Core Concepts',
    to: '/docs/core-concepts/actors',
    desc: 'Actors, behaviors, props, supervision, mailboxes, lifecycle, ask pattern, dead letters.',
  },
  {
    title: 'Guides',
    to: '/docs/guides/overview',
    desc: 'Message design, ask vs tell, routing, fan-out, rate limiting, sagas.',
  },
  {
    title: 'Runtimes',
    to: '/docs/runtimes/overview',
    desc: 'Fiber for development, Swoole for production, Step for deterministic tests.',
  },
  {
    title: 'HTTP & WebSockets',
    to: '/docs/http/overview',
    desc: 'Typed handlers, routing attributes, middleware, auth, actor-backed WebSockets.',
  },
  {
    title: 'Persistence',
    to: '/docs/persistence/overview',
    desc: 'Event sourcing, durable state, snapshots, and the single-writer principle.',
  },
  {
    title: 'Doctrine',
    to: '/docs/doctrine/overview',
    desc: 'Connection pools, EntityManager pooling, migrations, and HTTP integration.',
  },
  {
    title: 'Observability',
    to: '/docs/observability/overview',
    desc: 'OpenTelemetry traces, metrics, and logs across every actor boundary.',
  },
  {
    title: 'Scaling & Clustering',
    to: '/docs/scaling/overview',
    desc: 'Worker pools on Swoole threads, hash-ring placement, cross-worker asks.',
  },
  {
    title: 'Operations',
    to: '/docs/operations/overview',
    desc: 'Deployment, graceful shutdown, tuning, troubleshooting, and the runbook.',
  },
  {
    title: 'Best Practices',
    to: '/docs/best-practices/overview',
    desc: 'When to use actors, supervision policy, passivation, pooling, testing.',
  },
  {
    title: 'Reference',
    to: '/docs/reference/overview',
    desc: 'Front-door classes, configuration, exceptions, attributes, and gotchas.',
  },
  {
    title: 'Architecture',
    to: '/docs/architecture/design-philosophy',
    desc: 'Design philosophy, internals, and the architecture decision records.',
  },
];

const resources = [
  {
    title: 'API Reference',
    href: 'https://api.nexusactors.com',
    desc: 'Generated phpDocumentor reference for every class in all packages.',
  },
  {
    title: 'GitHub',
    href: 'https://github.com/nexus-actors/nexus',
    desc: 'Source, issues, and discussions.',
  },
  {
    title: 'nexusactors.com',
    href: 'https://nexusactors.com',
    desc: 'Project overview, integrations, and the case for typed actors in PHP.',
  },
  {
    title: 'FAQ & Glossary',
    href: '/docs/faq/overview',
    desc: 'Common questions and the vocabulary of the actor model.',
  },
];

function Card({ title, to, href, desc }) {
  return (
    <Link className={styles.card} to={to} href={href}>
      <h3 className={styles.cardTitle}>{title}</h3>
      <p className={styles.cardDesc}>{desc}</p>
    </Link>
  );
}

export default function Home() {
  return (
    <Layout
      title="Documentation"
      description="Documentation for Nexus, the typed actor system for PHP 8.5+: getting started, core concepts, HTTP, persistence, observability, operations, and full API reference."
    >
      <main className={styles.hub}>
        <header className={styles.hero}>
          <h1 className={styles.heroTitle}>Nexus Documentation</h1>
          <p className={styles.heroTagline}>
            Everything you need to build, run, and operate typed actor systems
            in PHP — from the first <code>spawn()</code> to production on Swoole.
          </p>
          <div className={styles.heroActions}>
            <Link className={styles.ctaPrimary} to="/docs/getting-started/quick-start">
              Quick Start
            </Link>
            <Link className={styles.ctaGhost} to="/docs/">
              Introduction
            </Link>
            <Link className={styles.ctaGhost} href="https://api.nexusactors.com">
              API Reference
            </Link>
          </div>
          <div className={styles.heroInstall}>
            <code>composer require nexus-actors/nexus</code>
          </div>
        </header>

        <section className={styles.section}>
          <h2 className={styles.sectionTitle}>Start here</h2>
          <div className={styles.grid}>
            {startHere.map((item) => (
              <Card key={item.title} {...item} />
            ))}
          </div>
        </section>

        <section className={styles.section}>
          <h2 className={styles.sectionTitle}>Browse the documentation</h2>
          <div className={styles.grid}>
            {sections.map((item) => (
              <Card key={item.title} {...item} />
            ))}
          </div>
        </section>

        <section className={styles.section}>
          <h2 className={styles.sectionTitle}>Resources</h2>
          <div className={styles.grid}>
            {resources.map((item) => (
              <Card key={item.title} {...item} />
            ))}
          </div>
        </section>
      </main>
    </Layout>
  );
}
