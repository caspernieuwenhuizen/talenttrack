# Demo data: playing time is shared out instead of following the player id (#3588)

The demo generator started the same lowest-id players in nearly every match.
As a result, every minutes report on the demo academy showed minutes falling
as the id rose. Starters now rotate the way a coach shares out playing time.
Each player carries their starts so far plus a standing place in the pecking
order, and the substitutes who come on first are those who have played least.
The demo now shows a realistic spread, with a few genuinely under-played
players. Minutes per match still add up to the squad size times the match
length.
