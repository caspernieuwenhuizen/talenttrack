# Match prep's Available tile counts only players still on the team (#4285)

The match prep's Available and Unavailable tiles counted every saved
availability row, including players who had moved team or been archived
since availability was captured, while the selection lists showed only the
current roster: a prep could read "Available 18" above an empty squad. The
availability map is now scoped to the current roster in `MatchPrepState`, so
the tiles, both lists and `GET match-prep/{id}` read the same squad. Saved
rows are kept. The check that decides whether a viewer sees the separate
suspension list now asks once for the team instead of once per player.
