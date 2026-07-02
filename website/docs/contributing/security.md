---
title: Security Policy
related:
  - contributing/your-first-pr
---

# Security Policy

## Supported versions

As Nexus is pre-1.0 and under active development, security fixes are applied to the latest version on the `main` branch only. Once Nexus reaches 1.0 this policy will be updated to cover the current major version line.

## Reporting a vulnerability

If you discover a security vulnerability in Nexus, report it responsibly. **Do not open a public issue.**

Use [GitHub's private vulnerability reporting](https://github.com/nexus-actors/nexus/security/advisories/new) feature. This keeps the report confidential until a fix is released and allows coordinated disclosure.

### What to include

- A description of the vulnerability and its potential impact
- Steps to reproduce the issue, if possible
- Any suggested fixes or mitigations

### What to expect

- **Acknowledgment** within 3 business days of your report
- We will work with you to understand and validate the issue
- A fix will be developed and released as quickly as possible
- We follow a **90-day disclosure timeline**: if a fix cannot be released within 90 days, we coordinate with you on public disclosure

## Credit

We are happy to credit reporters in release notes and security advisories unless you prefer to remain anonymous.

## Scope

Security issues in any of the following are in scope:

- Authentication and authorization bypass in `nexus-http-auth`
- Arbitrary code execution via deserialization in `nexus-serialization`
- Information disclosure through dead letter or persistence stores
- Privilege escalation via the worker pool or cluster transport
- Denial of service via mailbox exhaustion or scheduling loops

Issues in third-party dependencies should be reported upstream. If a dependency vulnerability directly affects Nexus users, open a private advisory so we can coordinate a dependency update.
