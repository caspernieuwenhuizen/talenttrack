<!-- audience: admin -->

# Invitations

Onboard the people behind a player without manually creating WP accounts. Generate a one-time signed link, share it via WhatsApp (or copy + email), recipient picks a password and lands in their dashboard. Three role variants: **player**, **parent**, **staff**.

## When to send

The plugin generates an invitation **automatically** when a person joins a team:

- **Player invite** — created when a player is added to a team's roster. The "Share invite" button on the roster row shares the link.
- **Staff invite** — created when staff is assigned to a team via Functional Role. The button surfaces on the assignment row.
- **Parent invite** — there is no roster step for parents, so two surfaces:
  - Auto-prompt at player-add time (suppressed during CSV bulk imports — a single batch action surfaces after the import for the freshly-created players).
  - Manual **Invite parent** button on the player edit form, available always.

## Where to share from

| Surface | Where | Audience |
| - | - | - |
| Frontend roster row | Coach dashboard → My teams → roster | Coach sharing from their phone, sideline-friendly |
| wp-admin player edit | Players → edit | Admin doing season-start onboarding |
| wp-admin people edit | People → edit | Admin sending staff invites |

The popover shows the acceptance URL, a live preview of the message text, and three share buttons: **WhatsApp** (default — opens `wa.me/?text=...`), **Email** (opt-in — opens the recipient's mail client), **Copy link**.

## Acceptance flow

The recipient taps the link → lands on the dashboard's accept-invite route → sees a tiny form with three sections:

1. **Account** — recovery email + password (required).
2. **Role-specific assignment**:
   - Player → optional jersey number; profile already prefilled.
   - Parent → relationship label (parent / mother / father / guardian), notify-me checkbox.
   - Staff → confirmation of role + team (set by the inviter; not editable here).
3. **Submit** — the plugin creates the WP user, runs the linking step, signs them in, redirects to their dashboard.

If the recipient is **already signed in and their email matches** the invitation, the silent-link path runs: no form, just a one-click "Accept and continue" button.

## Capabilities

| Capability | Default grant |
| - | - | - |
| `tt_send_invitation` | administrator + Head of Development + Club Admin + Coach |
| `tt_revoke_invitation` | administrator + Head of Development + Club Admin |
| `tt_manage_invite_messages` | administrator + Club Admin |

A new WP role `tt_parent` is added with `read` + `tt_view_parent_dashboard`. Parents see a "Children" view scoped to their linked players via the new `tt_player_parents` pivot.

## Configuration

`Configuration → Invitations` has two tabs:

- **Invitations** — paginated list of every invitation with filter by status, copy-link, revoke (admin / Head of Dev / Club Admin only).
- **Messages** — six message templates (3 roles × 2 locales — English + Dutch). Each editable as plain text with placeholder validation. Placeholders:
  - `{club}`, `{role}`, `{team}`, `{player}`, `{sender}`, `{url}`, `{ttl_days}`
  - `{url}` is **required** on save.

## Locale precedence

The share message renders in the recipient's locale, picked through this chain:

1. The target row's `locale` field on `tt_players` / `tt_people` (set per-row by an admin if known).
2. The club default — `tt_config.invite_default_locale` (defaults to `nl_NL` on fresh installs).
3. The inviter's WP locale as final fallback.

## Token + lifetime

- Tokens are 32-char URL-safe random (≈192 bits of entropy). Single-use.
- Default lifetime is **14 days**, configurable per club via `tt_config.invite_token_ttl_days`.
- Pending invitations sweep to **Expired** on every list render + every accept attempt.

## Rate limit + override

A soft cap of **50 invitations per admin per 24 hours** is enforced. Two override paths:

- **Filter** — `apply_filters('tt_invitation_daily_cap', 50, $user_id)` — for hosts that need a higher cap permanently.
- **Continue anyway** — when an admin hits the cap mid-flow, the share popover offers an inline reason field and a "Continue anyway" submit. The override + reason gets recorded in the audit log.

## Audit log

Every invitation event is logged to `tt_audit_log` with the actor + entity:

- `invitation.created` — actor created the row.
- `invitation.accepted` — recipient followed the link; IP + user-agent recorded for forensics.
- `invitation.revoked` — admin revoked.
- `invitation.cap_overridden` — admin clicked through the daily cap (records the reason).

## Hooks for extensions

The InvitationsModule fires three actions for plugin extensions:

- `do_action( 'tt_invitation_created', $id, $kind )` — fires after the row is persisted.
- `do_action( 'tt_invitation_accepted', $id, $kind, $user_id )` — fires after the WP user is created and the linking step succeeded.
- `do_action( 'tt_invitation_revoked', $id )` — fires after revocation.

Phase 1 ships no workflow template subscribing to `tt_invitation_accepted`; the hook is reserved for the v1.5 "welcome / set jersey number" task that lands inside #0022 Phase 2.

## See also

- [Roles and permissions](access-control.md) — for the four invitation-related capabilities.
- [Workflow engine](workflow-engine.md) — for the `tt_invitation_accepted` hook subscription pattern.
