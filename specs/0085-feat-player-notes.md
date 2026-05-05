<!-- type: feat -->

# #0085 — Notes and conversations on player profiles

## Problem

In a small academy with a tight leadership community — typically 3-6 staff who all touch player development decisions — coaches, scouts, head of development, and team managers all want to leave structured observations on a player. Today there's nowhere to do that.

What exists today: structured observation surfaces — evaluations (formal, periodic, scored), trial-decision letters (formal, single-event), PDP conversations (structured cycle with parent/player ack), scout reports (formal, narrative documents). All four are heavyweight by design. None of them fit the everyday "I noticed something at training tonight, the head coach should know" use case.

What's missing: a lightweight running log on each player profile where staff can write short observations, others can read them, the academy builds shared context over time, and nothing ceremonial is required. Three concrete examples surfaced in the pilot meeting:

- "Lucas was unusually quiet at practice tonight, parents are going through a divorce" — head coach wants the team manager and HoD to know without writing a formal evaluation.
- "Talked to Eve's parents at the tournament — they're considering a club switch in summer" — scout wants the HoD to be aware so retention strategy can adjust.
- "Try Jonas as a winger next match, he's outgrowing the defensive midfielder role" — assistant coach wants the head coach to see this before they pick the lineup.

These are not chats between people; they're observations about a player that other staff should see. The anchor is the player, not the conversation participants. Calling it "chat" or "messages" would be the wrong primitive; calling it "notes" gets the framing right.

A structural lucky strike: TalentTrack already has a generic threads infrastructure. The `Threads` module ships `tt_thread_messages` (UUID + thread_type + thread_id + author + body + visibility + edited_at + deleted_at) and `tt_thread_reads` (per-user last-read tracking) tables. A `ThreadTypeRegistry` accepts adapters that decide who can read, who can write, and what the thread's anchor entity is. Today only one thread type is registered — `goal` — wired into `ThreadsModule::boot()` via `ThreadTypeRegistry::register('goal', new GoalThreadAdapter())`. Adding a `player` thread type is the entire feature.

This is therefore a small feat, not an epic. One thread type adapter, one frontend component on the player profile, one new tab. The spec below is short on purpose.

## Proposal

Register a new `player` thread type with the existing `Threads` module. Render a "Notes" tab on the player profile that lists the thread and accepts new entries. Authorize via the existing matrix entity pattern. Reuse the existing message edit/delete/visibility infrastructure unchanged.

### Scope

**1. The thread type adapter.** New class `Modules\Threads\Adapters\PlayerThreadAdapter` implementing `ThreadTypeAdapter`. Returns:

- **Read scope:** any user with `tt_view_player_notes` capability AND scope-row grant on the player's club / team. The matrix gate (existing) does the per-team scoping; the adapter just declares the cap.
- **Write scope:** same, but `tt_edit_player_notes`. Parents and players themselves cannot read or write notes by default — this is staff-only by design (see "Why staff-only by default" below).
- **Anchor entity resolution:** `thread_id` is the `tt_players.id`. Adapter validates the player exists and is in the user's club.
- **Display label:** "Notes — {player name}" for breadcrumb / page title use.

**2. New matrix entity `player_notes`.** Seeded for assistant_coach / head_coach / team_manager (`r[team]` + `c[team]` — they can read and write on their team's players), head_of_development / academy_admin (`rcd[global]`), scout (`rc[global]` — scouts can write notes about any player, since they're often observing across teams). Player and parent: no grant.

The seed extends `config/authorization_seed.php`. Existing installs receive a top-up migration following the precedent `0063_authorization_seed_topup_0079.php`. Idempotent.

**3. New caps.** `tt_view_player_notes` (R) and `tt_edit_player_notes` (C/D — edit your own, delete your own; HoD+admin can delete anyone's per matrix). `LegacyCapMapper` bridges both. The existing `tt_thread_message_edit_own` and `tt_thread_message_delete_own` caps continue to govern row-level edit/delete (you can edit your own message; HoD+admin can soft-delete anyone's via the matrix).

