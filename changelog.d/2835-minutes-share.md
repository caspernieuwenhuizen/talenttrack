# Minutes share: what percentage of the available minutes did each player get (#2835)

Bump: minor

Every minutes report answered in absolutes, and 350 minutes looks fine until
you know the team played 700. **Team · Minutes share** is the missing relative
figure: every played match's own length summed into a denominator, each
player's recorded minutes over it, and the squad ranked lowest share first.

The denominator is every match the team played, not the ones each player was
available for — a player who missed six weeks injured shows a low share, which
is the honest number, and shrinking the denominator per player would hide
exactly the case the report exists to surface. Match length comes from the
match prep, the age-group default, or 35 minutes a half, so a team on
30-minute halves gets 600 available over ten matches rather than a flat 700.

Every player should reach a minimum share of the playing time — 30% by default,
editable under Configuration → Match minutes. Anyone below it is flagged with a
glyph and the words, never colour alone. `GET /teams/{id}/minutes-share` and
`GET /teams/{id}/minutes-share/{player_id}` return the same numbers.
