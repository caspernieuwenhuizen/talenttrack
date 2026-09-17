# Phone-home now reports what an academy records, not just who logged in (#3494)

The daily phone-home now includes five club-wide counts. The Admin Center uses them to see whether an academy is actually using TalentTrack:

- activities in the last 30 days with a recorded attendance register;
- activities in the last 30 days with minutes recorded;
- evaluations created in the last 30 days;
- open development plans;
- active goals.

They are counts only: never a player, a name or any text. The CI privacy self-check fails the build if anything other than an integer ever appears in this block. A planned roster is not a recorded register, so it does not count.
