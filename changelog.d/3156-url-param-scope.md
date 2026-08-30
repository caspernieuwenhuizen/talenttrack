# Rate cards and Player BMI hold their URL parameters to the viewer's scope (#3156)

Bump: patch

Two report surfaces resolved the viewer's team scope correctly for their
pickers and then ignored it for the `?team_id=` / `?player_id=` sitting next to
them. On Rate cards a hand-typed `team_id` listed any squad's roster into the
player dropdown and a hand-typed `player_id` rendered that player's whole rate
card. On Player BMI the team half was already clamped; the player drilldown was
not, so a team-scoped coach could read any club player's height, weight, BMI and
percentile history — growth data on a minor.

Both now clamp the URL parameter to the scope the view has already resolved
rather than resolving it a second time. An out-of-scope team on Rate cards
behaves as if none were given; an out-of-scope player is refused on both.

Rate cards also gains a capability check. It is registered for routing only —
no tile, no entity, no capability — so the dispatcher's two gates both passed it
through, and `render()` had no check of its own. It now requires
`tt_view_reports`, the capability its own docblock has always claimed and the
one its wp-admin twin uses.

The Back-button label resolver takes the same treatment: the record id it parses
out of a caller-supplied `tt_back` URL was club-scoped but not viewer-scoped, so
a crafted link resolved any child's name — or a team, PDP or evaluation label —
into the Back pill. It now falls back to the list-level label, which is what an
unresolvable id already did.
