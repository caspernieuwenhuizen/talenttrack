# Require two-step verification: edit the academy-wide rule in the app (#4707)

Configuration → People & access → **Require two-step verification** now opens
a form in the app instead of sending the administrator to wp-admin. Tick the
roles that must set up two-factor authentication, then Save, or Cancel to go
back to Configuration. Each role shows how many of its accounts have not set
it up yet ("2 of 3 not set up yet"), as a count and never by name. When a
ticked role still has people to enrol, a line under the form says they will be
asked at their next login.

The form, the new `GET` / `PUT /talenttrack/v1/settings/mfa-policy` route and
the wp-admin operator form all save through one service. They store the same
value, need the academy-wide right to change settings, and write the same
audit-log entry. Resetting somebody else's second factor after a lockout
stays in wp-admin.
