<!-- type: feat -->

# #0032 — Invitation flow: invite players, parents, and staff via shareable link

## Problem

Onboarding the people behind a player is the worst friction point in the product today. An admin manually creates a WP user, manually links it to a `tt_players` or `tt_people` row, manually emails credentials. For a 50-player academy with 1-2 parents per player plus a coach + assistant per team, that's hundreds of manual steps per season — and most clubs simply skip the WP-account part, losing the player + parent dashboard entirely.

The fix is a token-based invitation flow that an admin can share via WhatsApp (the default coordination channel for Dutch youth football). Recipient taps the link, picks a password, lands in their own dashboard. Three role variants — player / parent / staff — share the flow shape, differ only in what gets linked on accept.

## Proposal

A new `Invitations` module that:

1. Generates one-time signed URLs bound to a specific role + entity link.
2. Surfaces a **WhatsApp share button + copy-link** on the entity edit form (frontend roster + wp-admin) and a centralised **Configuration → Invitations** admin list with revoke + audit.
3. On the recipient's first follow-through: a frontend acceptance route on the dashboard shortcode collects only what's needed (password + recovery email), creates the WP user, runs a role-specific assignment step (jersey number for players, relationship confirmation for parents, functional-role + team for staff), and lands them on their dashboard.

## Scope

### Schema (migration 0025)

`tt_invitations`:

```
id BIGINT UNSIGNED PK
token VARCHAR(64) UNIQUE NOT NULL                     -- 32-char URL-safe random; 24 bytes entropy
kind ENUM('player','parent','staff') NOT NULL
target_player_id BIGINT NULL                          -- player + parent invites
target_person_id BIGINT NULL                          -- staff invites
target_team_id BIGINT NULL                            -- staff invites (optional)
target_functional_role_key VARCHAR(64) NULL           -- staff invites (optional)
prefill_first_name VARCHAR(100) NULL
prefill_last_name VARCHAR(100) NULL
prefill_email VARCHAR(255) NULL
locale VARCHAR(10) NULL                               -- resolved at create-time per the locale precedence
created_by BIGINT UNSIGNED NOT NULL                   -- WP user id
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
expires_at DATETIME NOT NULL
accepted_at DATETIME NULL
accepted_user_id BIGINT UNSIGNED NULL
revoked_at DATETIME NULL
revoked_by BIGINT UNSIGNED NULL
status ENUM('pending','accepted','expired','revoked') NOT NULL DEFAULT 'pending'
PRIMARY KEY (id)
KEY idx_token (token)
KEY idx_status (status)
KEY idx_target_player (target_player_id)
KEY idx_target_person (target_person_id)
```

`tt_player_parents` (replaces single-column `tt_players.parent_user_id` for many-to-many):

```
player_id BIGINT UNSIGNED NOT NULL
parent_user_id BIGINT UNSIGNED NOT NULL
is_primary TINYINT(1) NOT NULL DEFAULT 0
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
PRIMARY KEY (player_id, parent_user_id)
KEY idx_parent (parent_user_id)
```

Migration also backfills `tt_player_parents` from existing `tt_players.parent_user_id` rows with `is_primary = 1`, then keeps `tt_players.parent_user_id` as a derived shortcut: a trigger (or a service-side hook on insert/update of `tt_player_parents`) re-projects the `is_primary = 1` row into the column. This keeps #0022's `PlayerOrParentResolver` working without rewrites.

### Module structure

```
src/Modules/Invitations/
  InvitationsModule.php
  InvitationToken.php          — generate (32-char URL-safe) + validate
  InvitationService.php        — create / accept / revoke / list
  InvitationsRepository.php    — CRUD + atomic accept-flip
  PlayerParentsRepository.php  — pivot CRUD + is_primary projection
  Frontend/
    AcceptanceView.php         — renders ?tt_view=accept-invite
    AcceptanceHandler.php      — admin-post.php?action=tt_invitation_accept
    InviteButton.php           — reusable share button (WhatsApp + copy-link)
  Admin/
    InvitationsListPage.php    — Configuration → Invitations tab
    InviteMessageEditor.php    — six-default editor for tt_config keys
  Notifications/
    InvitationAuditLogger.php  — listens to tt_invitation_* hooks, writes audit
```

### Capabilities

