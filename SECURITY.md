# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in Paragrafy, **please do not open a public GitHub issue**. Publicly disclosing a vulnerability before it's fixed puts every self-hosted instance at risk.

Instead, report it discreetly by email to:

**security@paragrafy.cloud**

Please include as much of the following as you can:

- A description of the vulnerability and its potential impact
- Steps to reproduce, or a proof of concept
- The affected version/commit and deployment method (Docker / Apache bare metal)
- Any suggested mitigation, if you have one

We'll acknowledge your report and keep you updated as we investigate and fix the issue. Once a fix is released, we're happy to credit you in the changelog or release notes, if you'd like.

## Supported Versions

Paragrafy follows [CalVer](https://calver.org/) (`YEAR.MONTH.BUILD`). Security fixes are applied to the latest release; we recommend always running the most current version. There is no long-term support for older releases — please keep your instance up to date (see [Upgrading](README.md#-upgrading) in the README).

## Response Times

We aim to:

- Acknowledge new reports within **3 business days**
- Provide an initial assessment within **7 business days**
- Release a fix for confirmed critical vulnerabilities as quickly as possible, typically within a few days

Response times may vary depending on severity and complexity. Thank you for helping keep Paragrafy and its users safe.
