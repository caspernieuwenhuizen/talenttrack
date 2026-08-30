# Blueprint and formation routes now check which team they were handed (#3181)

`GET /blueprints/{id}` gated on "do you hold team chemistry access
anywhere" and never looked at the id it was given, so a coach with a
grant on one squad could read any other squad's full match-day lineup —
position, tier and player — by changing one number in the URL. The five
write siblings and the clone route had the same shape, which meant that
lineup could also be rewritten or deleted. The team's formation and
playing-style routes shared the gap.

Every route carrying an id now resolves the team first and checks the
caller's grant against that team. For the blueprint routes the team is
looked up from the row itself, reading only that column, so the refusal
lands before any lineup is loaded. A global grant (head of development,
scout, academy admin) still reaches every team, and the blueprint editor
still works with the team-chemistry sub-feature switched off.
