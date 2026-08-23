# Alerts: a new Onboarding alert (#2636)

Bump: minor

This release adds one alert about invitations. It is switched on from the
moment you update, for everyone who can act on it, so here is exactly what you
will start seeing:

- **Invitation never accepted** — a player or staff invitation was sent a
  fortnight ago and nobody ever accepted it. Goes to whoever sent it, and for
  a player invitation also to the head coach of their team. Usually the email
  went to spam or to a mistyped address, and until now nothing anywhere said
  so: TalentTrack recorded the send and the acceptance, and the gap between
  them was invisible unless somebody thought to open the invitations list.

It does not fire for an invitation the system has already made redundant — a
player or staff member whose account was created directly by an admin leaves a
pending invitation behind, and chasing it would be chasing something already
done. Nor for parent invitations, which have their own alert.

The threshold is an academy setting, `alerts_invitation_stale_days`.

This completes the alert catalogue for now: activities, evaluations, goals and
PDP, people, measurements, data quality and onboarding.
