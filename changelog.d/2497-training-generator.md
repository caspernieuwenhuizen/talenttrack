# The generator: answer four questions, get a training plan (#2497)

Bump: minor

**New training plan** on the Training tile opens a short wizard. Pick the team
and the date, choose what the session is about, confirm how long it runs and
how many players you expect — and the fourth screen is a finished session,
built from your own exercise library.

The number of players is filled in from your team's recent attendance rather
than its squad list, because a sixteen-player squad rarely puts sixteen on the
pitch. Change it whenever you know better.

Nothing is invented. Every exercise comes from your library, nothing is
proposed above the age group's intensity ceiling, and the same answers always
produce the same session. Where the library has no suitable exercise for part
of the session, that block is left blank and says so rather than being padded
out.

The last screen tells you which players' open goals the session actually works
on, by name.

Where a training cannot be drafted at all — an age group with no training
profile, so there is no age-safe intensity ceiling to plan inside — the wizard
now says so on the proposal screen and keeps you there, next to the Back button
that can fix it. It no longer walks you on to name a plan that was never going
to save.

The length you type is a request, not a guarantee: the blocks follow the age
group's training shape, so a 75-minute ask can come out at 90. When the draft
misses what you asked for by more than a few minutes, it tells you both
numbers rather than letting you find out on the pitch.

**Exercises now carry the principles they train.** The library's form gained a
"trains which principles" field, and exercises that already had a tactical
theme were linked automatically — 63 of them, across both shipped
methodologies. This is what lets the generator prefer a drill six of your
players need over one nobody is working on, and it is the same link that will
carry per-player training history later.

**Fixed:** the exercise library's intensity field offered levels 1 to 5, but
the scale runs to 10 and the older age groups train up to 7. Saving an
exercise through that form quietly reduced anything above 5. It now offers the
full range.
