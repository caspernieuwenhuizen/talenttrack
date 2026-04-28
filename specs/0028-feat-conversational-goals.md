<!-- type: feat -->

# #0028 — Goals as a conversational thread (coach + player + parent dialogue)

## Problem

Today a goal is a single record: title, description, status, due date. The data model treats it as a static checklist item. In reality, a goal is a *conversation* between coach, player, and (often) parent: the coach proposes a focus area, the player reflects on progress, the parent asks a clarifying question, the coach revises the target. Today none of that lives in the plugin — it happens via WhatsApp, email, or hallway chats, and the goal record stays a stale title.

Who feels it: head coach (loses the conversation history that gives a goal its meaning), player (no surface to reflect, no acknowledgement that anyone read), parent (asks "what's my kid actually working on?" and the goal title alone doesn't answer it), HoD (review reads as bare data instead of context).

The original idea expected #0022 (Workflow & Tasks Engine) to ship a generic thread primitive that goals could consume. #0022 went a different direction (tasks, dispatchers, event log — but no in-app threading), so this spec **builds the primitive itself** as part of the feature, designed polymorphically so #0017 trial decisions, #0014 scout reports, and #0044 PDP conversations can adopt it later.

## Proposal

Three pieces, shipped together as one PR:

1. **A polymorphic thread primitive** — `tt_thread_messages` + `tt_thread_reads` keyed on `(thread_type, thread_id)`, plus a `Threads` module with repository + REST + a small renderable component. v1 only registers `goal` as a `thread_type`; future modules add their own.
2. **Goal-thread consumption** — the goal detail view gets a thread tab below the existing fields. Comments + system messages (status changes) interleave chronologically. Status changes auto-write a system-author message.
3. **Notifications** — every new message pings every other thread participant via the workflow engine's existing `EmailDispatcher` for v1; switches to `PushDispatcher` automatically when #0042 ships and a participant has an active push subscription.

Visibility has two levels (`public` / `private_to_coach`). Edits allowed within 5 minutes of post; after that, soft-delete only. Soft-deletes leave an audit-log row.

## Scope

### Schema — migration `0038_thread_messages.php`

```sql
CREATE TABLE {prefix}tt_thread_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    uuid CHAR(36) DEFAULT NULL,
    thread_type VARCHAR(32) NOT NULL,           -- 'goal' for v1; 'trial_case' / 'scout_report' / 'pdp_conversation' later
    thread_id BIGINT UNSIGNED NOT NULL,         -- FK to the entity (interpreted by thread_type)
    author_user_id BIGINT UNSIGNED NOT NULL,    -- 0 = system message
    body LONGTEXT NOT NULL,
    visibility VARCHAR(24) NOT NULL DEFAULT 'public',  -- 'public' | 'private_to_coach'
    is_system TINYINT(1) NOT NULL DEFAULT 0,    -- 1 = status-change / "goal created" / etc.
    edited_at DATETIME DEFAULT NULL,            -- last successful edit (within the 5min window)
    deleted_at DATETIME DEFAULT NULL,           -- soft-delete tombstone
    deleted_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_thread (thread_type, thread_id, created_at),
    KEY idx_author (author_user_id),
    UNIQUE KEY uk_uuid (uuid)
);

CREATE TABLE {prefix}tt_thread_reads (
    user_id BIGINT UNSIGNED NOT NULL,
    thread_type VARCHAR(32) NOT NULL,
    thread_id BIGINT UNSIGNED NOT NULL,
    last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, thread_type, thread_id)
);
```

Both tables include `club_id` per the SaaS-readiness scaffold. `tt_thread_messages` has a `uuid` column on the root entity per `CLAUDE.md` § 3.

### Module — `src/Modules/Threads/`

- **`ThreadsModule`** — registers REST + caps + thread-type registry.
- **`ThreadTypeRegistry`** — `register( string $type, ThreadTypeAdapter $adapter )`. Other modules call this on boot to register their entity. v1 wires `'goal'` from inside this module (Goals can't be modified to depend on Threads bootstrap-wise; a small adapter class lives here for v1 and migrates to `GoalsModule` if/when convenient).
- **`ThreadTypeAdapter`** interface:
  ```php
  interface ThreadTypeAdapter {
      public function findEntity( int $thread_id ): ?object;
      public function participantUserIds( int $thread_id ): array;     // who pings on a new message
      public function canRead( int $user_id, int $thread_id ): bool;
      public function canPost( int $user_id, int $thread_id ): bool;
      public function entityLabel( int $thread_id ): string;            // for notifications + audit
  }
  ```
- **`ThreadMessagesRepository`** — CRUD with the 5-minute edit window enforced server-side, soft-delete, visibility filter, audit-log writes on every state change.
- **`ThreadReadsRepository`** — `markRead( int $user_id, string $thread_type, int $thread_id )`, `unreadCount( $user_id, $thread_type, $thread_id )`.

### Goal adapter — `GoalThreadAdapter`

Resolves participants for `thread_type='goal'`:

- The goal's player.
- The coach who owns the goal (`goals.created_by` if it's a coach; otherwise the head coach of the player's team via the existing `coach_owns_player` resolver).
- Linked parent users for that player (`tt_player_parents`).
- Plus anyone with `tt_view_settings` (admins / HoD) — they don't auto-receive notifications but can read the thread.

`canPost` = `canRead`. `private_to_coach` messages are filtered out for player + parent on `canRead`; coach + admin always see them.

### REST — `talenttrack/v1`

- `GET /threads/{type}/{id}` — list messages for a thread (cap-gated via the adapter's `canRead`). Returns `[ messages: [], unread_since: <timestamp>, participants: [] ]`. Updates `tt_thread_reads.last_read_at` for the requesting user as a side effect.
- `POST /threads/{type}/{id}/messages` — post a message. Body: `{ body, visibility? }`. Cap-gated via `canPost`.
- `PUT /threads/{type}/{id}/messages/{msg_id}` — edit, allowed only when `now - created_at < 300s` AND `author_user_id == requester`.
- `DELETE /threads/{type}/{id}/messages/{msg_id}` — soft-delete. Allowed for the author at any time, or for an admin (`tt_view_settings`) at any time.
- `POST /threads/{type}/{id}/read` — explicit read-marker for cases where the GET pattern doesn't fit (mobile background fetch, etc.).

Every endpoint declares `permission_callback` against the adapter. No role-string compares.

### Frontend — `FrontendThreadView` component

Stateless render component callable from any view:

```php
FrontendThreadView::render( 'goal', $goal_id, $current_user_id );
```

Renders a chat-style scroll with sticky compose bar at bottom. Mobile-first (`CLAUDE.md` § 2):

- Single column at 360px; messages right-aligned for the requesting user, left-aligned for everyone else.
- Compose bar: `<textarea>` with `font-size: 16px` (no iOS auto-zoom), 48px-tall send button, `inputmode="text"`.
- System messages render as a dim center-aligned strip ("Coach Maria moved this goal to In Progress — 12 mins ago").
- `private_to_coach` messages render with a subtle "private" pill so the coach knows what the player + parent can't see.
- Per-message actions (edit / delete) in a small "more" menu, gated client-side AND server-side.
- "Unread since [time]" divider when scrolling into messages newer than `tt_thread_reads.last_read_at`.

CSS lives in `assets/css/frontend-threads.css` enqueued only when the component renders.

JS is vanilla (`assets/js/tt-threads.js`):

- POSTs new messages via REST (X-WP-Nonce).
- Polls `GET /threads/.../messages?since=<last_id>` every 30s while the page is in foreground (uses `document.visibilityState` to pause).
- No live websocket / SSE for v1 — polling at 30s is fine for goal-pace conversations.

### Goal detail view integration

`FrontendGoalsManageView` (and `FrontendMyGoalsView` for the player surface) gets a "Conversation" section on the goal detail render:

```php
echo '<section class="tt-goal-conversation">';
echo '<h2>' . esc_html__( 'Conversation', 'talenttrack' ) . '</h2>';
FrontendThreadView::render( 'goal', (int) $goal->id, get_current_user_id() );
echo '</section>';
```

The goal list view shows an unread-count badge per row (`ThreadReadsRepository::unreadCount`). Cheap query, batched in the list query.

### System messages on status change

`GoalsRepository::updateStatus()` (or wherever status transitions happen — the existing PDP module already writes `agreed_actions` to a goal's `tt_goal_links` row when a conversation is conducted; same pattern) gains a hook that writes a `is_system=1` message to the thread:

- Pending → In Progress: "{coach name} moved this goal to In Progress."
- In Progress → Completed: "{coach name} marked this goal as completed."
- Any → Archived: "{coach name} archived this goal."
- Title or due-date edits: "{coach name} updated this goal: title was '...', is now '...'."

`author_user_id` on system messages is the user who triggered the change; `is_system=1` is the visual differentiator.

### Notification fan-out

Every non-system message creation writes one workflow event (`thread_message_posted`) carrying `(thread_type, thread_id, message_id, author_user_id, visibility)`. The dispatcher chain on the corresponding workflow template fans out to participants:

- v1: `EmailDispatcher` for participants with email; participants without email (U8-U10 player on the parent-only path) skip.
- When #0042 ships: chain becomes `[ Push, Email ]`; participants with active push subscriptions get push, others get email.

The `EmailDispatcher` payload is short:

```
Subject: New message on Marcus's goal: "Improve first-touch under pressure"
Body: Coach Maria wrote: "Nice work in training Saturday — let's go again next week."
View: <link to the goal detail>
```

Author is excluded from the fan-out (don't notify yourself). `private_to_coach` messages only fan out to the coach + admins, not player + parent.

Workflow template lives in `src/Modules/Threads/Workflow/ThreadMessagePostedTemplate.php` so admins can disable / re-route it via the existing workflow config UI (#0022).

### Capabilities

No new caps. Authorization runs through the adapter's `canRead` / `canPost`, which compose existing caps:

- Coach: owns goals for players on their teams (`coach_owns_player`).
- Player: own goals (`tt_view_own_goals` exists from earlier #0019 work).
- Parent: linked-player goals (`tt_player_parents` join).
- Admin / HoD: all goals (`tt_view_goals` cap).

`private_to_coach` adds a single rule on top: only `is_coach_for_player` OR `tt_view_settings` user can see those messages.

### Edit + delete rules (locked)

- **Edit window**: 5 minutes from `created_at`, author only. After 5 minutes, edits are rejected at REST with 403 + a clear message ("Edit window expired"). Soft-delete is the only mutation.
- **Soft-delete**: at any time by the author; at any time by an admin with `tt_view_settings`. The message row stays in the table; `body` is replaced with `__('Message deleted.', 'talenttrack')` for display, the original body is wiped. `deleted_by` + `deleted_at` set. An audit-log row records who deleted what (with the deleted body in the audit row's payload — admins can recover via the audit log if needed).
- **No hard delete** in v1. GDPR erasure goes through the existing #0011 retention path.

### Audit log integration

`#0021` audit log gains four event types:

- `thread_message_posted`
- `thread_message_edited`
- `thread_message_deleted`
- `thread_visibility_changed` (rare — author changes a public message to private during the edit window)

Author + thread reference + message id stored. Body is NOT stored in audit on post/edit (too noisy); body IS stored on delete (so the original is recoverable).

## Out of scope

- **File / image attachments.** Photo + audio are coming via #0016 (photo-to-session) and don't slot here cleanly. Add later if demand emerges; the schema's `body LONGTEXT` is enough for v1.
- **Reactions / emoji.** Slack-style reaction bar is over-engineering for goal-pace conversations.
- **@-mentions** with autocomplete. Notifications already fan out to all participants; mentions add UI complexity without meaningful pedagogical value at v1.
- **Distinct "weekly reflection" format** for the player. Just a normal comment for v1; future workflow templates can prompt the player to post one.
- **Live updates** via websocket / Server-Sent Events. 30s polling while page is foregrounded is fine.
- **Multi-thread surfaces.** v1 ships only `thread_type='goal'`. The primitive supports `trial_case` / `scout_report` / `pdp_conversation`, but those are separate follow-up PRs.
- **Acknowledge button** for parents (PDP-style `parent_ack_at`). A "Thanks!" comment is enough.
- **3+ visibility levels.** Only `public` and `private_to_coach`. No "parent-only-to-coach" or other granular tiers.
- **Editing past 5 minutes.** Soft-delete + repost is the workflow.
- **Hard delete in v1.** GDPR erasure path stays via #0011.
- **REST namespace bump.** Adding to `talenttrack/v1`; the additions are additive.

## Acceptance criteria

### Threading primitive

- [ ] `tt_thread_messages` + `tt_thread_reads` tables created via migration `0038_thread_messages.php`.
- [ ] Migration runs cleanly on fresh install and on existing installs with goals.
- [ ] `ThreadTypeAdapter` interface exists with `findEntity / participantUserIds / canRead / canPost / entityLabel`.
- [ ] `GoalThreadAdapter` implements all five methods correctly across coach / player / parent / admin paths.
- [ ] REST endpoints exist for list / post / edit / delete / read with capability gates routed through the adapter.

### Posting + reading

- [ ] A coach who owns a goal can post a public comment; the player + linked parent see it.
- [ ] A coach can post a `private_to_coach` comment; the player + parent do NOT see it; HoD / admin DO see it.
- [ ] A player can post a comment on their own goal; coach + parent see it.
- [ ] A linked parent can post a comment on their child's goal; coach + player see it.
- [ ] An unrelated coach (not owning the goal's player) cannot read or post.

### System messages

- [ ] Status changes (Pending ↔ In Progress ↔ Completed ↔ Archived) write a `is_system=1` message to the thread.
- [ ] Title or due-date edits write a system message naming the change.
- [ ] System messages render with a distinct visual style.

### Edit + delete

- [ ] Author can edit their own message within 300 seconds of `created_at`.
- [ ] Editing past 300s returns 403 with an explanatory message.
- [ ] Author can soft-delete their own message at any time.
- [ ] Admin (`tt_view_settings`) can soft-delete any message.
- [ ] Soft-deleted messages render as "Message deleted." in the feed but stay in the table.
- [ ] Audit-log entries record post / edit / delete / visibility-change events.

### Read status

- [ ] `GET /threads/{type}/{id}` updates `tt_thread_reads.last_read_at` for the requesting user.
- [ ] Goal list view shows an unread-count badge per row when `unreadCount > 0`.
- [ ] "Unread since [time]" divider appears in the thread when scrolling into newer messages.

### Notifications

- [ ] Posting a new public message fires `thread_message_posted` workflow event.
- [ ] EmailDispatcher fans out to all participants except the author.
- [ ] `private_to_coach` messages fan out only to coaches + admins.
- [ ] Author is never notified about their own message.
- [ ] When #0042 ships and a participant has an active push subscription, push is preferred over email.

### Mobile

- [ ] Thread renders at 360px with single-column layout.
- [ ] Compose textarea is `font-size: 16px` (no iOS auto-zoom).
- [ ] Send button is ≥ 48px tall.
- [ ] Polling pauses when `document.visibilityState !== 'visible'`.

### No regression

- [ ] Goal create / edit / delete flows unchanged for users not opening the conversation section.
- [ ] Existing `tt_goals` rows (without any messages) render correctly with an empty-state notice.
- [ ] No behavior change in PDP, Trials, or Scout report flows in v1 (they don't use the thread primitive yet).

## Notes

### Sizing

| Slice | Estimate |
| - | - |
| Schema migration + repositories | ~2-3h |
| `ThreadTypeAdapter` + `GoalThreadAdapter` | ~1.5h |
| REST endpoints + permission wiring | ~3h |
| `FrontendThreadView` component (HTML + CSS + vanilla JS polling) | ~5-7h |
| System-message hook on goal status changes | ~1h |
| Notification template + dispatcher fan-out | ~2h |
| Audit-log integration | ~1h |
| Goal list unread badge | ~1h |
| Translations + docs (`docs/conversational-goals.md` + nl_NL counterpart) | ~2h |
| Testing across coach / player / parent / admin permission cells + visibility filter | ~2-3h |
| **Total v1** | **~20-25h** as a single epic, single PR |

The original idea estimated 20-30h; this lands at the lower end because the polymorphic-from-day-one decision pays off: no rework when other modules adopt the primitive.

### Hard decisions locked during shaping

1. **Polymorphic from day one** — `tt_thread_messages` keyed on `(thread_type, thread_id)`. v1 only consumes `'goal'`; #0017 / #0014 / #0044 are explicit follow-up consumers.
2. **Two visibility levels** — `public` (default) and `private_to_coach`. No 3+ levels.
3. **5-minute edit window**, then soft-delete only. No immutable; no free editing.
4. **System messages on status changes** — auto-written, `is_system=1`, distinct style.
5. **Notifications fan out to all participants except author** — `EmailDispatcher` for v1, `PushDispatcher` when #0042 ships.
6. **Read status via `tt_thread_reads`** — composite primary key, side-effect of the GET, not a separate POST in v1.
7. **No file/image attachments in v1**.
8. **No reactions, no @-mentions, no live updates** (30s polling).
9. **Goals adapter only in v1** — other consumers later.

### Cross-references

- **#0017** Trial player module — `trial_case` thread type can adopt this primitive in a follow-up PR (HoD + assigned staff threading on a case).
- **#0014** Scout reports — `scout_report` thread type can adopt for "scout asked a clarifying question" workflows.
- **#0021** Audit log — four new event types added.
- **#0022** Workflow & tasks engine — `EmailDispatcher` consumed; `thread_message_posted` template registered through the existing workflow registry.
- **#0042** Youth-aware contact strategy — `PushDispatcher` becomes the primary fan-out when a participant has a push subscription.
- **#0044** PDP cycle — `pdp_conversation` thread type can replace the existing freeform-text approach in a follow-up PR.

### Things to verify in the first 30 minutes of build

- The "5-minute edit window" interacts cleanly with the audit-log payload — confirm we record the *edited* body, not the original, on subsequent edits within the window.
- 30-second polling doesn't blow up REST request rates in clubs with many open goal pages — measure under demo load.
- Mobile WhatsApp-style chat scroll vs. Slack-style top-down — pick one and stick with it. Recommend top-down (newest at bottom, scroll up for history) since it matches WhatsApp + iMessage conventions.
- iOS Safari `<textarea>` autosize — implement a tiny pure-JS autosize or accept fixed 3-row height. Recommend fixed for v1.
