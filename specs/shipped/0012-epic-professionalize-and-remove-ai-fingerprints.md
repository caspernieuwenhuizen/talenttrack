<!-- type: epic -->

# #0012 — Professionalize + remove AI fingerprints

## Problem

The plugin's public presentation — readme, docs, CHANGES, ideas folder, inline commit messages — currently reads like it was produced with heavy AI assistance. Specific tells:

- Frequent em dashes in expository prose (928 across the repo, 76 in `readme.txt` alone).
- "Tool X said" narration in DEVOPS.md and some idea files.
- Uniform bullet pacing and section rhythms that trigger "this is AI" radar.
- Public references to internal shaping workflow specifics.

None of these are *wrong* — the content is accurate and useful. But for a plugin that will eventually be sold (via #0011), the presentation matters. Buyers evaluating a paid plugin will scan the repo and GitHub; a presentation that reads as AI-generated signals "maintained by one person with AI, possibly unreliable."

Simultaneously, the plugin has no public `README.md`, no `CONTRIBUTING.md`, no `SECURITY.md`, no test suite, no code-style enforcement. These are low-risk, high-signal additions for professional credibility.

## Proposal

A quality-and-presentation pass in two parts:

- **Part A — Remove AI fingerprints from public docs** (cosmetic, high-visibility, ~4–6 hours).
- **Part B — Add real engineering hygiene** (`README.md`, `CONTRIBUTING.md`, `SECURITY.md`, PHPCS rules, test suite with 40% floor, CI workflow).

Both parts ship together as a single Phase 4 block per shaping decision.

## Scope

### Part A — Remove AI fingerprints

Four sub-deliverables:

**A1. Em-dash reduction.** 928 across the repo, 76 in `readme.txt`. Not full removal (reads stiff) — a sampling-and-rewrite approach:
- Sample 20 em dashes at random from `readme.txt`.
- Rewrite each one using a comma, parenthesis, or new sentence.
- Read the result. If it reads natural, scale that approach across the repo.
- Goal: reduce em-dash count by ~50–70%. Not zero.

**A2. Strip tool-specific references.**
- `ideas/README.md`, `specs/README.md`: remove "Claude Code" / "AI" references; describe the workflow abstractly.
- `DEVOPS.md`: keep the workflow description, remove tool names and AI-specific language. Shaping workflow stays documented — it's a *good* thing to show the process — just not tied to a named tool.
- `ideas/0002-*.md`: this specific idea has AI-workflow narration; rewrite.
- Move tool-specific workflow details (prompts, agent setup, etc.) to a **private** `CONTRIBUTING.internal.md` that's not in the public repo — e.g. in a `.gitignore`'d notes folder or a private fork.

**A3. Flatten uniform AI rhythm.**
Vary sentence lengths and bullet-list cadence in public-facing docs. Specifically:
- `readme.txt` (WordPress.org-style readme)
- Plugin's main description (Plugins page)
- `docs/*.md` (the English docs)

Not a full rewrite — a polish pass. Look for stretches of "bullet, bullet, bullet" and break with a connecting sentence; look for uniform 2-clause sentences joined by em dashes and vary.

**A4. Copy-editing pass on translated docs.**
Per shaping: the em-dash and bullet-pacing tells translate surprisingly well into other languages. So `docs/nl_NL/*.md` (and once #0010 ships, the fr/de/es translations) get the same polish treatment, not just the English source.

### Part B — Engineering hygiene

**B1. Root-level docs.**

- `README.md` — GitHub-facing description of the plugin. What it does, who it's for, current status, install instructions, link to full docs. 1–2 pages. Written for a developer browsing GitHub, not an end user.
- `CONTRIBUTING.md` — how external contributors can report bugs, propose changes, submit PRs. Written at the level a new external contributor would need. No internal workflow specifics.
- `SECURITY.md` — vulnerability disclosure policy. "Email security@{domain} instead of opening a public issue." Simple.
- `CODE_OF_CONDUCT.md` — standard Contributor Covenant. Copy from the official template.
- `CHANGELOG.md` at repo root — mirrors `CHANGES.md` content but in the standard `Keep a Changelog` format (markdown, headers per version, Added/Changed/Deprecated/Removed/Fixed sections). CHANGES.md can continue to exist for the WP readme format; CHANGELOG.md is for GitHub browsing.

**B2. Code style enforcement.**

- `phpcs.xml.dist` — PHPCS configuration based on the WordPress Coding Standards + some plugin-specific relaxations (the existing codebase won't be 100% WPCS-compliant; choose a pragmatic subset).
- `.github/workflows/ci.yml` — runs PHPCS on every PR and push. Blocks merge on violations.
- One-time codebase cleanup: run PHPCS with `--fix` and commit the auto-fixable changes.
- Manual cleanup: address any remaining PHPCS warnings that aren't auto-fixable.

**B3. Test suite.**

- `phpunit.xml.dist` — PHPUnit configuration for the plugin.
- `tests/` folder with:
  - `tests/bootstrap.php` — WP test bootstrap.
  - `tests/unit/` — pure PHP unit tests.
  - `tests/integration/` — WP-aware integration tests.
- Write tests targeting a **40% coverage floor**, focused on catching regressions on critical paths:
  - Authorization: capability checks, role grants.
  - Query layer: scope filters (especially the demo-mode filter from #0020).
  - Migrations: each migration runs cleanly and is idempotent.
  - REST controllers: auth, validation, happy paths.
  - PlayerStatsService: rolling averages, trend calculation.
- Integrate coverage reporting in CI. Publish coverage as a badge in README.md — but frame it as "regression safety" not "coverage chasing."

**B4. Security audit.**

Quick pass:
- All `$_GET`, `$_POST`, `$_REQUEST` usages sanitized via `sanitize_*()` / `wp_unslash()` / etc.
- All SQL via `$wpdb->prepare()`.
- All output via `esc_html()` / `esc_attr()` / `esc_url()` / `wp_kses()`.
- All AJAX/REST endpoints have nonce verification.
- Capability checks on every write path.

Fix any findings. This isn't a full pen-test — it's the hygiene pass a WordPress plugin should have passed before going public.

**B5. PHPDoc on public methods.**

Public methods in `src/Modules/*/` get PHPDoc blocks with `@param`, `@return`, and a one-line description. Private helpers can stay un-commented.

## Out of scope

- **Writing the actual privacy statement.** That's #0011's scope (monetization + privacy).
- **Full rewrite of the plugin's description / marketing copy.** Part A polishes it; a marketing-driven rewrite is a separate concern.
- **Continuous delivery / automated release pipeline.** CI runs tests; releases stay manual.
- **Full static analysis (PHPStan, Psalm).** Addable later. PHPCS is the v1 gate.
- **Mutation testing, BDD, or other advanced testing techniques.** Standard PHPUnit only.

## Acceptance criteria

### Part A

- [ ] Em-dash count in `readme.txt` reduced by 50%+ with natural-reading prose.
- [ ] Overall repo em-dash reduction at least 30% (accepts that some dashes are appropriate).
- [ ] No references to "Claude Code," "Cursor," or other named AI tools in public repo files.
- [ ] `DEVOPS.md` describes the shaping workflow abstractly.
- [ ] Private `CONTRIBUTING.internal.md` exists outside the public repo (user's local notes or private fork).
- [ ] Flattened AI rhythm in `readme.txt`, plugin description, and `docs/*.md`.
- [ ] `docs/nl_NL/` polished; fr/de/es polished when translations land.

### Part B

- [ ] `README.md` at repo root — developer-facing, well-written.
- [ ] `CONTRIBUTING.md` at repo root — external-contributor-focused.
- [ ] `SECURITY.md` at repo root — vulnerability disclosure.
- [ ] `CODE_OF_CONDUCT.md` at repo root.
- [ ] `CHANGELOG.md` at repo root in Keep-a-Changelog format (CHANGES.md preserved too).
- [ ] `phpcs.xml.dist` exists and enforces chosen standards.
- [ ] `.github/workflows/ci.yml` runs PHPCS + PHPUnit on every PR and push.
- [ ] Codebase passes PHPCS without violations.
- [ ] Test suite with **≥40% code coverage** across the codebase.
- [ ] Critical modules (Authorization, Query layer, Migrations, REST) have ≥70% coverage.
- [ ] Coverage badge in README.md.
- [ ] Security-audit findings resolved.
- [ ] Public methods in `src/Modules/*/` have PHPDoc.

### No functional regression

- [ ] No code behavior changes.
- [ ] No user-visible feature changes.
- [ ] All existing tests and manual workflows pass.

## Notes

### Sizing

**Part A: ~10–15 hours.**
- Em-dash pass across repo: ~3 hours
- Tool-specific reference stripping: ~2 hours
- AI-rhythm flattening across public docs: ~3 hours
- Nl docs polish: ~2 hours
- Later: fr/de/es docs polish (when translations land) — ~3 hours per language, deferred

**Part B: ~40–60 hours.**
- Root-level docs (README, CONTRIBUTING, SECURITY, CoC, CHANGELOG): ~4 hours
- PHPCS setup + one-time cleanup: ~6 hours
- PHPUnit setup + critical-path tests (40% floor): ~25–40 hours (this is most of the sprint)
- Security audit + fixes: ~4 hours
- PHPDoc pass: ~4 hours
- CI workflow: ~2 hours

**Total: ~50–75 hours** across both parts.

### Sequence position

Per shaping decision: full execution as a single Phase 4 block. Not split.

That said, **Part A is low-enough-risk that it could be cherry-picked earlier** if you want visible GitHub polish before #0011 (monetization) goes live. Flag for consideration during execution — not preemptive.

### Depends on

Nothing hard. The test-writing work benefits from most of the backlog being in place (more code = more tests), but doesn't strictly need any specific epic to finish first.

### Blocks

Nothing strictly. But #0011 (monetization) benefits enormously from this being done first — a paid plugin with no README, no tests, no security disclosure, and em-dash-heavy prose in every file is a harder sell.

### Touches

Most of the public-facing surface:

- `readme.txt`, plugin main description
- `CHANGES.md`, new `CHANGELOG.md`
- `ideas/README.md`, `specs/README.md`, `DEVOPS.md`, any idea files with AI narration
- `docs/*.md`, `docs/nl_NL/*.md` (and fr/de/es when available)
- New: `README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`
- New: `phpcs.xml.dist`, `phpunit.xml.dist`, `.github/workflows/ci.yml`
- New: `tests/` folder with suite
- Security hardening: anywhere findings emerge in the audit
- PHPDoc: public methods in `src/Modules/*`

No production code behavior changes. This is all quality, presentation, and safety-net work.
