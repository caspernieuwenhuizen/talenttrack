# Invitations are now emailed automatically (#1902)

Bump: minor

When an admin creates a parent/player invitation **with an email address**, the accept link is now **emailed to the invitee automatically** — previously invitations were link-only (copy / WhatsApp share), so an admin had to hand-carry every link. The email goes out through the existing Comms module (audit-logged, in the invitee's locale, with a "set your password" call to action and the link's expiry). It's transactional — it bypasses opt-out / quiet-hours / rate-limits so an invitee is never withheld their invite — and silently no-ops when the invite has no usable email (the copy-link / WhatsApp share path still stands). New `InvitationEmailTemplate` (registered in `CommsModule`) + an `InvitationEmailNotifier` that listens on `tt_invitation_created` and dispatches via `tt_comms_dispatch`. Closes the biggest self-serve onboarding gap for the player/parent go-live (epic #1723).
