---
title: Review gate — security and privacy
group: developer
summary: The checklist a PR must pass when it touches authorization, REST permissions, medical, safeguarding or guardian data, uploads or authentication.
audience: [dev]
order: 40
---

# Review gate: security & privacy

**Triggered by** any change to: `src/Modules/Authorization/**`, a REST
`permission_callback`, `MatrixGate` or `AuthorizationService` calls, the
matrix seed, injuries, measurements, safeguarding notes, PDP content,
guardian contact data, media upload or delivery, login, MFA, invitations,
application passwords, the Admin Center client.

**Run by** the architect, with a security review subagent reading the diff
cold. The reviewer ticks the list in the PR; an unticked line is a blocker.

TalentTrack holds data about minors. The default is less visibility, and
asking (`CLAUDE.md` §1, Privacy and dignity).

## Checklist

- [ ] Every new or changed endpoint declares a `permission_callback` that
      checks a capability through the matrix or `AuthorizationService`.
      Never `__return_true`, never a role-name string compare
      (`CLAUDE.md` §4).
- [ ] Scope is enforced in the query, not after it: a coach's team scope, a
      parent's own child, a scout's own prospects are `WHERE` clauses, so
      a wider row can never be fetched and then hidden
      (`docs/access-control.md`).
- [ ] A surface that takes a record id checks that record belongs to the
      caller's scope, not merely that the caller holds the entity
      (`docs/contributing.md`, #4004 rule). A tab or button is offered only
      where the panel behind it would answer 200.
- [ ] Reads of medical, safeguarding or guardian data are written to the
      audit log with who, when and which player (`docs/injuries.md`,
      Privacy).
- [ ] No role name leaks into a payload; expose capabilities, not roles
      (`CLAUDE.md` §4).
- [ ] Nothing new is stored in `wp_options` or `wp_usermeta` that is
      tenant-scoped; tenant config goes in `tt_config` keyed by `club_id`.
- [ ] New tables carry `club_id` and, for root entities, `uuid`; new
      repositories filter by `club_id` (`CLAUDE.md` §4).
- [ ] Uploads: content-sniffed type whitelist, SVG refused, EXIF stripped,
      stored in the private media store, never the WP media library
      (`MediaModule`); URLs returned, never server paths.
- [ ] No inline `<script>`, no `onclick=` attributes, no inline `style=` in
      `src/**/*.php` (`CLAUDE.md` §2); nonces on every REST call.
- [ ] All output escaped at the point of output; all input sanitised at the
      point of entry; `$wpdb->prepare` with a literal query string.
- [ ] Nothing in the PR, its description or its tests discloses an
      unpatched vulnerability. Findings go to a private GHSA draft; the
      repository is public.
- [ ] A test exists for the refusal path: the persona that must not see or
      change the thing gets 401/403 or an empty set, and the test fixture
      grants through the auth bridge, not `add_cap` (project memory: fixtures).

## Sweep (quarterly)

Run the Academy HQ with every persona against every REST route it can
discover and diff the answers against `docs/authorization-matrix.md`;
grep every `register_rest_route` for its `permission_callback`; list every
read of `tt_player_injuries`, `tt_measurements`, safeguarding and guardian
tables and confirm each is audit-logged. File each finding privately first.
