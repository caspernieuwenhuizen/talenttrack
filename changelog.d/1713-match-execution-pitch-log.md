# Match-day live surface: vertical positional pitch + chronological event log (#1713)

Bump: minor

The live match-execution screen now opens with a vertical pitch showing
the first-half starting eleven laid out by position, sourced from the
match-prep line-up and the bound formation shape. Below it a new "Live
progress" feed merges the goals and substitutions already logged during
the match into one time-ordered list — each row carries the half +
minute, a type chip (icon and text, not colour alone), and a running
score chip on goals. Both surfaces are also exposed as read endpoints
(`GET /match-execution/{activity_id}/event-feed` and `/pitch-lineup`)
behind the existing `tt_edit_activities` capability.

Scope notes: the Teamchemie badge from the mockup is deferred — no
chemistry metric exists yet and the algorithm is under review (#1017).
Red and yellow cards are not modelled, so the feed is goals +
substitutions only; no schema change was added.
