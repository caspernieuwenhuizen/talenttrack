# Academy admin can switch off individual player dashboard tiles (#1987)

Bump: minor

The player dashboard tiles — My journey, My team, My evaluations, My
activities, My goals and My PDP — are now per-academy features under the
Players module on the Modules &amp; features screen (`?tt_view=modules`). They
ship on; switching one off hides that tile from players *and* blocks its
`?tt_view` URL for this academy, reusing the existing feature-toggle plumbing
(per-club state, REST-managed). The player profile remains the always-on
anchor and is intentionally not toggleable.
