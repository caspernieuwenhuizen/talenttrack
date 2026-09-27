# Tournament squad picker shows the injury availability flag (#4057)

Picking a squad for a tournament now marks a player carrying an open injury as
**Unavailable** beside their name, the same flag and the same word the
planned-attendance card, the planned-attendance picker and
`GET /activities/{id}/planned-attendance` already show. It came from the same
request as those three; the squad step was in another lane at the time and was
deliberately left alone.

It is advisory, not a block. A coach can still tick a flagged player — rehab
minutes, travelling with the squad, or something the injury record does not know
— they just cannot do it unknowingly, which is what the head coach who reported
it had to work around by remembering.

The flag says only that the player cannot be planned for. No injury type, body
part, note or date reaches the screen, so an assistant coach without access to
the injury record plans around it without seeing a child's medical details. It
is derived from the shared `PlayerAvailability` service rather than a second
reading of the injury table, so the four surfaces cannot drift.
