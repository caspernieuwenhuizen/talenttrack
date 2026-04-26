<!-- type: feat -->

# Invitation flow — invite players, parents, and staff to join TalentTrack via shareable link

Origin: 26 April 2026 conversation. Onboarding the people behind a player is a real friction point today: an admin manually creates a WP user, manually links it to a `tt_players` row (or a `tt_people` row), manually emails credentials. For 50 players × 1-2 parents each plus a coach + assistant, that's hundreds of manual steps per season. A self-serve invitation flow turns that into "tap a button → share the link in WhatsApp → person follows the link, picks a password, lands in their own dashboard."

## What this is for

A token-based invitation that:

1. Generates a one-time signed URL bound to a specific role + entity link.
2. Is **shareable via WhatsApp** (the most common channel for Dutch youth-football clubs — coaches and parents already coordinate via WhatsApp groups).
3. On follow-through: collects only what's needed (name + password — sometimes name is already filled because we know it from the existing `tt_players` row), creates the WP user, links to the right entity, and lands the user on their dashboard.

Three role variants — same flow shape, three different "linking" decisions on accept:

- **Player invite**: target is an existing `tt_players` row. Accepting creates a WP user with the `tt_player` role and writes its ID to `tt_players.wp_user_id`.
- **Parent invite**: target is an existing `tt_players` row, but the relationship is "parent of". Accepting creates a WP user (probably `tt_player` role for v1 — the dedicated `tt_parent` role is flagged in #0014's open questions) and writes a `parent_of` link in either a new pivot table or existing relationship structure.
- **Staff invite**: target is an existing `tt_people` row + optionally a team + optionally a functional role. Accepting creates a WP user with the appropriate role (`tt_coach`, `tt_staff`, etc.) per the functional role mapping, writes `tt_people.wp_user_id`, and applies the functional-role assignment.

## Why this matters

- **Removes the worst onboarding step.** Today the admin's choice is: (a) create everyone manually one by one, or (b) skip the WP-account part and only use TalentTrack as a coach-side tool, losing the player/parent dashboard surface entirely. Most clubs pick (b) — meaning the player-facing features go unused.
- **WhatsApp-native.** Dutch youth football coordinates via WhatsApp groups by default. A "share via WhatsApp" button (`wa.me/?text=<encoded>`) drops the link straight into the right group chat without copy-paste mistakes.
- **Foundation for #0017** (trial player module). Trialists need a temporary account; the invitation flow is exactly what creates one. #0017's "intake" step becomes "send a trial invite".
- **Foundation for #0011** (monetization). License caps live at the player/team level; invitations need to respect them ("you've hit the free-tier cap of 15 players — upgrade or revoke an existing account first"). v1 skips this gate — wires the cap-check in #0011's feature-audit sweep.

## Working assumption (verify during shaping)

A new `tt_invitations` table:

```
id BIGINT UNSIGNED PK
token VARCHAR(64) UNIQUE NOT NULL                      -- random URL-safe; the credential
kind ENUM('player','parent','staff') NOT NULL
target_player_id BIGINT NULL                           -- player + parent invites
target_person_id BIGINT NULL                           -- staff invites
target_team_id BIGINT NULL                             -- staff invites (optional)
target_functional_role_key VARCHAR(64) NULL            -- staff invites (optional)
prefill_first_name VARCHAR(100) NULL
prefill_last_name VARCHAR(100) NULL
prefill_email VARCHAR(255) NULL
created_by BIGINT NOT NULL                             -- WP user id
created_at DATETIME NOT NULL
expires_at DATETIME NOT NULL                           -- default created_at + 14 days
accepted_at DATETIME NULL                              -- set on acceptance
accepted_user_id BIGINT NULL                           -- set on acceptance
revoked_at DATETIME NULL
revoked_by BIGINT NULL
status ENUM('pending','accepted','expired','revoked') NOT NULL DEFAULT 'pending'
```

Acceptance URL: `<site>/wp-login.php?tt_invite=<token>` (or a dedicated `?page=tt-accept-invite` endpoint — TBD during shaping). Hits a controller that validates the token + presents a tiny registration form (only password + confirm-password, since name is prefilled from the linking row), creates the WP user, runs the linking step, marks the invitation accepted, and `wp_set_auth_cookie`s the user into their dashboard.

WhatsApp share: a button on the invitation row that opens `https://wa.me/?text=<urlencoded-localised-message-with-link>`. The localised message reads something like:

> "Hi, you've been invited to join \[club name] on TalentTrack as a coach. Open this link to set your password and get started: \[URL]. The link is valid for 14 days."

## Decisions locked during shaping (26 April 2026)

1. **Trigger model: roster/assignment-event-driven, not "click invite".** An invitation is generated automatically when a person becomes part of a team:
   - **Player** added to a team's roster → invite generated, surfaced as a "share invite" button on the roster row.
   - **Staff** assigned to a team via a Functional Role → invite generated, surfaced as a share-invite button on the assignment row.
   - **Parent** of a player on a team → trigger differs since parent isn't directly added to a team. Two surfaces: (a) auto-prompt at player-add time ("Player saved. Generate parent invite now?"), (b) manual "Invite parent" button on the player edit form.
   - The first-login experience runs an **assignment step** before dropping the user on their dashboard — content differs per role:
     - **Player**: pick jersey number, confirm photo + DOB.
     - **Parent**: confirm relationship to player, email-for-recovery.
     - **Staff**: confirm functional role + team, email-for-recovery.

2. **Parent role: introduce a dedicated `tt_parent` WP role.** Reusing `tt_player` would mean parents see the player-side dashboard surfaces (My evaluations, My goals) for themselves rather than for the player they're a parent of. The dedicated role lets the dashboard render an "I'm a parent of X" view with read access to the linked player's profile + evaluations + goals + sessions, without granting any player-self capabilities.

3. **Channel mix: WhatsApp + copy-link primary; email opt-in checkbox (default off).** Most NL clubs trust WhatsApp more than their hosting's `wp_mail`. Email channel exists for the rare admin who wants formal records.

4. **Existing-account handling: silent link if logged in and email matches; otherwise prompt for log-in then link.** No "reject" path.

5. **Cap interaction with #0011: ungated in v1; gates wired in #0011's feature-audit sprint.**

6. **WhatsApp message text: editable per-club, default per-role, NL + EN out of the box.**
   - Stored in `tt_config` as `invite_message_<role>_<locale>` (e.g. `invite_message_player_nl_NL`, `invite_message_parent_en_US`, `invite_message_staff_nl_NL`).
   - Edited via a new **Configuration → Invitations** tab (this is also where the open-invitations list lives — see #8 below).
   - Six default texts ship out of the box (3 roles × 2 locales). Placeholders: `{club}`, `{role}`, `{team}`, `{player}`, `{sender}`, `{url}`, `{ttl_days}`. Validation on save ensures `{url}` is present.

   **Default drafts** (en_US):

   - **Player**:
     > Hi! \{sender\} has invited you to join \{club\} on TalentTrack as a player in \{team\}. Set up your account here: \{url\} — the link is valid for \{ttl_days\} days.

   - **Parent**:
     > Hi! \{sender\} has invited you to follow \{player\}'s development at \{club\} on TalentTrack. Set up your account here: \{url\} — the link is valid for \{ttl_days\} days.

   - **Staff**:
     > Hi! \{sender\} has invited you to join \{club\} on TalentTrack as \{role\} for \{team\}. Set up your account here: \{url\} — the link is valid for \{ttl_days\} days.

   **Default drafts** (nl_NL):

   - **Speler**:
     > Hoi! \{sender\} heeft je uitgenodigd om bij \{club\} op TalentTrack te komen als speler in \{team\}. Stel hier je account in: \{url\} — de link is \{ttl_days\} dagen geldig.

   - **Ouder**:
     > Hoi! \{sender\} heeft je uitgenodigd om de ontwikkeling van \{player\} te volgen bij \{club\} op TalentTrack. Stel hier je account in: \{url\} — de link is \{ttl_days\} dagen geldig.

   - **Staf**:
     > Hoi! \{sender\} heeft je uitgenodigd om bij \{club\} op TalentTrack te komen als \{role\} voor \{team\}. Stel hier je account in: \{url\} — de link is \{ttl_days\} dagen geldig.

7. **Token lifetime: 14-day default, single-use, configurable per club.** New invites can be generated if the first expired.

8. **Privacy posture pre-accept: name + role + club only; no surrounding data.** Token is the credential.

9. **Audit + revocation: every event logged from day one; admin sees a list under Configuration → Invitations with revoke buttons.**

10. **Rate limiting: 50 invites per admin per day (soft cap with override).**

11. **Email collected at acceptance, not at invite-create.** The first field on the acceptance form is "Your email so we can recover your account".

## Still to validate during implementation

- Does the auto-prompt at player-add time annoy admins doing bulk imports? May need a "don't ask again this session" toggle.
- The first-login assignment step UX needs a designer's eye — three role variants of the same screen, kept simple.
- Whether the share-invite button lives on the team's roster page (mobile-friendly for coaches sending from the sideline) or only on the wp-admin player edit form.

## Out of scope (for v1)

- **SMS channel**.
- **Bulk invite from CSV upload** (the existing CSV importer plus a bulk-action button covers most needs).
- **In-app message centre** for invitees (e.g., "your invite from \[club] is waiting"). Acceptance happens via the link, period.
- **Public registration page** (the wide-open "anyone can sign up" pattern). Invitations are explicit acts by an admin — there's no self-serve sign-up.
- **Federated identity** (Google / Facebook OAuth login). WP password is the v1 mechanism; SSO is a separate concern.
- **Branded invitation emails** with logo + club colours. Plain text v1.
- **Invitation templates per role / per team**. One default message per role, club admin can edit; per-team customisation deferred.
- **Two-factor on the acceptance flow.** Token is the credential.

## Rough scope (before shaping)

New:
- `database/migrations/<NN>-add-tt-invitations.sql`
- `src/Modules/Invitations/InvitationsModule.php`
- `src/Modules/Invitations/InvitationToken.php` — generate + validate.
- `src/Modules/Invitations/InvitationService.php` — create / accept / revoke.
- `src/Modules/Invitations/Admin/InviteButton.php` — reusable button + popup for player/people edit forms.
- `src/Modules/Invitations/Admin/InvitationsListPage.php` — `Configuration → Invitations`.
- `src/Modules/Invitations/AcceptanceController.php` — handles `?tt_invite=<token>` URL: registration form, account creation, linking, redirect.
- `src/Modules/Invitations/InviteRouter.php` — wp_loaded hook that intercepts the URL and dispatches.
- `assets/js/components/invite-share.js` — WhatsApp share + copy-link UI.
- `docs/invitations.md` (and nl_NL).

Existing:
- `src/Modules/Players/Admin/PlayersPage.php` (and `FrontendPlayersManageView.php`) — add "Invite player" + "Invite parent" buttons on the player edit form.
- `src/Modules/People/Admin/PeoplePage.php` — add "Send staff invite" button on the people edit form.
- `src/Modules/Authorization/` — possibly add a new `tt_parent` WP role + capabilities, or note in the spec that we reuse `tt_player` for v1.
- `languages/talenttrack-nl_NL.po` — invitation strings (curated, since the WhatsApp message is the visible bit).
- `src/Shared/Frontend/DashboardShortcode.php` — when a freshly-invited user lands, show a one-time onboarding flash banner.

## Sequence position (proposed)

Phase 1 follow-on. Mid-priority. Independent of #0011 (cap interaction is a v1.5 add-on), #0027 methodology, and the rest of the Ready queue. Pairs naturally with #0017 (trial player module) since trialists need account creation; the invitation flow is the substrate.

## Estimated effort

- **v1** (manual button on player + people edit forms; WhatsApp + copy-link share; 14-day single-use tokens; basic acceptance form; revoke + list page): **~14-20 hours**.
  - Schema + migration: 1.5h
  - Token generation + validation: 2h
  - Acceptance controller + registration form: 3h
  - Linking logic per role variant (player / parent / staff): 3h
  - Invite button + share UI on edit forms: 2h
  - Invitations list page + revoke: 2h
  - Audit + rate limit: 1.5h
  - Docs + nl_NL.po (carefully — WhatsApp message text matters): 2h
  - Testing across all three role variants: 2h

- **v1.5** (bulk-invite action on the Players list, auto-prompt on player create, configurable TTL): **+~6h**.

- **v2** (cap-aware via #0011's gates, email channel toggle, SMS via a 3rd-party gateway as paid add-on): **+~10-15h**, blocked on #0011 + a SMS provider decision.
