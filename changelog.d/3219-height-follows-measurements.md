# A player's profile height now follows their recorded measurements (#3219)

Bump: patch

The height on a player's profile used to be a single undated number, typed when
the player was first entered — which for a growing 13-year-old is wrong within
months and gave no sign of it. Record a height measurement and the profile now
shows it. Correct an older reading and the profile stays on the newer one;
remove the last reading and the existing value is left alone rather than
blanked. Name the test `Lengte`, `Height`, `Length` or `Stature` for it to be
recognised. The BMI report is unaffected — it still pairs each weight with the
height that was true at the time, which is what a BMI needs.
