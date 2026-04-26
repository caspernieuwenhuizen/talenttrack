<!-- audience: user, admin, dev -->

# TalentTrack documentation

A layered table of contents. The in-product `Help & Docs` page filters this list by your role automatically; the full layered view is here for browsing the repo directly. See [contributing](contributing.md) for the audience-tag rule and translation discipline.

## User docs

For coaches, players, parents-as-observers, and anyone reading TalentTrack data day-to-day.

- [Getting started](getting-started.md) — first session in the app.
- [Coach dashboard](coach-dashboard.md) — tile grid + group landing.
- [Player dashboard](player-dashboard.md) — what a player sees.
- [Evaluations](evaluations.md) — rate a player.
- [Sessions](sessions.md) — train, attend, take notes. Includes [guest attendance](sessions.md#guest-attendance-v3220).
- [Goals](goals.md) — development goals per player.
- [Reports](reports.md) — progress, team averages, coach activity.
- [Player rate cards](rate-cards.md) — deep per-player view.
- [Player comparison](player-comparison.md) — side-by-side up to four players.
- [Methodology](methodology.md) — your academy's coaching framework, principles, set pieces, voetbalhandelingen.
- [Bulk actions](bulk-actions.md) — archive vs. delete, multi-row operations.
- [Printing & PDF export](printing-pdf.md) — clean printable reports.

## Admin docs

For club admins and head-of-development users configuring the install.

- [Setup wizard](setup-wizard.md) — first-run guided installer.
- [Teams & players](teams-players.md) — manage rosters.
- [People (staff)](people-staff.md) — coaches, physios, scouts.
- [Evaluation categories & weights](eval-categories-weights.md) — main categories, subcategories, age-group weighting.
- [Custom fields](custom-fields.md) — club-specific extensions.
- [Configuration & branding](configuration-branding.md) — academy name, logo, palette, lookups.
- [Access control](access-control.md) — roles, permissions, functional roles, observer.
- [Usage statistics](usage-statistics.md) — logins, DAU, evaluation trends.
- [License & account](license-and-account.md) — tier, trial, caps, upgrade.
- [Backups & disaster recovery](backups.md) — scheduled exports, partial restore.
- [Migrations & updates](migrations.md) — what happens on plugin update.

## Cross-cutting (user + admin)

- [Methodology](methodology.md) — coaches consume; admins author.
- [Reports](reports.md) — both audiences run them.
- [Player rate cards](rate-cards.md) — both audiences read them.
- [Bulk actions](bulk-actions.md) — both audiences invoke them.
- [Getting started](getting-started.md) — onboarding for either role.

## Developer docs

English-only by design. For plugin extenders, theme integrators, and reviewers.

- [REST API reference](rest-api.md) — endpoints, payload shapes, capability scopes.
- [Hooks & filters](hooks-and-filters.md) — every `do_action` / `apply_filters` exposed.
- [Architecture](architecture.md) — module pattern, kernel boot order, schema.
- [Theme integration](theme-integration.md) — token contract, `body.tt-theme-inherit`.

## Translation discipline

Every doc tagged `user` or `admin` (or both) has a Dutch counterpart in `docs/nl_NL/`. Dev-tagged docs are English-only. See [contributing](contributing.md) for the rule and the audience-marker syntax.
