# Central per-age-category default match minutes (#1727)

Bump: minor

You can now set a default match length per age category — minutes per half (N),
with the full match shown as 2 x N — under Configuration -> Match minutes. One
row per age group, blank inherits a global fallback of 35 minutes per half.
That central setting is now the single source of truth for match length:
new match prep and the match-completion minutes entry both prefill from the
team's age category instead of the old hardcoded 35-per-half / 70 default
(still editable per match). Accurate minutes feed each player's load and
development picture.