**4. Frontend rendering on the player profile.** A new "Notes" tab on the existing player file (player file UX redesigned in #0082; the tab slot is already there as a six-tab page). The tab content:

- A reverse-chronological list of notes, newest first. Each entry shows: author name (with link to person detail), timestamp ("2 hours ago" / "Yesterday at 14:23" / dated for older), body text, optional "edited" indicator if `edited_at` is set.
- Author can edit their own note in-place (existing `tt_thread_message_edit_own` flow); HoD/admin can soft-delete any note (existing `tt_thread_message_delete_own` flow with the matrix grant).
- Below the list, a textarea for adding a new note. Plus a small "@-mention staff member" affordance — autocompletes against the people directory, scoped to the user's accessible people. Mentioning a person fires a workflow task on completion, routed to the mentioned person via the existing Workflow engine's `RoleAssigneeResolver`.
- Tab count badge using the existing `PlayerFileCounts` infrastructure shipped via #0082 — note count > 0 shows a number next to the tab label.

**5. The @-mention to workflow task.** When a note's body contains `@person-key`, on save the system parses mentions, validates each mentioned user has access to the player, and dispatches a `task` via `TaskEngine::dispatch('player_note_mention', ...)`. The mentioned user gets a task on their persona dashboard's "your tasks" widget pointing at the player profile's Notes tab. Reuses the existing notification chain — no new notification infrastructure.

A new `TaskTemplate` class `PlayerNoteMentionTemplate` registers with the existing `TemplateRegistry`. Default deadline: 7 days from spawn. Completes when the user views the player's Notes tab (auto-completion via the existing `tt_thread_reads` table — viewing the tab updates the read pointer). No explicit "mark as read" action needed.

**6. REST endpoints.** Reuses the existing `ThreadsRestController` (which is generic over thread type). The frontend calls:

- `GET /talenttrack/v1/threads/player/{player_id}` — list notes, paginated.
- `POST /talenttrack/v1/threads/player/{player_id}` — create note.
- `PATCH /talenttrack/v1/thread-messages/{message_id}` — edit own note.
- `DELETE /talenttrack/v1/thread-messages/{message_id}` — soft-delete (own or HoD/admin).
- `POST /talenttrack/v1/threads/player/{player_id}/read` — mark read (called when the tab opens).

No new routes; the existing controller dispatches by thread type.

**7. Search and discovery.** Note bodies are indexed in the existing global search (search-across-everything is shipped via the existing `Stats` module's player comparison search) only when `tt_view_player_notes` resolves true for the searcher. A note containing "divorce" appears in search results for the HoD but not for the team manager who lacks scope on that team. Default behaviour of the matrix gate, not new logic.

**8. GDPR.** Notes contain personal data about minors. They're explicitly listed in the player's data-erasure manifest (when the player offboarding GDPR work happens — currently parked, not yet specced). For now, on player soft-delete (`archived_at`), the player's notes are also soft-archived (visibility set to `archived`, hidden from default queries but retained for compliance). Hard delete via the future GDPR erasure flow.

**9. Visibility levels.** The `tt_thread_messages.visibility` column already exists with values `public`, `staff_only`, `internal`. For the player thread type:

- **`staff_only`** is the default visibility for new notes. Visible to staff with read scope. This is what the pilot academy described.
- **`internal`** is more restrictive — only the author and HoD/admin. For very sensitive notes (safeguarding flags, parental issues that haven't been formalised yet).
- **`public`** is *not* used for player notes — would expose to parents/players, which is explicitly out of scope for this feature.

The note-creation form has a small dropdown next to the submit button: "Visible to: [Staff with team access ▼ / HoD only]". Default is staff. Defaults can be overridden per-club via a configuration option.

## Why staff-only by default

The pilot academy specifically asked for a place where staff can be honest about what they're observing without the speaker filter that comes with parent/player visibility. A coach needs to be able to write "Lucas was unusually quiet, parents are going through a divorce" — that observation is genuinely useful for staff coordination but harmful if surfaced to the parent or to Lucas himself.

If parent/player communication becomes a genuine product need later, that's a separate feature with its own surface (parent-staff messaging, possibly built on the same Threads infrastructure but a different thread type with different visibility rules). Mixing the two — having one notes feature that sometimes parents see — would force coaches into self-censorship from day one, which defeats the feature's purpose.

This is the same architectural decision the Threads module already encodes via `visibility` levels. We're using the existing infrastructure for what it was designed for.

## Out of scope

- **Parent/player participation in notes.** As above. Future feature, not v1.
- **Direct messaging between staff.** "Coach A messages Coach B" outside of any player context. Different anchor (people, not players); different feature. Could use Threads infrastructure with a `staff_dm` thread type later.
- **Rich text editing.** Body is plain text with @-mentions. No bold/italic/lists/images. The existing `Threads` infrastructure stores plain text; rich-text additions are a Threads-module-wide future concern.
- **File attachments on notes.** Same — Threads-module-wide concern. Today notes are text-only.
- **Email/push notifications on every note.** @-mentions fire workflow tasks (visible on persona dashboard); they don't email or push by default. If a club wants email notifications, that's a Communication module (#0066) integration once that ships — not in this spec.
- **Note threading / replies.** A note doesn't have a parent_message_id — it's flat. Replies happen as new notes that reference each other in body text. Threading is a future enhancement if there's demand.
- **Cross-team / cross-club shared note pools.** A scout's note about a prospect at another club's tournament is about that prospect; if the prospect joins, the note travels with them via the prospect-to-player promotion flow (#0081). Notes don't get shared across academies.
- **Bulk import of historical notes from external systems.** The pilot academy's existing notes (in a shared Google Doc or WhatsApp) are not migrated. Manual copy-paste only.

## Acceptance criteria

- New `Modules\Threads\Adapters\PlayerThreadAdapter` registers with `ThreadTypeRegistry::register('player', ...)` at boot.
- Two new caps `tt_view_player_notes` and `tt_edit_player_notes` exist; bridged via `LegacyCapMapper`.
- New matrix entity `player_notes` seeded with the documented persona scoping. Top-up migration backfills existing installs.
- Player profile gains a "Notes" tab between existing tabs. Tab count badge shows non-zero note count. Active tab shows reverse-chronological list, edit-own / delete-own controls per matrix.
- Note creation form supports plain-text body, @-mention autocomplete, visibility dropdown (staff_only / internal). Default visibility is staff_only.
- @-mentions in note body parse on save, dispatch a `PlayerNoteMentionTemplate` workflow task to the mentioned user. Auto-completes when mentioned user views the player's Notes tab.
- REST endpoints inherited from existing `ThreadsRestController` work without changes — no new routes.
- Note bodies appear in global search only for users with read scope.
- On player `archived_at` soft-delete, the player's notes' visibility flips to `archived` and they're hidden from default queries.

## Notes

**Documentation updates.**
- `docs/player-notes.md` (new, EN + NL) — operator guide for the feature. How notes work, visibility levels, @-mentions, who sees what.
- `docs/access-control.md` — note the new `player_notes` matrix entity and the two new caps.
- `docs/modules.md` — extend the Threads module entry: "second registered thread type beyond `goal`."
- `languages/talenttrack-nl_NL.po` — UI strings ("Notes" / "Notitie toevoegen" / mention autocomplete strings, etc.).
- `SEQUENCE.md` — append `#0085-feat-player-notes.md` to Ready.

**`CLAUDE.md` updates.**
- §3 (data model) — note: "Generic threads infrastructure (`tt_thread_messages` + `ThreadTypeRegistry`) is the right place to add new conversational surfaces. Don't introduce a new table for each conversation feature."

**Effort estimate at conventional throughput.**
- Adapter + cap + matrix seed + top-up migration: ~250 LOC
- Frontend tab on player profile + create form + @-mention autocomplete: ~400 LOC
- Workflow task template + auto-completion via thread reads: ~150 LOC
- Tests: ~200 LOC
- Docs + translations: ~150 LOC

Total at conventional rates: ~1,150 LOC. **Applying the codebase's documented ~1/2.5 estimate-to-actual ratio: realistic actual ~450 LOC**, ~6-9 hours in one PR.

This is small enough to ship in one PR rather than a multi-child epic. Doing so avoids the merge-train pain that happens with multiple sequential PRs against a single module.