- `tt_send_invitation` — granted to administrator + tt_head_dev + tt_club_admin + tt_coach (coach can share player + parent invites for their own team's roster).
- `tt_revoke_invitation` — granted to administrator + tt_head_dev + tt_club_admin only.
- `tt_manage_invite_messages` — administrator + tt_club_admin only.

### Trigger model — invitation auto-created on roster/assignment events

- **Player invite**: created when a player is added to a team's roster (subscribe to the existing roster-add event). Surfaces as a "Share invite" button on the roster row.
- **Parent invite**: not auto-fired on roster add (parent isn't on the roster directly). Two surfaces: (a) auto-prompt at player-add time ("Player saved. Generate parent invite now?") suppressed during CSV import via the session flag `tt_csv_import_active`; after a CSV import, a single batch action "Generate invites for the 47 newly-imported players?" with per-row checkboxes; (b) manual "Invite parent" button on the player edit form, available always.
- **Staff invite**: created when a person is assigned to a team via Functional Role. Surfaces on the assignment row.

### Acceptance URL format

`<site>/dashboard/?tt_view=accept-invite&token=<32-char>` (frontend route on the dashboard shortcode). Wired from `DashboardShortcode::dispatchInvitationView()`. The `?tt_view` slug renders the same brand chrome as the rest of the dashboard.

If the visitor is **already logged in and their email matches the invitation's prefill_email**, silent-link path: link the entity, mark accepted, redirect to dashboard with a flash banner. If logged in but email doesn't match, show a "Sign out and accept as the invited user?" interstitial. If not logged in, show the acceptance form.

### Acceptance form (recipient flow)

Single-page form with three sections:

1. **Account** — recovery email (required, shown first), password + confirm-password.
2. **Role-specific assignment step** — content varies:
   - **Player**: pick jersey number, confirm photo + DOB (prefilled from the linked `tt_players` row).
   - **Parent**: confirm relationship to player ("I am the mother / father / guardian of {player}"), checkbox to receive notifications about goals + evaluations.
   - **Staff**: confirm functional role + team (prefilled from invitation), recovery email already collected in section 1.
3. **Submit** — handler creates the WP user with the appropriate role, runs the linking step, fires `tt_invitation_accepted`, marks the invitation accepted, calls `wp_set_auth_cookie`, redirects to the dashboard.

### Linking logic per role variant

| Variant | WP role | Linking writes |
| - | - | - |
| Player | `tt_player` | `tt_players.wp_user_id = <new>`; `tt_players.jersey_number` if provided |
| Parent | `tt_parent` (new role) | `tt_player_parents (target_player_id, <new>, is_primary = (no other parents yet ? 1 : 0))` |
| Staff | role per `tt_functional_roles[target_functional_role_key].default_wp_role` (e.g. `tt_coach`, `tt_staff`) | `tt_people.wp_user_id`; functional-role assignment row written via existing `FunctionalRoleAssigner` |

### `tt_parent` WP role (new)

Added to `RolesService::roleDefinitions()`. Capabilities:
- `read` (always)
- `tt_view_parent_dashboard` (new cap, gates the "Children" group on the tile grid)

The parent dashboard shows a "Children" tile group listing each linked player, each tile drilling into the player's evaluations / goals / sessions in a read-only view. No `tt_view_evaluations` etc. capabilities are granted directly — the parent-specific view re-implements those reads scoped to the linked players (via `tt_player_parents`).

### Share UI — `InviteButton` component

Reusable component rendered on:
- **Frontend roster row** (`FrontendTeamRosterView` — for the coach sharing from their phone).
- **wp-admin player edit form** (`PlayersPage` — for the admin doing season-start onboarding from a desktop).
- **wp-admin people edit form** (`PeoplePage` — for staff invites).

Component HTML: a "Share invite" button that opens a popover with:
- The acceptance URL pre-filled in a copy-button input.
- Three share buttons: **WhatsApp** (`https://wa.me/?text=<urlencoded-message>`), **Email** (default off, opens `mailto:` with subject + body), **Copy link** (clipboard + flash).
- A live preview of the WhatsApp message.

Message text resolves through:
1. `tt_players.locale` if set (player + parent invites) OR `tt_people.locale` (staff invites).
2. `tt_config.invite_default_locale` (club default).
3. Inviter's WP locale as final fallback.

The resolved locale picks the right `tt_config.invite_message_<role>_<locale>` template, then expands placeholders: `{club}`, `{role}`, `{team}`, `{player}`, `{sender}`, `{url}`, `{ttl_days}`. Six defaults ship out of the box (3 roles × 2 locales) per the idea file; the **Configuration → Invitations** tab includes a per-template editor with `{url}`-required validation.

### Configuration → Invitations admin list

Single page covering both invitation operations and message-template editing. Two tabs:

- **Invitations** — paginated list of all invitations (filter by status / kind / inviter / date). Per-row actions: revoke (with confirm), copy-link, re-share (regenerates the URL if expired). Audit log entries inline.
- **Messages** — six message-text editors with placeholder reference + validation.

Both gated by `tt_manage_invite_messages` (administrator + tt_club_admin).

### Audit + rate limit

Every event written to the existing audit-log surface: `invitation.created`, `invitation.accepted`, `invitation.revoked`, `invitation.expired`. The acceptance event records IP + user-agent for forensics; other events record actor + entity only.

Rate limit: 50 invitations per admin per 24 hours, soft. Override paths:
- `apply_filters( 'tt_invitation_daily_cap', 50, $user_id )` — for hosts that need a higher cap permanently.
- "Continue anyway" button on the cap-hit screen — records `who clicked + why (free-text)` in the audit log.

### Workflow integration (#0022 hook)

`InvitationService::accept()` fires `do_action( 'tt_invitation_accepted', $invitation_id, $kind, $accepted_user_id )` after the linking step succeeds. v1 ships no workflow template subscribing to this; v1.5 adds a "Welcome / set jersey number" task template that fans out to the new player.

### Locale precedence resolution

`InvitationService::resolveLocale( $invitation )`:

```
1. If invitation->target_player_id and tt_players[target].locale is set, use that.
2. Else if invitation->target_person_id and tt_people[target].locale is set, use that.
3. Else use tt_config.invite_default_locale.
4. Else fall back to inviter's WP locale (get_user_locale($created_by)).
```

### Frontend dispatch

`DashboardShortcode::dispatchInvitationView()` handles `?tt_view=accept-invite` (no auth required — the token is the credential). Wired into the existing `$dev_slugs`-style dispatch pattern from #0009.

### Ship-along

- `languages/talenttrack-nl_NL.po` — invite-button + acceptance-form + admin-list + 6 default messages (curated translations, the WhatsApp message text matters).
- `docs/invitations.md` + `docs/nl_NL/invitations.md` with audience marker `<!-- audience: admin -->`.
- `HelpTopics` — new `invitations` topic in the `configuration` group.
- `DEVOPS.md` — no new constants needed.
- `SEQUENCE.md` — flips #0032 from Needs shaping → Done at release time.

## Out of scope (v1)

- **SMS channel** — would require a 3rd-party gateway decision; defer.
- **CSV-driven bulk invite** — the CSV importer + the post-import batch action covers most needs.
- **In-app message centre** for invitees ("your invite from \[club] is waiting").
- **Public registration page** — invitations are explicit acts by an admin, no self-serve sign-up.
- **Federated identity** (Google / Facebook OAuth) — WP password is v1; SSO is a separate concern.
- **Branded HTML invitation emails** with logo + colours — plain text v1.
- **Per-team customisation of invitation message templates** — one default per role per locale; per-team override deferred.
- **Two-factor on the acceptance flow** — token is the credential.
- **Cap-aware rejection** when the club has hit its #0011 license cap — wired in #0011's feature-audit sweep.

## Acceptance criteria

- [ ] Migration `0025_invitations` creates `tt_invitations` + `tt_player_parents` and backfills the pivot from existing `tt_players.parent_user_id` rows with `is_primary = 1`.
- [ ] New `tt_parent` WP role exists with `read` + `tt_view_parent_dashboard` capabilities; existing parent rows in `tt_player_parents` retain access through the pivot.
- [ ] `InviteButton` renders on (a) frontend team roster row, (b) wp-admin player edit form, (c) wp-admin people edit form, with WhatsApp share + copy-link + email opt-in.
- [ ] Auto-prompt at player-add time fires only outside CSV import flow; CSV-import batch surfaces a single "Generate invites for N players?" action after import.
- [ ] Acceptance URL `?tt_view=accept-invite&token=<32-char>` validates the token, shows the role-specific assignment form, creates the WP user with the right role, and runs the correct linking step.
- [ ] Logged-in visitor with matching email gets the silent-link path; mismatched email gets the sign-out interstitial; logged-out gets the acceptance form.
- [ ] Six default invite messages ship in `tt_config` (3 roles × 2 locales) and are editable via Configuration → Invitations with `{url}` validation.
- [ ] Locale resolution follows the precedence: target row's locale → club default → inviter's WP locale.
- [ ] Audit log records every invitation event (created / accepted / revoked / expired) with actor + entity.
- [ ] Rate limit is 50 invitations per admin per 24 hours; soft cap with a filter override + "Continue anyway" button that records the override reason in the audit log.
- [ ] `tt_invitation_accepted` action fires after successful linking; no workflow template subscribes to it in v1 (left as a v1.5 hook).
- [ ] Token format is 32-char URL-safe (24 bytes of entropy, base64url-encoded).
- [ ] `tt_players.parent_user_id` continues to reflect `is_primary = 1` from `tt_player_parents` so #0022's `PlayerOrParentResolver` keeps working unchanged.

## Notes

### Decisions locked during shaping

11 decisions from the original idea (April 2026) plus 8 architectural calls from the spec session (April 2026):

1. **Trigger model** — roster/assignment-event-driven; share button surfaces auto-generated invite. Auto-prompt at player-add time, suppressed during CSV import via session flag.
2. **Parent role** — new `tt_parent` WP role.
3. **Channel mix** — WhatsApp + copy-link primary; email opt-in (default off).
4. **Existing-account handling** — silent link if logged in + email matches; sign-out interstitial otherwise.
5. **Cap interaction** — ungated in v1; #0011 wires the gate later.
6. **Message text** — editable per club, default per role, NL + EN out of the box, 6 templates × placeholder validation.
7. **Token lifetime** — 14 days, single-use, configurable.
8. **Privacy posture pre-accept** — name + role + club only; no surrounding data.
9. **Audit + revocation** — every event logged, admin list page with revoke buttons.
10. **Rate limit** — 50/day soft cap, filter override + "Continue anyway" with audit.
11. **Email collected at acceptance** — first field on the acceptance form is recovery email.
12. **Acceptance URL format** — frontend route `?tt_view=accept-invite` on the dashboard shortcode (not wp-login.php, not a custom page).
13. **Parent ↔ player relationship** — new pivot `tt_player_parents`; `tt_players.parent_user_id` becomes a derived "primary parent" shortcut.
14. **Workflow integration** — fire `tt_invitation_accepted` action; no template subscribes in v1.
15. **Share-invite button location** — both frontend roster + wp-admin player/people edit forms.
16. **Bulk-import auto-prompt** — suppressed during CSV import; replaced by post-import batch action.
17. **Soft-cap override** — filter `tt_invitation_daily_cap` + "Continue anyway" button.
18. **Locale precedence** — target row's locale → club default → inviter's WP locale.
19. **Token format** — 32-char URL-safe (~192 bits entropy, fits cleanly in WhatsApp).

### Cross-epic interactions

- **#0011** — license caps; v1 ungated, gates wired in #0011's feature-audit sweep.
- **#0017** — trial player module needs temporary accounts; the invitation flow is the substrate.
- **#0022 Phase 1** — fires `tt_invitation_accepted` for future workflow subscriptions.
- **#0019 Sprint 5** — Configuration → Invitations is a new tab on the existing frontend admin Configuration page.
- **#0029** — the new docs file gets an `<!-- audience: admin -->` marker.
- **#0021** — when audit log viewer ships, the invitation events surface there automatically (we write to the same audit-log primitives).

### Sequence position

Phase 1 follow-on. Independent of #0011 / #0027 / the rest of the Ready queue. Pairs naturally with #0017 (trial player module) since trialists need account creation.

### Estimated effort

**v1 (this spec):** ~8-12h actual, single-PR build (compression pattern from v3.22.0 holds for this kind of well-scoped feature work).

- Schema + migrations (`tt_invitations` + `tt_player_parents` + parent_user_id projection): 1.5h
- Token generation + validation: 0.5h
- Acceptance route + form: 2h
- Linking logic per role variant (player / parent / staff) + new `tt_parent` WP role: 2h
- `InviteButton` component (3 surfaces): 1.5h
- Configuration → Invitations admin page (list + message editors): 1.5h
- Audit + rate limit + filter override + locale precedence: 1h
- 6 default WhatsApp message texts + nl_NL.po + docs: 1h
- Testing across all three role variants + the silent-link / sign-out / logged-out paths: 1h

**v1.5 (deferred):**
- Welcome workflow template subscribing to `tt_invitation_accepted` (~3h, lands inside #0022 Phase 2).
- Cap-aware rejection per #0011 (~1h within #0011's feature-audit sweep).
