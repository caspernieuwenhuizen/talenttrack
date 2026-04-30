<!-- type: feat -->

# #0062 — Replace Spond iCal fetcher with internal JSON API client

> Originally drafted as #0061 in the user's intake batch. Renumbered on intake — #0061 was already taken by the round-1/2/3 polish bundle that shipped across v3.59-v3.63.

## Problem

#0031 shipped a Spond integration shaped around an iCal URL per team. Turns out Spond doesn't publish iCal at all — not per team, not per group, not per user. Their only "calendar sync" is the mobile app writing into the phone's local calendar via OS permissions. There's no URL anything server-side can subscribe to.

The first-30-minutes verification step in #0031 literally flagged this risk and said "if it fails, pause and re-shape." We're in that branch now.

## What still works

Most of #0031 is fine and stays:

- Schema (`tt_teams.spond_*`, `tt_activities.external_id` + `activity_source_key`)
- Team-edit form, admin overview page, the "from Spond" badge
- WP-cron, WP-CLI, the per-team REST sync endpoint
- `SpondSync` (upsert + soft-archive on UID disappearance + conflict rules)
- `SpondTypeResolver` (game-vs-training keyword classifier)

What collapses is `SpondClient` (the iCal fetcher) and `SpondParser` (VEVENT → array). Both were written against a contract that doesn't exist.

## Proposal

Use Spond's internal JSON API at `api.spond.com` — the same one their official mobile and web apps use. Reverse-engineered shape is well-documented in community libraries (Olen/Spond Python, d3ntastic/spond-api Node, martcl/spond OpenAPI sketch). It's undocumented, not closed.

- Login: `POST /core/v1/login` with email + password → returns `loginToken`
- Events: `GET /core/v1/sponds/?groupId=…&minStartTimestamp=…&maxStartTimestamp=…`
- Groups: `GET /core/v1/groups/`

`SpondClient` becomes a JSON client (login, cached token, group-scoped event fetch, 401-retry-once). `SpondParser` becomes a thin normalizer that maps the JSON event shape to the array `SpondSync` already consumes — i.e. `SpondSync` doesn't care that the upstream changed.

### Credential model change

Each Spond event lives inside a Spond group. To fetch events you need a logged-in account that's a member of that group:

- **Per-club credentials** (one Spond email + password) instead of per-team URLs. One coach/manager account that's in every team the club wants to sync. That's how clubs already operate.
- **Per-team `spond_group_id` mapping** which TalentTrack team corresponds to which Spond group. Picked from a dropdown populated by the groups endpoint, not pasted in as hex.
- Old `spond_ical_url` column gets nulled out and deprecated; dropped in a later spec.

## Wizard plan

**Existing wizard extended**: the `?tt_view=wizard&slug=spond-setup` (or equivalent in #0024 setup wizard) gains a "Connect Spond" step that captures the per-club credentials. Team-creation wizard gains a "Pick Spond group" branch (gated on club having credentials).

## Open shaping questions

| # | Question | Why it matters |
|---|----------|----------------|
| Q1 | 2FA on the Spond account: hard-fail with a clear message, or attempt to support? | Probably hard-fail in v1 — clubs use a non-2FA dedicated account. |
| Q2 | Token caching TTL — 24h based on community libraries' assumptions, or shorter? | Verify against a real account. |
| Q3 | Wizard hooks — extend #0024 setup wizard with "Connect Spond"? Extend team-creation wizard with "Pick Spond group"? | Probably both, gated on credentials existing. |

## Out of scope

- Hosted JSON proxy service — rejected. Adds operational surface area (deploy, monitor, secure, pay) for a single-tenant problem the plugin can solve in-process. Every other TalentTrack integration runs in-process.
- Two-way write-back to Spond. v1 is read-only.
- Inbound webhooks — Spond doesn't publish them; daily/hourly cron poll is the model.

## Risks

- **Undocumented upstream** — Spond can change paths/shapes any time. Mitigation: all upstream calls go through `SpondClient`, all field-name knowledge lives in the normalizer, failures are non-destructive.
- **Terms of service is silent** on third-party clients. We use credentials a club already owns to read data the club already has. If Spond formally objects, we deprecate the integration.

## Verify in the first 30 minutes of build

Same posture as #0031 — pause and re-shape if any of these fail:

- `POST /core/v1/login` still accepts `{email, password}` and returns `loginToken`
- `GET /core/v1/sponds/?groupId=…` returns events with the field names this idea assumes (`id`, `heading`, `startTimestamp`, `endTimestamp`, `location.feature`, `description`, `cancelled`, `lastModified`)
- Group IDs are stable across edits in Spond
- `loginToken` is genuinely long-lived (~24h)
- 2FA failure response shape is recognisable for the error message

## Acceptance criteria

- [ ] `SpondClient` is a JSON HTTP client with login / token-cache / group-scoped event fetch / 401-retry-once.
- [ ] `SpondParser` normalizes the JSON event payload into the array shape `SpondSync` already consumes — no `SpondSync` changes.
- [ ] Per-club credential storage (email + password) replaces per-team iCal URLs.
- [ ] Per-team `spond_group_id` picker populated from `/groups/`.
- [ ] Migration nulls the old `spond_ical_url` column on existing rows; column kept in schema for one release.
- [ ] All `SpondSync` upsert / archive logic continues to work unchanged.
- [ ] WP-cron + WP-CLI + REST sync endpoints all still function.
- [ ] CI green; PHP lint clean; nl_NL.po updated.

## Notes

This idea **supersedes #0031 for the fetcher and credential model**. The schema/UI/upsert/cron/CLI from #0031 stays.

## Estimated effort

~12-18h v1 (matches the original #0031 estimate; mostly re-implementing `SpondClient` against a different protocol while keeping every downstream consumer unchanged).
