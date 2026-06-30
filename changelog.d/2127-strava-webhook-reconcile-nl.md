# Strava console: Dutch translations + self-healing webhook subscription (#2127)

Bump: patch

Translates the Strava operator console into Dutch (the new strings shipped
English-only) and makes the webhook subscription robust against Strava's
one-subscription-per-application rule. "Create / re-verify" now adopts an
existing subscription instead of failing when one already exists at Strava,
and the subscription status reconciles against Strava's real state on load —
so an id this install lost is recovered and a subscription deleted from
Strava's side clears here automatically. Backed by a new read of Strava's
`GET /push_subscriptions` endpoint.
