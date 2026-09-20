---
title: Invitations
group: configuration
summary: Invite players, parents, and staff via shareable WhatsApp links — set passwords on first follow-through.
audience: [admin]
views: [invitations-config]
module: TT\Modules\Invitations\InvitationsModule
order: 70
---

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

## Automatic email

When an invitation is created **with an email address**, the accept link is also **emailed to the invitee automatically** — the admin no longer has to hand-carry every link. The email goes out through the Comms module (so it's audit-logged like any other message) in the invitee's locale, with a "set your password" call to action and the link's expiry. It's transactional: it bypasses opt-out / quiet-hours / rate-limits, so an invitee is never withheld their invite. The WhatsApp / copy-link share buttons still work for cases without an email or where the admin prefers to share by hand.

## Holding the credentials back

An invitation can be created **without sending it yet**. Nothing reaches the invitee until someone explicitly sends it — useful when setting a club up, where you want to add your coaches and check the place works before anyone gets an email inviting them into it.

Held invitations show in the invitations list as **not sent yet**, with a count above the table and a **Send all invitations** action, or a **Send now** on any single row. Until you send, the invitee has received nothing at all.

Sending is safe to repeat: an invitation that has already gone out is skipped rather than delivered twice, and a bulk send reports how many went and how many were left alone rather than one overall result.

Held invitations do not expire differently, do not behave differently once sent, and can be revoked like any other. Leaving the wizard or the page without sending does not lose them — they stay in the list, waiting.

## Acceptance flow

The recipient taps the link → lands on the dashboard's accept-invite route → sees a tiny form with three sections:

1. **Account** — recovery email + password (required).
2. **Role-specific assignment**:
 - Player → optional jersey number; profile already prefilled.
 - Parent → relationship label (parent / mother / father / guardian), notify-me checkbox.
 - Staff → confirmation of role + team (set by the inviter; not editable here).
3. **Submit** — the plugin creates the WP user, runs the linking step, signs them in, redirects to their dashboard.

If the recipient is **already signed in and their email matches** the invitation, the silent-link path runs: no full form, just a one-click "Accept and continue" button. For a **parent** invitation it still asks for the relationship (parent / mother / father / guardian) so a carer is never linked with an assumed role. On the full accept form, the recovery email is pre-filled from the invitation with a short note explaining it's only used for password recovery (and can be changed).

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
- `guardian_contact.requested` — staff asked a family for their contact details; filed against the **player**, recording where it was sent.
- `guardian_contact.submitted` — the family answered; filed against the player, recording each field's previous and new value so the write can be undone.

## Asking a family for their contact details

Not every family needs an account. Sometimes the academy only needs a name, an email address and a phone number on the player's file, so staff can ring home when a training is cancelled or a child picks up a knock — and that is exactly the data that arrives on paper, gets retyped at the gate and is stale within a season.

Open the player's edit form and scroll to **Ask the family**, under the guardian fields. Type the address to send to and press **Send request**. The family gets a short message naming their child and a link to a one-page form where they fill in their own name, email and phone, and confirm the academy may use them.

The **Player with no guardian contact** alert links straight to this form, so the office can work down the list from the alert inbox.

What happens when they answer:

- The details land on the player's record **immediately**. There is no approval queue — a second inbox would only delay the thing the office is already behind on.
- Only the fields they filled in are written; anything they leave blank is left exactly as it was.
- The audit log records what changed, from what to what, and which request was used. That is what makes a wrong answer fixable: the previous value is in the trail, so an admin can put it back.

What the link does **not** do:

- It never creates an account, and it grants nothing. It is not an invitation and cannot be redeemed as one.
- The page shows the **child's name and nothing else** — no team, no birthday, no evaluations, and never the contact details already on file, which may belong to the other parent.
- It works **once**, and expires after the same number of days as an invitation (**Invite link lifetime**, 14 days by default). An expired, spent or unknown link shows the same sentence: *this link is no longer valid, ask the academy for a new one*. Send a fresh one if a family needs it.

A parent with an account sees their own side of this under **My settings** → *What the academy holds about you*.

## Hooks for extensions

The InvitationsModule fires four actions for plugin extensions:

- `do_action( 'tt_invitation_created', $id, $kind )` — fires after the row is persisted, whether or not anybody was mailed.
- `do_action( 'tt_invitation_sent', $id )` — fires after a held invitation is delivered and `sent_at` is stamped.
- `do_action( 'tt_invitation_accepted', $id, $kind, $user_id )` — fires after the WP user is created and the linking step succeeded.
- `do_action( 'tt_invitation_revoked', $id )` — fires after revocation.

And two for the guardian-contact link:

- `do_action( 'tt_guardian_contact_requested', $request_id, $player_id, $email )` — fires after the request row is persisted, before it is mailed.
- `do_action( 'tt_guardian_contact_submitted', $request_id, $player_id, $changes )` — fires after the family's answer is written, with each changed field's previous and new value.

Phase 1 ships no workflow template subscribing to `tt_invitation_accepted`; the hook is reserved for the v1.5 "welcome / set jersey number" task that lands inside #0022 Phase 2.

## See also

- [Roles and permissions](access-control.md) — for the four invitation-related capabilities.
- [Workflow engine](workflow-engine.md) — for the `tt_invitation_accepted` hook subscription pattern.
