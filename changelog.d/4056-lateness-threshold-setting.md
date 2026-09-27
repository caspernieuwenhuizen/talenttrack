# Lateness at-risk threshold is now a settings field (#4056)

The lateness flag added in v4.135.0 counted on its own threshold but had no
field, so an academy that wanted to act on chronic lateness sooner or later
than on absence had to be talked through editing `tt_config` by hand.

**Configuration → General → Attendance** now holds both numbers side by
side: the attendance at-risk threshold and, beside it, the lateness one.
The lateness field renders from the stored value, so it is blank while the
academy is inheriting the attendance threshold and carries that inherited
number as its placeholder — you can see what it does today before changing
it. It stays blank until somebody types in it: pre-filling it would have
posted the inherited number back on the next save and quietly turned the
inheritance into a fixed number nobody chose. Clearing the field and saving
goes back to one number.

`AttendanceFlagService` gained `lateThresholdOverride()` for that
difference — `lateThreshold()` resolves the inheritance and so cannot tell
"set to 3" from "following 3". The key is on the config endpoint's
allow-list, and the tests assert the configured value changes which players
carry the lateness reason on the at-risk list, not merely that the option
round-trips. The two threshold inputs also pick up an explicit 48px touch
floor and lose the inline margins they carried.
