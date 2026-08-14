# Spond sync: matches with no end time now default to kick-off + 105 min (#2389)

Bump: patch

Spond match events frequently carry no end time, which left imported
**matches** with a blank end while trainings (which do carry ends) looked
right — the "end time is wrong only for matches" report. The kick-off +
105 minute default already used by the "+ New activity" wizard (#1863) was
never wired into the Spond sync. Now, when a Spond match gives a start but
no end, the sync fills the end with kick-off + 105 min (clamped to
end-of-day for a very late kick-off). A real Spond end always wins — the
default only fills the blank — and trainings are unaffected.
