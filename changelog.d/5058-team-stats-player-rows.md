# Team statistics route: per-player rows follow the player read (#5058)

`GET /teams/{id}/stats` now lists scorers, assists and appearances only for
the players the reader may view, by the same check the player pages use,
asked once for the whole payload. A coach of the team and the head of
development see every row; a scout sees the players linked to them. The
team's record and form are unchanged for everyone who reads the team.
