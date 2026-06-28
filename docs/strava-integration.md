<!-- audience: user, admin -->

# Strava integration

Connect a player's personal Strava account so their training — runs, rides,
conditioning work done outside academy sessions — lands as dated entries on
**that player's own timeline**. It answers the question *"what training is this
player doing outside our sessions, and how is their load trending?"*

This is **account linking**, not "log in with Strava". Players stay signed into
TalentTrack as normal; connecting Strava is a one-time authorization bolted
onto the existing session. Strava is never an identity provider here, so a
player who cannot hold a Strava account is never locked out of TalentTrack.

---

## What syncs — and what does not

Imported for each activity:

- distance
- moving time and elapsed time
- pace / average speed
- elevation gain
- activity type, name, and start time

**No heart-rate or other biometric data is ever requested, stored, or shown.**

This is deliberate. Strava blocks heart-rate data for athletes under 16
(an EU-data-protection requirement), and most academy players are under 16.
Rather than build a feature that silently fails for the core cohort, the
integration is scoped to distance / duration / pace / elevation only — so it
works identically for every player regardless of age. Imported activities live
on a dedicated per-player list, separate from team sessions.

---

## Connecting a Strava account

The "Connect with Strava" panel lives on the player's profile (its own page at
`?tt_view=strava`, and a **Strava** tab on the player detail view).

1. Tick the **consent checkbox** — agreeing to share the player's activity data
   (distance, duration, pace, elevation) with the academy. The Connect button
   stays disabled until it is ticked.
2. Click **Connect with Strava**. You're sent to Strava's own consent screen.
3. Approve there, and Strava returns you to the player profile with a
   confirmation. Activities begin appearing within minutes of being recorded.

### Consent — who agrees, and a recorded caveat

Consent is captured on the **player's own profile** via the inline checkbox,
and is **audit-logged**. Enforcement is server-side: the authorization step
cannot be reached without a recorded consent, so the checkbox is the
affordance, not the only guard.

> **Recorded caveat (2026-06-28).** Capturing consent on the player's own
> profile is the simpler flow chosen for the pilot. For minors it is a weaker
> parental-consent posture than capturing consent on the parent's view of the
> child. This trade-off was accepted by the product owner; revisit it if a
> legal review requires guardian-side consent capture, at which point the
> affordance moves to the parent's view.

These are minors: imported data is visible only to roles already authorized to
view the player, never across academies, age groups, or unauthorized roles.

---

## Disconnecting

A player (or a coach with edit rights) can **Disconnect** from the panel: this
revokes the grant at Strava and clears the stored tokens. If the athlete
instead revokes access from **Strava's** side, TalentTrack is notified and does
the same automatically.

Either way, the previously imported activities are **archived** (soft-deleted,
not hard-erased) so nothing lingers in an authorized state after a disconnect.

---

## Operator setup

Two one-time steps, performed by an administrator:

1. **Register the Strava app credentials.** Create an API application in your
   Strava account and store its **Client ID** and **Client secret** via
   `POST /wp-json/talenttrack/v1/strava/app` (operator-only). The client secret
   is encrypted at rest and never returned by any endpoint.
2. **Create the webhook subscription.** `POST .../strava/webhook/subscription`
   registers the single academy-wide push subscription with Strava. Strava
   validates it immediately with a challenge handshake. Use the matching
   `GET` to inspect and `DELETE` to remove it.

The OAuth **callback** (`GET .../strava/callback`) and the **webhook**
(`GET/POST .../strava/webhook`) are public routes by necessity — Strava calls
them directly. They authenticate themselves (the callback verifies a signed
`state`; the webhook handshake verifies a per-install token), never via a
WordPress session.

---

## How it works (architecture)

- **OAuth connect.** The connect button mints an authorize URL carrying a
  signed, time-limited `state` that binds the connecting player (CSRF +
  identity binding). The public callback verifies that `state`, exchanges the
  code for tokens server-side, and stores them.
- **Per-player tokens, encrypted.** Each connection's access and refresh tokens
  are stored encrypted at rest, one row per player. Access tokens expire after
  six hours; the refresh token rotates on every refresh, and the rotated token
  is persisted atomically with the new access token so a player is never locked
  out by a torn write.
- **Token refresh** runs on the workflow engine's heartbeat (the one scheduler
  chokepoint), plus on demand right before a sync. A grant Strava rejects flips
  the connection to "revoked" so the UI can prompt a reconnect.
- **Webhook sync, not polling.** Strava allows exactly one push subscription per
  application, covering all authorized athletes. Activity create / update /
  delete and athlete deauthorization arrive as pushes; TalentTrack fetches the
  full activity with the player's token and upserts it. Polling every player
  would exceed Strava's rate limits — webhooks are the intended mechanism.

---

## REST API

All endpoints live under `talenttrack/v1`; see
[`docs/rest-api.md`](rest-api.md) for the full list and gating. In short:
per-player `connect` / `disconnect` / `status` / `activities`, the public
`callback` and `webhook`, and the operator-only `app` and
`webhook/subscription` management routes. Per-player tokens are never returned
in any response.
