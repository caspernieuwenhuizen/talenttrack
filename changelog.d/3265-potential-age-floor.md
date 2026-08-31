# Potential is no longer asked about children (#3265)

The potential bands describe how far a player might go **as a professional**. TalentTrack was putting that question to coaches about seven-year-olds, and the *Potential not revisited* alert was flagging every one of them for never having answered it.

Potential is now asked from age 13. Below that the **Set potential** card says so instead of offering the bands, the API refuses a write with the same reason, and the alert skips those players entirely — it was previously unresolvable on any academy running young squads, since the only way to clear it was to record exactly the judgement the rule now prevents.

Behaviour ratings are unchanged at every age. Bands already recorded on younger players stay visible and still draw the trajectory; what stops is being asked again. A player with no date of birth on record is still asked — a missing field is not evidence of being too young.
