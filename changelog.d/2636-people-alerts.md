# Alerts: three new People alerts (#2636)

Bump: minor

This release adds three alerts about the people around a player. They are
switched on from the moment you update, for everyone who can act on them, so
here is exactly what you will start seeing:

- **Player turns 18 soon** — a player's eighteenth birthday is within 30 days.
  Goes to the head coach of their team. Turning eighteen changes the paperwork
  rather than the football: parental consent stops being the basis for holding
  their data, a youth agreement may need to become a contract, and the parent
  account's access becomes a decision rather than a default.
- **Parent invited but never activated** — a parent was invited more than a
  fortnight ago, never created their account, and the player still has no
  parent linked at all. Goes to whoever sent the invitation and to the head
  coach. A parent who was invited twice and accepted the other invitation, or
  who an admin linked directly, does not trigger it.
- **Certificate expiring** — one of your own certificates is within 60 days of
  expiring, or expired inside the last 60 days. This one goes **only to the
  person whose certificate it is**: that is somebody's professional record,
  not squad information. Already-expired certificates are included on purpose;
  dropping them would make the alert vanish exactly when the problem becomes
  real.

Thresholds are academy settings: `alerts_player_turns_18_days`,
`alerts_parent_invite_stale_days` and `alerts_staff_cert_expiring_days`. The
age of majority itself is not a setting — it is a fact about the jurisdiction
the academy operates in, not a preference.

Parent invitations are covered here; player and staff invitations get their
own alert in a later instalment, so nobody is told the same thing twice.

This is the third instalment filling out the alert catalogue, after
Evaluations and Goals/PDP.
