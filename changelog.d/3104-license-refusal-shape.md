# One shape for every "not on your plan" refusal (#3104)

Bump: minor

A feature your plan does not include now always refuses the same way, and
says so in the same words. Open one and you get a panel naming the feature,
naming the plan it belongs to, and linking to the account page — the screen
stays where it is rather than vanishing, so it is obvious the feature exists
and is simply switched off.

Two things that were previously implicit are now stated in the product and in
`docs/license-and-account.md`. Anything already recorded stays readable and
exportable when a feature leaves the plan; only writing new entries stops. And
"not on your plan" is a different answer from "you are not allowed" — over the
REST API the first is HTTP 402 and the second stays 403, so a failed request
says which of the two happened.

Groundwork: the panel and the refusal helpers are shared, so the surfaces that
gain a plan gate later cannot each invent their own wording. Nothing new is
gated by this change.
