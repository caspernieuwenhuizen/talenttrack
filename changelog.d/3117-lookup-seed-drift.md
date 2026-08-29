# French, German and Spanish lookup labels that had silently never been seeded (#3117)

Bump: minor

The curated list of lookup translations had drifted away from the vocabulary it
translates. 68 of its entries pointed at names no longer in use — journey event
types renamed to internal keys, `Match` renamed to `game`, competition types
folded into game subtypes, the behaviour scale rewritten as 1–5 — so the seeding
step matched nothing and quietly did nothing for 13 of the 20 vocabularies it
claims to cover.

Dutch mostly escaped this, because a much older update had filled Dutch labels
in from the translation catalogue. French, German and Spanish had no such
backstop, so those labels fell through to raw English: 136 of 263 lookup values
carried a label on a reference install, and the missing ones were almost exactly
the drifted set.

The list is now re-keyed against the live vocabulary and completed, and an update
fills the newly-matching labels in — 36 per language, taking coverage to 172 of
263. It only ever fills empty slots; a label your club typed itself is never
overwritten. Stale entries for vocabularies that no longer exist have been
removed, and newly-added values that had never been curated (the `Tournament`,
`Observation` and `Other` evaluation types, the `Football periodisation`
certificate, the meeting activity type) now carry labels in all four languages.

A test now fails the build when the list drifts from the vocabulary again, in
either direction — an entry pointing at nothing, or a value with no entry.
