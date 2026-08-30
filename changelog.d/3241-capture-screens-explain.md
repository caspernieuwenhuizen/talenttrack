# The behaviour and potential forms now say what they are asking for (#3241)

Bump: minor

Both capture forms showed a scale and a list of bands and explained neither, and
nothing anywhere said how often potential is meant to be revisited. Two coaches
guessing at what *Semi-pro* means is how the same player gets recorded
differently — and three things downstream read those numbers as if they meant
the same thing.

**Behaviour** now names the ends of your own configured scale and says the
rating is about the week you just watched, not the player as a whole. A single
low week is information; the status reads the trend.

**Potential** asks how high the player can reach *at their peak*, not where they
are now, and carries a **What the bands mean** section next to the picker — one
line each for First team, Professional elsewhere, Semi-pro, Top amateur and
Foundation.

**The cadence is on the screen.** It says potential is a quarterly judgement,
when this player's band was last set and by whom, how long ago that was, and
whether it is now overdue. The threshold is your own
`alerts_potential_stale_days` setting, so the form and the *Potential not
revisited* alert can never disagree about what late means. A player who has
never had a band set says exactly that.

The **Set potential** popover on the player profile carries the same one-line
explanation, so the two ways in do not tell you different things.
