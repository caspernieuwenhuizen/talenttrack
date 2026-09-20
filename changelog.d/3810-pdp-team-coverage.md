# PDP coverage, broken down by team (#3810)

Bump: minor

Seven weeks into a season, one development talk of sixty-four had been held, and there was no way to see which teams the other sixty-three were in. The PDP screen shows one team at a time and the line above it is a single ratio, so finding out meant texting four coaches.

The team-selection screen now opens with **PDP coverage by team**: one row per team with how many players it has, how many have a plan, how many have actually had a conversation, how many talks are booked in the next four weeks, and how many talks a parent has signed. Teams with the fewest players talked to come first, because that is the team you are looking for, and each team name links straight into its roster.

"With a plan" and "Talked to" are separate columns on purpose. A team where every player has a file and nobody has sat down yet reads as fully covered on the old summary line, and is exactly the team that needs chasing.

There is also a new **`conducted=0`** filter on the coverage endpoint — "who has not had their talk" in one call. Players with no file at all are included: they are the worst case, and a filter built to find people nobody has spoken to must not hide them.

Both the headline ratio and the per-team breakdown are computed over the whole filtered scope rather than the page on display. A coach still sees only their own players, in the breakdown as everywhere else.

The parent column counts talks a parent has **signed**. Nothing in the product records who was in the room, so it is named for the fact it actually holds rather than presented as an attendance register.
