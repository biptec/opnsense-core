# AGENTS Guidelines for OPNsense Core

Repository-level guidance for coding agents working on the OPNsense core repository.

OPNsense® is an open source, easy-to-use and easy-to-build FreeBSD based firewall and routing platform.

## Mission

Give users, developers and businesses a friendly, stable and transparent environment. Make OPNsense the most widely used open source security platform. The project’s name is derived from open and sense and stands for: “Open (source) makes sense.”

Reference: [Mission Statement](https://docs.opnsense.org/intro.html#mission-statement)

## Core Principles

- Prefer small, reviewable changes over broad rewrites.
- Preserve existing behavior unless the task explicitly requires changing it.
- Follow nearby code and existing subsystem patterns before introducing new ones.
- Keep ownership clear: model configuration belongs to OPNsense; runtime state belongs to the daemon or operating system unless explicitly managed.
- Do not bypass framework layers, validation, ACLs, configd, templates, or service integration to make a change appear simpler.
- Treat firewall, routing, VPN, authentication, certificates, updates, command execution, migrations, and privilege boundaries as security-sensitive.

## Architecture

OPNsense core is built around a PHP/Phalcon MVC frontend, XML-backed configuration models, backend service actions, templates, migrations, and FreeBSD system integration.

| Area | Purpose |
|------|---------|
| `src/opnsense/mvc/app/models/` | XML models and validation |
| `src/opnsense/mvc/app/controllers/` | API and page controllers |
| `src/opnsense/mvc/app/views/` | Volt templates and UI code |
| `src/opnsense/mvc/app/library/` | shared components |
| `src/opnsense/mvc/tests/` | phpunit tests |
| `src/opnsense/service/conf/actions.d/` | configd actions |
| `src/opnsense/service/templates/` | generated service configuration |
| `src/opnsense/scripts/` | backend helper scripts |
| `src/etc/inc/` | legacy PHP/system integration |
| `src/etc/rc*/` | boot and service integration |

Reference: [Development Workflow](https://docs.opnsense.org/development/workflow.html)

## Code Style

- Follow the style already used in the touched file and subsystem.
- Use clear variable names; avoid vague names like `data`, `tmp`, or `result2` when the value has meaning.
- Prefer early returns and straightforward control flow.
- Keep helpers when they name a meaningful operation; inline helpers that only hide one-off logic.
- Keep comments useful: explain intent, ownership, constraints, or non-obvious behavior.
- Avoid formatting-only churn in unrelated lines.
- Keep public API shape stable unless a breaking change is intentional.
- Wrap user-facing PHP strings in `gettext()` where appropriate, important: except log messages

Reference: [Coding Guidelines](https://docs.opnsense.org/development/guidelines.html)

## Security Expectations

Use extra scrutiny for changes touching firewall rules, NAT, routing, VPNs, authentication, authorization, sessions, CSRF, ACLs, certificates,
firmware, packages, shell execution, file writes as root, import/export, backup/restore, and migrations.

Reference: [Security](https://docs.opnsense.org/security.html)

## Review Checklist

A good change should be easy to explain:

- What problem does it solve?
- Which layer owns the change?
- What existing behavior is preserved?
- What failure mode was considered?
- How was it tested?

Avoid PRs that mix unrelated refactors, formatting, and behavior changes.

## Further Reading

- [Mission Statement](https://docs.opnsense.org/intro.html#mission-statement)
- [Security](https://docs.opnsense.org/security.html)
- [Development Workflow](https://docs.opnsense.org/development/workflow.html)
- [Coding Guidelines](https://docs.opnsense.org/development/guidelines.html)
- [API Reference](https://docs.opnsense.org/development/api.html)
- [AGENTS.md format](https://github.com/agentsmd/agents.md)
