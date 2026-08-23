# TalentTrack v4.101.1 — Tiles nobody can open are now caught before they ship (#2008)

A dashboard tile is offered on one permission and the surface behind it
sometimes demanded a stronger one, so a coach could be shown a tile that
refused them the moment they clicked it. It had happened four times, each fixed
individually, each time discovered by the person it happened to.

The check now runs on every change: it walks every registered tile, works out
which roles are offered it and which of those the destination would turn away,
and fails the build when a new mismatch appears. The four that exist today are
recorded rather than silently allowed — each needs a decision about whether the
surface should open read-only or the role should not be offered it at all, and
that is a judgement per surface rather than a mechanical change.

# TalentTrack v4.101.1 — A hidden surface is no longer reachable by typing its address (#2570)

The dashboard decides which surfaces to offer you from what each one declares
it needs. That declaration governed the menu only — open the address directly
and the check was never made, so a surface your role is not offered could still
be opened by someone who knew or guessed its URL. Seven of those were closed one
at a time; this closes the reason they kept appearing.

The dashboard now asks the same question before opening a surface that it asks
before listing it, so the two cannot answer differently again. Nothing changes
for anyone opening surfaces they were already offered. Pages reached from inside
another surface — a wizard step, a record's detail page — are unaffected: they
have no menu entry of their own, and their own checks still apply.

# TalentTrack v4.101.1 — The academy admin can open the authorization matrix again (#2776)

The frontend matrix editor shipped refusing the very role it was built for. An
academy admin opening it was told they did not have permission, and the same
refusal came back through the API.

The permission it needed had been described twice in the same list, once as
"may edit" and once, further down, as "may reset". The second description won
silently, so the editor asked for a privilege deliberately reserved for a
WordPress administrator. Resetting the matrix is unaffected and stays where it
was — it is checked separately, and was never part of this.

# TalentTrack v4.101.1 — Page-header actions no longer run off the side of a phone screen (#2789)

On narrow screens the action buttons beside a page title were laid out at
their combined natural width instead of the width actually available. On an
activity detail page that put eight of nine actions — including the one that
opens match execution — more than a thousand pixels off the right edge, where
a coach could only reach them by scrolling the whole page sideways.

The action slot now shrinks to the room it has and the buttons stack, so
every action is on screen at 360px and above. Desktop layout is unchanged.

# TalentTrack v4.101.1 — Weekly planner PDF no longer carries the browser's URL footer (#2791)

Saving the weekly planner sheet as a PDF put the browser's own header and
footer on the paper — the page URL and page number along the bottom, the
document title and date across the top. The sheet handed its whole paper
margin to the page box, and that margin is exactly the band a browser prints
those into.

The sheet now carries the 14mm margin as its own padding and leaves the page
box at zero, so there is nowhere for the band to print. Nothing on the sheet
moves: the printed geometry is identical to before, minus the browser's
additions. Same approach the goal-intake and methodology-reference print
sheets already use.

# TalentTrack v4.101.0 — A head coach no longer sees the Scouting visits tile (#2007)

Planning and logging outbound scouting visits is the scout's work; a head coach
was being offered it because the tile authorised on the same `prospects` record
type as their own onboarding funnel, and the two had been inseparable since an
unrelated fix in v4.20.2.

The tile now has a visibility setting of its own, granted to scouts, the head of
development and the academy admin. The head coach **keeps the onboarding
pipeline for their own age group** — that was deliberate, and removing the
prospects grant to hide the visits tile would have taken the funnel with it.
Direct navigation to the visit planner and to a single visit is refused for the
same personas the tile is hidden from.

Nothing changes for a scout, a head of development or an academy admin. An
upgrade backfills the new setting, so the tile does not disappear while the
matrix catches up.

# TalentTrack v4.101.0 — The authorization matrix is editable without a WordPress account (#2654)

An academy admin can now edit the persona × entity grid from
**Configuration → Authorization matrix**, gated on a new
`tt_manage_authorization` capability granted to administrator and Club Admin.
Until now the only editor was in wp-admin behind an administrator account, so
an academy without one on hand could not correct an over-broad or too-narrow
grant at all — and those grants decide who can open a player's evaluations,
notes and medical fields.

**What a Club Admin cannot do is the reason this could ship.** Their own
persona row is locked, and so are the entities that govern the permission
model, the schema and the backups. The lock is enforced when the save is
applied, not merely in the markup: a hand-crafted form post or a direct REST
call against a protected cell is rejected and writes neither a matrix row nor a
changelog entry. Reset-to-defaults, the seed export/import round-trip and the
matrix on/off switch were not delegated and stay administrator-only in
wp-admin — which also keeps that page as the recovery path, since a bad matrix
edit can hide the frontend surfaces that lead back to the matrix.

The save-and-audit logic moved out of the wp-admin controller into a shared
`MatrixEditService`, so the two screens and the new REST routes
(`GET`/`PUT /authorization/matrix`, `POST /authorization/matrix/reset`) write
identically. Behaviour for a WordPress administrator is unchanged on both
surfaces.

# TalentTrack v4.101.0 — A training photo now waits on the phone when there is no signal (#2735)

Out of range the capture screen used to say nothing was sent and leave the coach
to retake the photograph later. It now keeps the image on the device and reads it
the moment the connection returns, landing on the same checking step as always.
It survives a reload and a browser restart, and a count of what is waiting shows
on the capture screen and on the training plans page, for the coach who walked
away from the camera.

No plan is ever created without the coach — the promise that nothing is saved
until they press the button holds for a photo that waited exactly as it does for
one taken with full signal. A waiting photo stays on that phone and nowhere else,
is deleted as soon as it has been read and checked, and is dropped after seven
days whether or not anybody looked at it. The screen says so rather than letting
it vanish quietly: a coach who believes their training is safe should not find out
weeks later that it was not. `docs/photo-capture-dpia.md` records the device as a
processing location and closes the retention prerequisite that was open there.

# TalentTrack v4.100.0 — Exports read in Dutch, values as well as headers (#2012)

The column titles were
already translated; the cells under them were not, so a squad list opened in
Excel showed *Status* over `active`, *Rol* over `coach`, and
`["CB","LB"]` where the player's profile says *Centrale verdediger /
Linksback*. Since the export is usually the file that leaves the academy — to a
parent, a federation desk or the board — that was the most visible place for a
raw database code to surface.

Players list, team roster and season stats, attendance register, staff directory
and the goals export all now carry the same labels as the screen. A position
code an academy added itself, with no label yet, still shows as the code rather
than disappearing. The demo-data round-trip, the full backup and the
subject-access export keep their raw values on purpose — something reads those
back.

# TalentTrack v4.100.0 — "Review" on an old PDP verdict reads as a state, not an instruction (#2696)

The
word was translated once for the whole product, and the sense that won was the
wizard's — *check what you entered before saving*. On a PDP verdict carried by
an older plan it means something else: the plan still has to be looked at. It
now reads **Te beoordelen** there, alongside *In behandeling*, while every
wizard review step keeps the sense it needs. The periodic conversation about a
player is unaffected — the product already calls that a PDP-gesprek, and always
did.

# TalentTrack v4.100.0 — Match prep PDF: dark block over the second-half pitch (#2756)

Exporting the match-preparation PDF while the page was scrolled — which it
always is, since the grid is taller than the window — painted a hard-edged
dark block over part of a half-pitch, usually the second half. The pitch
colours themselves were never wrong: the image capture was given the wrong
scroll offsets, so the pitch's drop shadow landed inside the pitch instead
of around it, and the pitch clipped it into a block over the line-up. The
capture now uses the page's real scroll position, and the pitch joins the
other surfaces whose shadow is dropped for the export. Both half-pitches
come out the same light blue again; the on-screen view is unchanged.

# TalentTrack v4.100.0 — A privacy registration that quietly covered nothing (#2758)

The PII registry listed
evaluation ratings against a player column that table does not have — a rating
reaches a player through its evaluation, not directly. The registration was
therefore doing nothing, while the registry reported it as covered. Ratings were
never missing from an erasure or a subject-access export, because both already
follow the parent evaluation, but the registry now says so honestly. A test
checks every registration against its table, so the next one fails the build
instead of going quiet.

# TalentTrack v4.100.0 — A translation can no longer silently revert to English after a merge (#2765)

The translation catalogues carry git's union merge driver so parallel branches
stop conflicting on them; the cost is that a union merge takes both sides. Once
the i18n sync has relocated a branch's appended entries into their sorted
position on main, merging main back leaves the branch holding both copies — and
git reports no conflict, because nothing disagreed. It happened four times in
one day on four separate branches.

Duplicate entries are what the compiler refuses, so one reaching main can break
the compiled translations for every language. The quieter case is worse: when
the two copies disagree, one translated and one emptied, gettext takes the first
and the Dutch string reverts to English with no error anywhere.

A new check fails any pull request that duplicates an entry the base branch does
not, and names the strings. It runs as pure PHP so it can be reproduced on any
machine, understands translation contexts (a contextual entry sharing a string
with its plain twin is not a duplicate), and ignores obsolete blocks the way
gettext does.

# TalentTrack v4.100.0 — One intensity scale across the product, 1 to 7 (#2767)

Three parts of the product
disagreed about it: the exercise form offered ten levels, the handbook said
five, and the engine, the shipped drills and the age profiles all used seven.
That is not a cosmetic difference — intensity is the number the age-safe ceiling
is compared against, so a coach rating a hard drill against a documented 1–5
marked it a 5 when the shipped equivalent was a 6 or 7, and a session that
should have raised a warning for a player raised none.

The form, the VCT library and the age-profile editor now all read one scale, so
a band no age group can accommodate cannot be chosen at all. The handbook now
also says what the bands *mean* — 5 is a normal training block, 7 is as hard as
any age group should ever go — because a range on its own does not tell a coach
how to rate consistently.

# TalentTrack v4.100.0 — The match day team sheet has its own switch (#2769)

One setting used to gate
both match-prep exports, so an academy that files match forms digitally could
only hide the **Wedstrijdformulier afdrukken** button by also losing **PDF
exporteren** — the sheet the coach actually takes to the touchline. They are two
documents for two readers: the coach's carries the plan, the referee's carries
identity and eligibility.

**Match day team sheet** is a new feature under Match prep, on by default, so
nothing changes for an academy that still hands paper to the referee. Switch it
off and the button leaves the toolbar, the print URL refuses, and the
server-side export on the Exports page stops offering it — while the coach's own
PDF is untouched. That last part is new too: the server-side team-sheet export
previously ran whatever the toggle said.

# TalentTrack v4.100.0 — Saving an activity no longer empties its Line-up card (#2771)

A match prep writes
its Starting XI and bench through onto the activity's attendance rows, and the
activity detail's Line-up card — and the match-day team sheet's fallback — read
nothing else. Saving the activity rewrote those rows to store status and notes
and silently dropped the two line-up columns along the way, so the card went
blank while the prep itself was untouched. Re-opening the prep still showed the
line-up, which is why this read as a card that disappeared rather than as data
that was lost.

The rewrite now carries the line-up across on both paths, planned and completed.
An explicit starter tick on the completion form still wins: the coach who ticked
the box on this save meant it.

# TalentTrack v4.99.1 — Confirm dialogs say what they do, tournaments stop offering match prep, and the line-up card fits (#2684, #2686, #2763)

**Confirm dialogs finally carry their own words.** Reopening a completed
activity asked *"Archive record"* behind a red *"Archive"* button while the
message underneath correctly described reopening. The per-action title,
label and button colour were being assembled and then dropped on the way to
the dialog, so every action wore the archive defaults: Reopen, Restore and
Sync from Spond all read as destructive.

That also brings back a feature nobody could reach: archiving a team offers
its **"archive this team's activities too"** checkbox again. Because the
checkbox never appeared, its answer was sent as *no* on every single team
archive, whatever the coach intended.

**Tournaments no longer offer match preparation or the live-match screen.**
The buttons were there and the screens behind them refused to open — a dead
end. They are gone rather than fixed, because a tournament is usually
several games in one day and match prep holds one line-up, one availability
list and one set of player goals per activity: it would have quietly
described a whole tournament as a single fixture. Tournaments keep the
minutes grid and per-player minutes entry, which handle a multi-game day
correctly.

**The line-up card on a match now spans the full width of the detail page.**
It was sharing a row, then splitting again into Starting XI and Bench, which
left each player about a quarter of the page and truncated names to things
like `#4 M...`. Names render in full now, and the Expected attendance card
beside it shrinks to its own content instead of being stretched to the
line-up's height — as do Notes, Linked principles and the other short cards.

# TalentTrack v4.99.0 — Photo and video consent can now be recorded against a player (#2744)

The media library stores photographs of children and had no way to record
whether the family had agreed to it. Academies were tracking that on paper
with nothing in the system to check against before a matchday.

Each player record now carries a **Photo & video consent** checkbox on the
edit form, beside the photo. Ticking it stores the date and the name of the
staff member who recorded it, so the entry is evidence rather than a bare
assertion. Clearing it removes both. The player's profile shows the answer
to staff — including when the answer is no, since a blank would read as
though nobody had asked.

**This records; it does not restrict.** Nothing about adding a photo checks
the box, and a coach can still add media for a player with no consent on
record. That is deliberate rather than unfinished: the real control is the
conversation and the form the family signed, and a hard block at the side of
a pitch tends to be worked around by photographing on a personal phone
instead — which leaves the child worse off than a recorded gap does. What
the field is for is answering *who may we photograph?* before the day, and
being able to show the question was asked.

Withdrawal is recorded by clearing the box. It does not reach back and
remove photographs already stored; those are removed from the player's
Media tab.

# TalentTrack v4.99.0 — Long media galleries load in pages (#2745)

A player, team or activity with a lot of photos rendered every single one
at once. On a phone after a full season that is a heavy page for no good
reason — nobody scrolls to August while looking for last weekend.

Galleries now show the 24 most recent items with a **Show more** button
underneath, adding the next 24 each time until there is nothing left and
the button disappears.

It is a button rather than loading more as you scroll, on purpose: the
oldest photo stays reachable, the browser's back button keeps working, and
there is nothing small to aim at with a thumb. Keyboard users land on the
first newly-loaded item rather than being thrown back to the top, and a
screen reader is told when more has arrived.

The count on the Media tab still reflects everything held for that player,
not what is currently on screen.

# TalentTrack v4.99.0 — Match analysis reads as one page, and sharing it is now a decision (#2748, #2749)

The finished analysis is a document instead of a stack of half-empty cards,
and the screen, the share link and the print sheet are now the same
document — so what you look at is what the person you send it to sees.

**Two chains instead of six cards.** The phases sit in two columns that each
read top to bottom: with the ball (attacking → the instant we lose it → our
own set pieces) and without it (defending → the instant we win it →
theirs). A transition only means something read next to the phase it comes
out of. Each phase carries its own points inside its own tile, so the
qualification and the specifics sit together rather than in a separate list
further down, and a phase nobody rated is a thin placeholder rather than a
full-size empty card.

**Set pieces split by side.** They shipped merged, which put a note about our
corners in the same box as one about defending their free kicks. Splitting
them also restores an exact 1:1 mapping with the four goal boxes on the match
plan, so every planned line now appears beside the phase it was planned for.
Anything already written under the merged section moves to the attacking side
and keeps its text.

**The rating moved onto the phase's own heading.** It was four full-width
pills in the same white-on-outline as the text fields below them, which read
as a row of empty inputs — and the selected one always turned green, whatever
you picked. It is now three compact chips carrying the rating's own colour,
with **Clear** appearing once something is set.

**A share link is no longer created just by opening the page.** It was:
merely looking at an analysis wrote a live, working URL nobody had asked for,
on a document that names children. Now there is **Create share link**, and
once it exists you get the URL with **Copy link** beside it and a **Replace
link** action that says plainly that the current one stops working.

**Saving keeps you where you are.** It used to reload the page and jump the
scroll to the top, which reads as having been taken somewhere else; your text
and the print and share actions stay put now.

The printed sheet is landscape A4, built to fit one page, and still real
selectable text rather than an image.

# TalentTrack v4.99.0 — Classify the exercise library in bulk (#2753)

An exercise with no principles is never suggested by the generator, and time
spent on it does not count towards what your players have been taught. Neither
consequence is visible from the library list — which is why, on a typical
install, most of the library has no principles at all and has stayed that way.

The library now says how many exercises are waiting and offers **Classify them**.
That screen is built around one action: **tick several exercises, choose the
principles they train, apply once.** A classified exercise carries around eight
principles, so doing it one at a time is hundreds of separate saves. Selecting a
whole category and applying in one go is the difference between an afternoon and
a fortnight.

Exercises are grouped by category, since drills of a kind usually train the same
principles, and **Select all shown** takes a whole group.

**"None apply" is what lets you finish.** Warm-ups, cool-downs and conditioning
work mostly should not carry a tactical principle — a warm-up does not train
building up from the goalkeeper. Marking them as looked at keeps them out of the
list, so the count reaches zero instead of showing you the same warm-ups forever.

Two things it will not do to you: adding principles never removes the ones an
exercise already had, and replacing only affects the methodology you are working
in — if your academy runs more than one, the others are left exactly as they
were.

# TalentTrack v4.99.0 — Match prep PDF: no more placeholder text or stray buttons on paper (#2755)

The exported match-prep PDF no longer prints the grey hint text from empty
fields. An unfilled goal line comes out as a blank ruled line instead of
"Doelstelling 2…", and a player with no note prints an empty cell instead of
"…". The `×` that clears a set-piece player and the `→` that copies the first
half's line-up to the second no longer print either — they're on-screen
controls, not part of the team sheet.

The export is an image capture rather than a browser print, so it never read
the print stylesheet that already handled all of this. The placeholder half
could not be fixed from CSS at all: the capture engine ignores `::placeholder`
and paints an empty field's `placeholder` attribute as ordinary text, which is
why the hints came out darker on paper than they look on screen. The attribute
is now removed from the capture clone, which every surface using the shared
image-export module benefits from. Nothing changes on screen or in the browser
print dialog.

# TalentTrack v4.99.0 — The academy logo top-left now links to the dashboard (#2764)

Clicking the crest and academy name in the top-left corner returns you to
the dashboard, in both the classic header and the app shell's sidebar —
including the collapsed icon rail. Installs without a logo get the same
behaviour on the gold initials mark. The link carries the academy name as
its accessible name and is reachable by keyboard; nothing else about the
header changes.

# TalentTrack v4.98.0 — Finishing a course now shows on your staff record, and courses can be assigned (#2649)

Until now, completing a course was something the knowledge library knew about
and nothing else did. Two changes fix that.

**Completing a course issues a certificate.** It appears on the coach's staff
record and their PDP, alongside their UEFA badges and safeguarding training,
and the academy-wide certificate overview picks it up. Nobody has to type
anything in — finishing the last lesson is what puts it there.

A course can say how long its certificate stays valid; the periodisation course
does not, so it does not expire. Where a course does set a period, the existing
expiry overview and the reminder about expiring certificates handle it exactly
as they do for any other certificate.

If a reviewer later withdraws their approval of an assignment, the course stops
being complete and **the certificate is withdrawn with it**. It is not deleted —
it shows as archived, because it was genuinely issued — but it no longer counts
as a live qualification. A certificate standing on work that was retracted would
be worse than no certificate at all.

**You can assign a course to several people at once.** "Assign a course" walks
through picking the course, picking the staff, and setting an optional
deadline, then shows what is about to happen before it does it. Staff are
filtered to the people the course is written for; if nobody matches — usually
because staff records are not linked to accounts yet — it shows everyone and
says why.

Assigning the same course to the same person twice does nothing, and the
confirmation step tells you so up front: "3 people will be enrolled, 12 are
already on this course and will be left as they are." Existing deadlines are
never quietly overwritten.

**And a team question you could not ask before:** which of the staff around a
given squad have finished a given course, including the ones who never started
it. That is the point of putting completions on the staff record — every player
in a squad has an interest in the person running their training being trained
themselves.

# TalentTrack v4.98.0 — Three new reports: who has done which course, and where people get stuck (#2650)

The knowledge library has recorded progress since it shipped, but there was
nowhere to see it across the academy. Three reports now sit in the Reports
launcher alongside the others.

**Learning · Course completion** — per course: how many are enrolled, not
started, in progress, completed and overdue, the median number of days people
take, and **the lesson readers stop at**. That last column is the one worth
looking at: it is the only number here that says something about the course
rather than about the coaches. A lesson half the group stops before finishing
is usually badly written, badly placed, or asking for something they cannot do
yet — and a completion percentage never tells you that.

**Learning · Per person** — who is on what, how far they have got, what is
overdue, and what is sitting with a reviewer.

**Learning · Staff coverage per team** — for each squad, how much of the staff
around it has finished each course. *The U13 staff are 2 of 4 on the
periodisation course.*

Three levels of access, as elsewhere in the module. A coach sees **their own
record** — not an error page — and needs the learning-statistics permission to
see anyone else's. That is enforced behind the scenes as well as on screen, so
the numbers cannot be reached another way.

Overdue and coverage are shown as labelled tags — "3 overdue", "All trained",
"2 to go" — rather than colour alone, so the tables read correctly for anyone
who does not distinguish red from green. The course report exports to CSV with
readable values rather than internal codes.

# TalentTrack v4.98.0 — Activity header actions follow the activity's status (#2685)

The activity detail page offered the same buttons whether the activity was
still to come or long finished: a completed training showed Edit, "Run this
training" and "Continue rating" alongside Reopen. Status is now read before
the action list is built, so a completed or cancelled activity gets read
affordances instead of an invitation to start work over.

Edit is offered on planned activities only — Reopen is the way back to an
editable record. Match prep reads "View match prep" once the match has been
played, and on a planned match its label finally reflects reality: "Plan
match prep" when no prep exists, "Match prep" once it does. "Start match"
and "Resume match" no longer appear on a finished activity; "View match"
is the only execution label left there. A finished training reads "View this
training", and shows no run button at all when no plan was ever attached.

The rating button now says what is left to do — "Rate players" when nobody
has been rated, "Continue rating" when some have — and disappears once every
attending player carries a rating, taking the completed-training header from
seven buttons down to six. Completing an activity does not rate anyone by
itself, so the button stays available after completion until the work is
actually done; the Ratings grid button remains either way.

# TalentTrack v4.98.0 — Alerts clear the moment you fix the thing (#2731)

An alert used to linger for up to an hour after you had dealt with it. You
marked the session completed, recorded the attendance, assigned the head
coach — and the banner, the bell and the alerts list all carried on saying
otherwise until a background job next ran. The engine was right and the
screen was stale, but from where you were sitting the product simply looked
wrong about your own data.

Alerts are now re-checked at the moment the record changes. Fix the thing
and the next screen you land on no longer mentions it. That holds for every
alert TalentTrack ships: past activity still planned, attendance
unrecorded, no coach assigned, player not evaluated, evaluation window
closing, evaluation never shared, goal past its target date, PDP cycle with
no conversation, player turning 18, parent never activated, staff
certificate expiring, no measurement this season, player without a team,
team without a head coach, and stale invitations.

The re-check runs after the page has been sent to you, so saving is no
slower than it was — including on the attendance grid, which can touch a
whole squad in one go. A save like that counts as one re-check, not forty.
Very large operations, such as importing players or rolling over a season,
deliberately leave the recount to the hourly job rather than performing
hundreds of them while you wait.

The hourly background check has not gone away and still matters: it is the
only thing that notices a condition that became true because time passed
rather than because somebody saved something — a certificate reaching its
expiry date, an invitation nobody answered, a session slipping past the
point where its attendance is overdue.

Alongside this, several parts of the app that changed records quietly now
announce it, which anything extending TalentTrack can listen for:
`tt_activity_saved`, `tt_activity_attendance_changed`,
`tt_measurement_result_saved`, `tt_staff_certification_saved` and
`tt_pdp_conversation_saved`. Evaluations created through the evaluation
wizard now fire `tt_evaluation_saved` as well — they never did, so
automatic follow-up tasks configured against that event were only ever
created for evaluations saved the other way. They now fire for both.

# TalentTrack v4.98.0 — Course reader: uses the screen, and asks you questions as you go (#2737, #2738)

Two changes from working through the periodisation course on a laptop.

**The reader now uses the width.** Lessons were capped at a paperback column,
so on a normal laptop screen more than half the window sat empty while the
week planner and the load matrix were squeezed into it — and the widest tables
scrolled sideways with 700 pixels of space beside them. The text keeps a
comfortable reading width, because very long lines are genuinely harder to
read, but everything that is a tool or a table rather than a sentence now
spans the full page. Phones and tablets in portrait are unchanged.

**There are now questions throughout the lesson, not just at the end.** Every
module used to be a stretch of reading followed by one quiz and one practical
assignment you carried out days later with your team. The reading was fine;
there was just nothing to do while you did it.

Each lesson now has three to four **quick checks** dropped into the text at the
points where people go wrong. Pick an answer and you are told straight away
whether it is right, with the reasoning either way. They are not marked and not
recorded — they do not count towards completing the lesson and they appear in
no report. They exist to interrupt: committing to an answer before you see the
right one is what makes something stick, and quietly nodding along does not.

Forty-two of them across the course, in Dutch, on the things that actually
catch coaches out: the 72 hours before a match, halving minutes rather than
partijen, why the conditietraining sits on day 3 or 4, and why overload before
the growth spurt costs you the damage without the benefit.

The end-of-module quiz is unchanged and still counts. A handful of the old
"think about it, then open" boxes have become real checks, since those asked
you to consider a question and then let you read the answer without ever
committing to one.

# TalentTrack v4.98.0 — The growth-spurt warning works again (#2739)

The printed training sheet carries a warning naming players whose growth-spurt
intensity ceiling is below the hardest block in the plan. **It was not appearing
— on any plan that was not built by the generator.**

A block took its intensity from the exercise only when the generator put it
there. Plans built in the plan builder, through the API, or from a photographed
sheet stored no intensity at all, and the check read that as "intensity zero"
rather than "intensity unknown" — so it concluded there was nothing to warn
about and printed nothing.

Nothing was wrong with the plans. What was wrong is that a sheet with no warning
looked exactly like a sheet that had been checked, and a coach had no way to tell
the difference.

Now:

- A block takes its exercise's intensity when it has none of its own, so the
  check has something to work with. This applies to plans you already have — no
  re-saving needed.
- **A plan where no block has any intensity recorded now says so on the sheet**,
  in grey, rather than printing nothing. If you see nothing, the check ran.
- An intensity you set on a block yourself is never overwritten — an adapted or
  walked-through version of a hard drill stays as you rated it.
- The same value now reaches the sideline view's record of the session.

If your exercises have no intensity set, this is worth doing: it is what turns
the growth-spurt warning from silent into useful.

# TalentTrack v4.98.0 — An uploaded photo now appears straight away (#2742)

Adding a photo or video from a player, team or activity page reported
"Added" and then showed nothing. The upload had worked and the file was
safely stored, but the grid below only picked it up when the page was
reloaded — so the natural reaction was to try again, and end up with the
same photo twice.

Uploads now appear at the top of the grid the moment they finish, complete
with their thumbnail, the Remove button and, on an activity, the control for
tagging the players in the shot. Adding several at once shows each one as it
lands, and the first upload into an empty gallery replaces the "No photos or
video yet" message rather than sitting behind it.

# TalentTrack v4.98.0 — A subject-access export now accounts for photographs and video (#2743)

When someone exercised their right to see everything the academy holds on a
player, the export left out photographs and video entirely — while the
academy went on holding them. Everything else was covered; media had simply
never been added to the list of places a player's data lives.

The export now includes a `media.json` listing every photograph and video
held of that player: what it is, when it was taken, what it was attached to
and who added it.

The files themselves are deliberately not included. A season of video runs
to gigabytes, and an export too large to produce helps nobody. The export
says this in as many words rather than staying silent about it, because a
list with no explanation reads as though there is nothing to hand over —
which would be worse than the omission it replaces. An academy that is asked
for the files sends them on separately from the player's Media tab.

Media belonging to a team or an activity stays out of an individual's
export, even where that player was present. Those belong to the team or the
session rather than to one child, and mixing them in would disclose other
families' photographs to someone with no right to them.

Erasure was never affected — deleting a player has always deleted their
photographs.

# TalentTrack v4.97.0 — Photograph a hand-written training and get a draft back (#2502)

The last wave of the training module. If your academy has photo reading switched
on, **From a photo** on the training plans page turns a sheet you wrote out by
hand into a draft plan: photograph it, check what was read, create it.

**Nothing is saved until you press the button that says so.** Close the page at
the checking step and there is no plan, no blocks and no photograph anywhere.

The checking step shows how sure it is about each line — green for a confident
match, amber for one worth a second look, red for a line it did not recognise.
An unrecognised line stays as a loose block, and the screen says what that costs:
it will not count towards what your players have been taught, because that count
is built from matched exercises. Names and durations can be changed before the
draft is created, and a line that was never really there can be removed.

**Where the photo goes is on the screen, beside the shutter, before you take it.**
Your administrator decides where photographs are sent to be read; until that
choice has been made this screen refuses to open and says so, rather than sending
anything. If you have no signal nothing is sent, and the screen tells you that
too.

Everything the wave needed on the server was already built and had never had a
screen — the extraction, the matching against your library, and the draft-plan
write. This is that screen.

**Not yet:** holding a photograph on the phone while you are out of range so it
reads itself on your way back. Today you retake it.

# TalentTrack v4.97.0 — The sideline view keeps working when the signal drops (#2552)

Pitches are where signal is worst, and until now a coach who lost it mid-session
lost that session: block timings and observations typed into a form that then
failed to save. That is the exact failure that sends people back to paper.

Now those writes are kept on the phone and sent as soon as there is a connection
again. A line at the top of the sideline view says how many are waiting —
*"2 wijzigingen wachten op bereik"* — and it survives locking the phone,
switching apps and reloading the page.

**Nothing is recorded twice.** If a change reaches the server but the reply is
lost on the way back, the phone tries again, and the second attempt lands on the
same record instead of creating a duplicate. That matters more than it sounds:
these numbers become each player's training minutes, so a change applied twice
would put a wrong figure on a child's development record.

A change that still cannot be saved after reconnecting — because you were away
long enough for your login to expire — stays queued rather than being discarded.
Reload the page and it goes.

**Opening the page still needs signal.** What this protects is a session already
underway; starting one from nothing with no connection is a separate thing and
is not covered.

# TalentTrack v4.97.0 — Alerts: see whether the engine is running, and which alerts people ignore (#2634)

The **Alert policy** screen now opens with an engine-health panel.

It answers the question nothing else can: a background job that has stopped
produces exactly the same screens as an academy with nothing wrong — empty
ones. If alerts have not been checked recently, scheduled tasks are not
running on the site and every alert screen is frozen at whenever they last
did.

Underneath it, a table per alert: how many are open, how many were cleared,
and what share people simply dismissed.

That last figure is the point. An alert most people dismiss is not informing
anyone — it is teaching them to dismiss alerts, and the useful ones go with
it. Anything above about 60%, over enough occurrences to mean something, is
flagged for review. Nothing is switched off automatically: whether an alert
earns its place is a judgement about your academy, not a calculation.

Also available through the API at `/alerts/diagnostics` for anyone wanting to
monitor the engine externally.

# TalentTrack v4.97.0 — Knowledge library: hand in a practical assignment, and review one (#2648)

Every module of the periodisation course ends in a practical assignment the
coach runs with their own team. Until now those were something to read. They
can now be handed in, and somebody reviews them.

At the bottom of a lesson's assignment there is a box to write your answer in
and a button to hand it in. If you prefer to be walked through it, "Hand in an
assignment" does the same thing in three steps — write, attach, confirm. Either
way you see afterwards exactly where the work stands.

Your submission goes to your mentor if you have one. If you do not, it goes to
a shared queue that anyone who manages the knowledge library can pick up, so it
never sits waiting on one specific person who happens to be on holiday.

Reviewers get a new **Assignments to review** page, oldest first, with the
original assignment shown above each answer so there is no need to go and look
up what was asked. Three decisions are available: approve, ask for changes, or
do not approve. Asking for changes sends it back and lets the coach revise and
hand in again — the earlier version and your feedback both stay on the record.
A reason is required for anything other than approval.

Approving is what completes the lesson. Withdrawing an approval un-completes
it again, so a course cannot stay finished on work that was later retracted.

**Attachments are documents only** — PDF, Word, spreadsheet, OpenDocument or
plain text. Photos and video are deliberately not accepted on an assignment: a
submission is attached to no player and no team, so a photograph handed in here
would sit outside the consent and visibility rules that protect players in the
rest of the system. What the assignments ask for is written work.

A new alert tells reviewers what is waiting. It is not an email per submission
— it shows what is in your queue right now and disappears by itself when you
have cleared it. Anything left for more than a week is flagged more prominently.

# TalentTrack v4.97.0 — Correction: photo capture is not off by default (#2695)

The v4.96.0 notes and the photo-capture DPIA both said the
`exercises_vision_extraction` feature was off on a default install, and the DPIA
leaned on that as a safeguard. **It is on by default.** That statement was wrong
and has been corrected.

Nothing about your install's actual safety changes. A site that has not set
`TT_VISION_ENDPOINT` and `TT_VISION_DATA_REGION` still sends nothing — the
endpoint answers `503` and no photograph leaves the server. The protection is
real; it just comes from the destination declaration rather than from the
feature flag.

What this means in practice: if you were relying on the feature being off, it is
not, and you should switch it off explicitly. Simply leaving the two destination
settings unset already prevents anything being sent, and remains the thing the
DPIA treats as the deliberate act a signature authorises.

A test now compares the document against the code's actual default, so the two
cannot drift apart again.

# TalentTrack v4.97.0 — Photo-capture DPIA: the legal decisions are recorded (#2695)

Legal clearance for photo-to-plan capture was given on 2026-08-23, and the DPIA
now records what was decided rather than listing what was outstanding:

- **Lawful basis: consent** (Art. 6(1)(a)), given by the parent or guardian,
  with the reasoning written down — the data subjects are children, and
  legitimate interest would have the academy weighing its own convenience
  against a child's privacy and marking its own homework.
- **No in-product consent step.** Consent is captured at registration. An extra
  tap on the capture screen would look like consent while collecting it from the
  wrong person: the coach is not the data subject.
- **A photo held on a phone lives at most 7 days.** Nothing is held today —
  capture shipped online-only — so this is the number the feature will be built
  to when holding lands.
- **Provider terms confirmed** by the data controller.

Two blanks remain for the academy to complete at signing: where consent is
captured, and how it is withdrawn. The product cannot know either.

The feature still cannot send anything until an administrator sets
`TT_VISION_ENDPOINT` and `TT_VISION_DATA_REGION`.

# TalentTrack v4.97.0 — Links across the app now land on the right page (#2720)

On academies whose dashboard is not the site's front page, a scattering of
links quietly went to the wrong place — the theme's homepage, with no error
and nothing to explain it. The destination only ever reads its instructions
on the page that hosts the dashboard, and these links were pointing at the
site root instead.

Twenty-nine places were affected. Among the ones most likely to have been
noticed:

- the **"my tasks" link in task notification emails**
- **Print view** close buttons on match prep, match analysis, PDP files,
  training plans and the weekly team planner
- **Help and documentation** links throughout the app, including the help
  drawer
- the **trial** surfaces — case details from the dashboard widget, the
  parent-meeting screen, the printed letter, reminder emails, and the two
  redirects after saving a trial track
- scout report links on the dashboard, the mail-compose shortcut on the
  People admin page, and the closing step of the person and prospect wizards

All of them now resolve the dashboard page properly. That resolution also
copes with the page having been renamed or moved to the trash: it finds the
live one and remembers it. Links generated by scheduled background work —
reminder and notification emails in particular — no longer risk pointing at
an internal maintenance address.

A new automated check refuses any future link built the wrong way, so this
particular mistake cannot come back a third time.

# TalentTrack v4.97.0 — A nudge when a match goes unreviewed, and observations that no longer outlive their match (#2723, #2724)

**A new alert: "Match played, no analysis".** A match played between two
days and two weeks ago with nobody's write-up on it now shows on the bell.
It appears on the badge only — never as a banner — because a missing
analysis is a prompt, not a problem with your data, and it stops after a
fortnight: by then the detail is gone and a reminder is only guilt. Writing
the analysis clears it; there is nothing to dismiss.

It deliberately stays quiet about two things. Tournaments, which cannot
carry an analysis yet, because telling a coach to do something the product
refuses to let them do is worse than saying nothing. And matches where no
attendance was recorded at all — that academy is already getting the
attendance alert, and two nudges about one match is how an inbox becomes
noise. An academy that switches Match analysis off stops being asked for
analyses entirely.

**Deleting an activity no longer leaves observations behind.** A coach's
note about a named player — from a match analysis or from a training —
emits an entry on that player's timeline. Deleting the activity removed the
note but left the timeline entry standing: a sentence about a child,
pointing at a match or training that no longer exists. Both kinds are now
removed with their activity, and the delete-preview counts them, so the
number you are shown before confirming is the number that goes.

The same fix reaches training observations themselves, which were not being
removed with their activity either.

# TalentTrack v4.97.0 — Match analysis: the roster is a tally sheet, and the wizard is styled again (#2726)

Two fixes to the match analysis that shipped in v4.96.0, one of them
visible the moment you opened the wizard.

**The wizard steps rendered unstyled.** The stylesheet was enqueued on the
first step only, and every wizard step is its own page load — so steps two
to five arrived with no CSS. That is worse than it sounds for this screen:
the marker chips are a hidden radio plus a styled label, so losing the
stylesheet turned them back into raw browser radio buttons stacked down the
page. Assets are now asked for once per step, from one place.

**Marking players is a tally sheet now, not fourteen forms.** The squad
renders as a grid of names; tap one and pick ▲ Stood out, ● As expected or
▼ Below par. The name takes that colour and the player drops into a Notes
list underneath, where the note and phase fields live. Only the players you
marked have a note field, so a squad of fourteen fits on one phone screen
and an analysis you have not started has no text boxes on it at all.

Nothing about what gets stored has changed, and the whole squad is still
listed — that is what stops the quiet players being skipped. What changed is
that the page no longer asks fourteen questions to collect two answers.

The section ratings (Went well / Mixed / Needs work) also moved from a
wrapping row to a two-column grid on phones, where the Dutch labels no
longer fit on one line.

Without JavaScript the roster falls back to the plain form — every player,
every field — so nothing is lost, it is just longer.

# TalentTrack v4.96.0 — Alerts: the bell now takes you where the number came from, and long-ignored alerts become tasks (#2635)

The notification bell counts your alerts as well as your tasks — it has done
since alerts shipped — but clicking it always landed on the task list. A coach
whose bell read "3" because of three unmarked activities arrived at an empty
inbox and reasonably concluded the bell was broken. It now takes you to
whichever list the count actually came from, and to the alerts list when it
is a tie, because that is the one that can show you everything.

Alerts that nobody deals with can now turn into real, assigned tasks. Set a
threshold per alert under Settings → Alert policy ("Turn into a task after
(days)"); leave it empty and nothing escalates, which is the default.

Two deliberate properties, because both are the sort of thing people expect
to work the other way:

- It happens **once**. An alert becomes a task one time, not once a day until
  somebody acts.
- It is **one-way**. Fixing the underlying thing clears the alert but does not
  close the task. A task carries somebody's name and a record of what
  happened; closing it behind their back would defeat the point of having
  made it a task. Close it from the task itself.

The bell's styling also moved out of the code and into the stylesheet, so it
follows your academy's theme instead of being hard-coded red.

# TalentTrack v4.96.0 — Alerts: two new data-quality alerts (#2636)

This release adds two alerts about records that are simply incomplete. They
are switched on from the moment you update, for everyone who can act on them,
so here is exactly what you will start seeing:

- **Player has no team** — an active player belongs to no team, a week or more
  after being added. A player with no team has no attendance, no minutes, no
  evaluation-coverage row, and no head coach receiving any of the other alerts
  about them; TalentTrack genuinely cannot say where they are. This one is
  quiet: it appears on the bell, not as a banner.
- **Team has no head coach** — a team with players has nobody assigned as head
  coach. Most alerts go to the head coach, so a team without one quietly stops
  receiving any of them. A coach whose assignment has an end date in the past
  does not count. Teams with no players are ignored, and so are trial groups.

Both go to whoever looks after the records rather than to a coach, because
there is no coach to send them to — that is the condition. And both are
treated as player data: an alert that names a child is only shown to someone
already allowed to see that child's record.

The one threshold is an academy setting,
`alerts_player_without_team_grace_days`. Assigning a squad is usually the next
step in the same sitting as adding the player, so a brand-new record does not
appear immediately.

This is the fifth instalment filling out the alert catalogue.

# TalentTrack v4.96.0 — Alerts: a new Onboarding alert (#2636)

This release adds one alert about invitations. It is switched on from the
moment you update, for everyone who can act on it, so here is exactly what you
will start seeing:

- **Invitation never accepted** — a player or staff invitation was sent a
  fortnight ago and nobody ever accepted it. Goes to whoever sent it, and for
  a player invitation also to the head coach of their team. Usually the email
  went to spam or to a mistyped address, and until now nothing anywhere said
  so: TalentTrack recorded the send and the acceptance, and the gap between
  them was invisible unless somebody thought to open the invitations list.

It does not fire for an invitation the system has already made redundant — a
player or staff member whose account was created directly by an admin leaves a
pending invitation behind, and chasing it would be chasing something already
done. Nor for parent invitations, which have their own alert.

The threshold is an academy setting, `alerts_invitation_stale_days`.

This completes the alert catalogue for now: activities, evaluations, goals and
PDP, people, measurements, data quality and onboarding.

# TalentTrack v4.96.0 — Lesson checks that actually check something (#2647)

Every module of the periodisation course has had five questions written for
it since the corpus shipped. None of them appeared anywhere. The payloads were
valid, the lessons declared they had a check, and there was no block on any
page to render one — so the sequential unlock waited on a quiz nobody could
take. The corpus lint now fails a PR where those two halves disagree, in
either direction.

The questions are live now, in four shapes: pick one, pick several, put a
sequence in order, and match two lists. Ordering uses a position box per item
rather than drag-and-drop — dragging is nicer with a mouse and unusable with a
keyboard, and typing a number is a real answer rather than a fallback bolted
beside a nicer one.

Marking happens on the server. That is not caution for its own sake: the file
that holds the questions also holds the answers, so anything that marked in
the browser would have to be given the answers first. The page a coach sees
with developer tools open is the page they see without them.

Options are shuffled every time a lesson is opened, which matters more than it
sounds. Every ordering and matching question in the course happens to be
stored in its correct sequence, so showing the options as filed would have
handed over the answer to all nine of them.

There is no partial credit and a skipped question counts as wrong. Half an
ordering is not half an understanding of a sequence, and a check you can pass
by answering only the questions you were sure of is not checking anything.

Every attempt is kept, passed or not — a coach who got there on the fourth try
has a different development record than one who got it first time, and the
head of academy reading that record should see both. Retakes are unlimited,
and the reason behind each answer is shown whether you got it right or wrong.

The whole thing is a plain form, so it still works with JavaScript switched
off; the script just saves you losing your place in a long lesson.

# TalentTrack v4.96.0 — Photo-capture DPIA: precise subject-access position (#2695)

Follow-up to the DPIA correction. The previous pass called the subject-access
position a "gap — either register the tables or state the limitation", which
offered an option that does not exist: `tt_activity_exercises` carries no player
identifier, and the export mechanism can only follow a column that joins to a
player.

The document now states the position exactly. Who attended a session **is**
covered, through `tt_attendance`. The extraction is not, and cannot be by that
mechanism. The real residual risk is narrower and sharper than "a gap": a player
name transcribed into the free-text `notes` column is reachable by neither a
subject-access export nor an erasure request, because erasing one player does not
delete a session that belongs to a whole team.

That is now prerequisite 7 in "Before this can be signed", with three ways to
close it: instruct the model not to transcribe names, strip them before save, or
accept and document the limitation.

# TalentTrack v4.96.0 — Photo capture will not send anything until you say where (#2695)

Photo-to-plan capture used to have a working default endpoint. An install that
had merely switched the feature on was already able to send photographs taken at
a youth academy to a destination nobody had consciously chosen — and the DPIA
said the opposite, that EU residency was enforced and that leaving it took a
deliberate opt-out.

**The default is gone.** Two settings are now required in `wp-config.php`, and
until both are present the feature reports itself unconfigured and nothing is
sent:

```php
define( 'TT_VISION_ENDPOINT',    'https://…' );          // where requests go
define( 'TT_VISION_DATA_REGION', 'EU (Frankfurt)' );     // where that processes them
```

Switching the feature on without declaring a destination now answers plainly that
nothing was sent and what an administrator needs to set, rather than reporting
that the photo could not be read.

This cannot verify a declaration — no plugin can tell whether an endpoint really
processes data where its operator says it does. What it guarantees is that the
destination is always a choice somebody made, which is the thing a DPIA can
honestly record. The declared region belongs in the signed document.

Two related corrections: the extraction prompt now tells the model to keep player
names in the structured attendance field rather than in free-text notes, where
neither a subject-access export nor an erasure request could reach them; and the
`TT_VISION_BEDROCK_*` settings, which were documented but never read by any code,
have been removed so nobody configures them believing they do something.

**If you already use this feature, it will stop working until you add the two
settings.** That is deliberate.

# TalentTrack v4.96.0 — Match analysis: write up a game per team function, and per player (#2704)

A match can now be reviewed in the app, not only planned and measured.
**Write the match analysis** appears on a match activity once it has been
played, and on the post-match sideline screen where the detail is still
fresh.

The review is structured in the academy's own methodology vocabulary: an
overall read, then a rating (Went well / Mixed / Needs work) plus up to four
short points for each of *Aanvallen*, *Omschakelen naar aanvallen*,
*Verdedigen*, *Omschakelen naar verdedigen* and set pieces. Where the match
plan asked for something in a phase, it is shown next to it — so the review
answers what was asked rather than what is remembered. An unrated section is
a valid answer and stays out of the record entirely.

Below the phases sits the roster: everyone who played, with their minutes.
Each row optionally takes a marker (Stood out / As expected / Below par), one
specific line about what the player did, and the phase it belongs to. Rows
left untouched persist nothing. Every note also lands on that player's own
timeline as *Observed in a match*, dated to the match and visible to staff —
which is what makes it a development record rather than a per-match document.
Rewriting a note updates the timeline entry; clearing it removes the entry
too.

The first draft is written through a five-step wizard; re-opening an existing
analysis goes straight to the page, because changing one line should not mean
walking five steps. Output is an A4 print (real text, so the PDF stays
selectable) and a signed staff share link that can be revoked and reissued in
one click, shutting every URL handed out before it.

Works with or without a match plan and with or without the live-match screen
— a game run off a paper team sheet gets the same analysis, with nothing to
pre-fill. Match-type activities only for now: a tournament day is several
games, and one analysis cannot say which of them it is about.

Both the module and its two outputs are switchable: an academy can keep the
review surface while turning the PDF export or the share links off.

# TalentTrack v4.96.0 — Photos and video now load, count and land where you expect (#2715, #2716, #2717)

Three defects found in the first live test of the media library, all fixed
together.

**Photos and video would not display (#2715).** Every thumbnail rendered as a
broken image. The files themselves were fine — stored, thumbnailed and stripped
of EXIF exactly as intended — but the browser was turned away at the door.
An `<img>` tag cannot send the `X-WP-Nonce` header the REST API expects, so
WordPress treated the request as coming from nobody at all and answered 401.
Media URLs now carry the nonce in the query string, which WordPress accepts as
equivalent. The session cookie is still required: a URL copied out of a page and
opened elsewhere is refused, so this does not turn a player's photo into a link
anyone can follow.

**Finishing the wizard dropped you on the site's front page (#2716).** The
"Add photos or video" wizard built its closing redirect on the site root rather
than the page that hosts the dashboard, so on any install where the dashboard is
not the front page the coach landed on the theme's homepage instead of the player
they had just added a photo to. The same three lines also pointed activity media
at a view that does not exist. Both now route through the shared link helper.

**The Media tab never showed a count (#2717).** Goals, Evaluations, Activities
and the rest all carry a number; Media was added to the tab strip without being
added to the counter behind it, so a player with photos showed a bare tab. The
badge now counts the same media the tab lists — club-scoped, archived items
excluded — so the two cannot disagree.

One limitation worth knowing: a nonce is valid for roughly a day. A gallery left
open in a tab longer than that will show broken thumbnails until the page is
reloaded.

# TalentTrack v4.95.0 — Draw an animated scene for a drill (#2501)

An exercise can now carry a **scene** — a small animated diagram of the drill,
with players, opponents, the ball, cones and goals on a pitch, and the
movements you want them to make. Open an exercise and press **Draw a scene**.

The editor is built around one gesture: drag a marker on the pitch and it
records where that marker is at the moment the playhead is on. Scrub to two
seconds, move the left-back forward, and the left-back now runs forward over
those two seconds. A timeline, a marker palette, a line tool (pass, dribble,
run, shot, press) and forty steps of undo are there for everything the drag
does not cover, and the arrow keys move a marker without a mouse.

A saved scene shows up in three places — the exercise page, the sideline view
while the training runs, and the printed A4 sheet. All three draw it with the
same code, so they cannot drift apart; on paper it becomes a still picture of
the scene's final frame, which is also what a reader who prefers reduced motion
sees on screen.

Scenes are stored per exercise and validated on the way in, so a diagram that
reaches the database is always one that renders. Coordinates off the pitch are
pulled back onto it, keyframes are sorted, and a line drawn to a player who has
since been deleted is dropped rather than left pointing at nothing.

Drawing works best on a tablet or a desktop. On a phone you can watch a scene
and move a marker, but the timeline wants more room than a phone has.

# TalentTrack v4.95.0 — Fixed: the Data browser tile stayed on the dashboard after switching the module off (#2599)

Switching the **Data browser** module off hid what it does but left its tile on the dashboard, pointing at a screen that no longer answered. The
tile now disappears with the module, as every other module's does.

Behind that, the switchability check that shipped alongside it has been taught something it was missing: a screen belonging to a module you can
already switch off does not also need a separate feature toggle. That removed 47 entries from the list of screens marked as needing a decision —
they never needed one — and left six that genuinely must always be on, each with the reason written down.

# TalentTrack v4.95.0 — Five modules now show their real names on the Modules page (#2599)

Strava, Training plans, Measurements & testing, Data browser and Knowledge library were listed on the Modules page under a slugified class
name instead of a proper label and description. They now read like the other modules do.

The rest of this change is a build-time check with no visible effect: TalentTrack now refuses to ship a module or a screen that an academy cannot
switch off, unless somebody has written down why it must always be on. The switching itself has always worked — what was missing was anything that
noticed when a new one arrived without a toggle.

# TalentTrack v4.95.0 — Alerts: TalentTrack now tells you when your data needs attention (#2631)

A new Alerts engine surfaces conditions that are true right now and need
someone to act — an activity whose date has passed but is still marked as
planned, a completed activity with nobody's attendance recorded, an activity
next week with no coach assigned. Alerts appear in a banner at the top of
the dashboard and are counted by the notification bell alongside open tasks.

Alerts are deliberately not tasks. You never mark one as done: you fix the
thing it points at and it clears itself on the next background check. That
is the whole reason for a separate engine — modelling "this activity is still
planned" as a task would leave a stale task in someone's inbox every time a
coach fixed the activity in the activities list.

Alerts go to the people who can fix them: the coach assigned to the activity
and the team's head coach. Heads of Development do not receive one per team;
an aggregate view for that role comes later. Whether a recipient may see an
alert is re-checked on every sweep, so a coach who moves off a team stops
receiving that team's alerts without anyone having to remember.

Conditions are re-checked hourly in the background rather than while your
dashboard loads, so adding alert types can never slow down signing in. The
trade-off is that an alert can linger for up to an hour after you fix the
underlying thing. A fresh install runs one check on activation so the first
dashboard load shows a true picture.

This is the foundation only. Per-person and per-club settings for which
alerts you see and where, contextual chips on list and detail views, email
digests, and the rest of the alert catalogue all build on top of it.

# TalentTrack v4.95.0 — Alerts: choose which ones you see, and where (#2632)

Alerts now have settings. **Account → Alert settings** lists every alert with
a tick per place it can appear — in the bell, as a banner on the dashboard —
so a coach who wants unmarked activities counted but not announced can have
exactly that.

Alerts you cannot change are shown greyed out with the reason rather than
hidden. A settings list that quietly omits what you cannot change teaches you
the list is complete when it is not.

Two new controls for administrators, under **Settings → Alert policy**. Each
alert can be left to the individual (the default), forced on for everyone, or
switched off for the whole club — except alerts concerning a child's safety,
which cannot be switched off at all. Switching one off also clears the alerts
it has already raised, rather than leaving rows stored where nobody can see
them. Administrators can also require an alert to be acknowledged before the
page continues, and set how long an ignored alert waits before it becomes a
real assigned task.

Individual alerts can now be snoozed for a day, a week or a month, or
dismissed outright. Dismissing removes that occurrence only: if the same
problem is fixed and then happens again, the alert comes back, because that
is genuinely new information. To stop a whole category, untick it in Alert
settings.

Message preferences — what the academy emails or pushes to you — stay on
their own screen under Account → Settings, and the two screens link to each
other. They govern different things: one is what gets sent to you, the other
is what the app surfaces about your own data.

# TalentTrack v4.95.0 — Alerts wave 3: alerts appear on the records they are about (#2633)

Alerts now surface where the fix happens, not only in a banner. A compact
severity chip appears on any activity in the activities list, on the
activity's own page, on a team's page, and on a player's record — a count,
a word, and a link into the new alerts list scoped to that record. The chip
carries its meaning in text as well as colour, works without hover, and
stays a 48x48 target on a phone.

Two rules hold the design together. The chip is the one alert surface a
person cannot mute: it is not a notification, it is the record's own current
state drawn next to the record, and hiding it would hide a row's real
condition from whoever is looking straight at that row. And on a player's
record only OPEN alerts are ever shown — resolved ones are gone, and nothing
about an alert is written into the player's journey. The journey records
what happened to the player; an alert records what staff did not get round
to entering, and at a 90-day retention a journey entry would vanish
retroactively anyway.

A new **Alerts** list at `?tt_view=alerts` carries the whole set with
area / severity / state filters, and is where every chip deep-links to.

Heads of Development and academy admins get the counterpart they were
promised: a per-team summary at the top of that list ("4 teams have records
that need attention"), read as a grouped query over the alerts that already
exist. No occurrence is written for oversight users, so the "no alert per
team for the person with the least time to read them" rule stays intact.
The summary is scoped to the teams the viewer already oversees and counts
each affected record once, even when two coaches were both told about it.

Rendering chips on a fifty-row list costs one database query for the whole
page. `GET /alerts` gained `subject_type` / `subject_id` / `player_id` /
`state` filters and `GET /alerts/rollup` returns the per-team summary, so a
non-WordPress front end can draw the same chip.

# TalentTrack v4.95.0 — Alerts: optional summary email, and a 90-day retention window (#2634)

Alerts can now reach you by email. If you do not open TalentTrack often, tick
**In the summary email** against the alerts you care about in Account → Alert
settings and your open ones arrive as a single message.

It is off until you turn it on. Nobody is signed up by this release: the app
will show you alerts in the bell and on the dashboard, but it will not put
mail in your inbox until you ask it to.

The summary will not repeat itself. An alert stays open until the underlying
thing is fixed, so without this you would receive the same items every
morning; anything already mailed, read, snoozed or dismissed is left out, and
when there is nothing to report no email is sent at all. Each line links
straight to the record that needs attention rather than to a list.

Cleared alerts are now kept for 90 days and then deleted. Alerts still open
are never deleted however old they are — one nobody has dealt with for a year
is worth seeing, not tidying away. The trade-off is that the alerts system
cannot answer questions spanning more than about a quarter; for season-long
patterns use Reports, which reads the underlying records.

# TalentTrack v4.95.0 — Alerts: three new Evaluations alerts (#2636)

This release adds three alerts about evaluations. They are switched on from
the moment you update, for everyone who can act on them, so here is exactly
what you will start seeing:

- **Player not evaluated recently** — nobody has recorded an evaluation for a
  player for longer than your academy's threshold (eight weeks out of the box).
  Goes to the head coach of that player's team. A player who has never been
  evaluated is counted from the day they joined, so a trialist who arrived on
  Tuesday will not appear.
- **Evaluation window closing** — an evaluation window is within three days of
  closing and players in your team have no evaluation in it. Goes to the head
  coach. It stops the moment the window closes: a gap nobody can still fill is
  not something worth nagging about.
- **Evaluation not shared with the player** — an evaluation was recorded but
  the player-facing feedback field was left empty, so the player and their
  parents see nothing. Goes to the coach who wrote it and to the team's head
  coach, from a week after the evaluation until sixty days after it.

All four thresholds are academy settings rather than fixed numbers, because
an academy that evaluates every block and one that evaluates twice a season
disagree about what "recently" means: `alerts_eval_stale_weeks`,
`alerts_eval_window_closing_days`, `alerts_eval_share_grace_days` and
`alerts_eval_share_lookback_days`.

As with every alert, you never mark these done. Record the evaluation, or add
the feedback, and the alert clears itself at the next hourly check. You only
receive one about a player you already have permission to see.

This is the first of several instalments that fill out the alert catalogue.
They ship one module at a time, and each release names the alerts it adds —
a release that quietly changed twelve things the app nags about would be an
ambush rather than an improvement.

# TalentTrack v4.95.0 — Alerts: two new Goals and PDP alerts (#2636)

This release adds two alerts about a player's development plan. They are
switched on from the moment you update, for everyone who can act on them, so
here is exactly what you will start seeing:

- **Goal past its target date** — a development goal has passed the date it
  was aimed at and is still open. Goes to whoever set the goal and to the head
  coach of the player's team, from three days after the date until a year
  after it. Either the player got there and nobody recorded it, or the plan
  needs changing; both are answers, leaving the goal untouched is not.
- **No PDP conversation this cycle** — a player's PDP file for this season is
  open but no conversation has actually been held. Goes to the coach who owns
  the file and to the team's head coach, from 45 days after the file was
  opened. Conversations that were scheduled but never held do not count: a
  cycle is created with all of its conversation rows already written, so
  counting rows would mean the alert could never appear.

Both thresholds are academy settings rather than fixed numbers:
`alerts_goal_overdue_grace_days`, `alerts_goal_overdue_lookback_days` and
`alerts_pdp_no_conversation_days`.

Only the current season's PDP cycles are considered. Last season's untouched
cycle is history, not a gap anyone can still close.

This is the second instalment filling out the alert catalogue, after the
Evaluations alerts. They ship one module at a time so that every release can
tell you what it added.

# TalentTrack v4.95.0 — Alerts: a new Measurements alert (#2636)

This release adds one alert about the testing battery. It is switched on from
the moment you update, for everyone who can act on it, so here is exactly
what you will start seeing:

- **No measurement this season** — a player has nothing recorded in the
  current season's testing battery. Goes to the head coach of their team,
  from 60 days into the season. Growth data is the only part of a player's
  record that is not somebody's opinion, and a season with no measurement
  leaves a permanent hole in the curve: you cannot fill it later, because the
  player has already grown.

The question is "this season", not "recently": a measurement taken before the
current season started does not count, because the academy's testing battery
runs on a season rhythm. The current season is the one marked as current in
your season settings; if none is marked, the alert stays quiet.

The threshold is an academy setting, `alerts_measurement_grace_days`. In week
one of a season this alert would fire for every player in the academy at once,
which is indistinguishable from saying nothing.

You only receive it if you already have access to measurements — the alert
names a player and says what is missing from their record, so it is gated the
same way the measurement screens are.

This is the fourth instalment filling out the alert catalogue.

# TalentTrack v4.95.0 — Alerts: three new People alerts (#2636)

This release adds three alerts about the people around a player. They are
switched on from the moment you update, for everyone who can act on them, so
here is exactly what you will start seeing:

- **Player turns 18 soon** — a player's eighteenth birthday is within 30 days.
  Goes to the head coach of their team. Turning eighteen changes the paperwork
  rather than the football: parental consent stops being the basis for holding
  their data, a youth agreement may need to become a contract, and the parent
  account's access becomes a decision rather than a default.
- **Parent invited but never activated** — a parent was invited more than a
  fortnight ago, never created their account, and the player still has no
  parent linked at all. Goes to whoever sent the invitation and to the head
  coach. A parent who was invited twice and accepted the other invitation, or
  who an admin linked directly, does not trigger it.
- **Certificate expiring** — one of your own certificates is within 60 days of
  expiring, or expired inside the last 60 days. This one goes **only to the
  person whose certificate it is**: that is somebody's professional record,
  not squad information. Already-expired certificates are included on purpose;
  dropping them would make the alert vanish exactly when the problem becomes
  real.

Thresholds are academy settings: `alerts_player_turns_18_days`,
`alerts_parent_invite_stale_days` and `alerts_staff_cert_expiring_days`. The
age of majority itself is not a setting — it is a fact about the jurisdiction
the academy operates in, not a preference.

Parent invitations are covered here; player and staff invitations get their
own alert in a later instalment, so nobody is told the same thing twice.

This is the third instalment filling out the alert catalogue, after
Evaluations and Goals/PDP.

# TalentTrack v4.95.0 — Knowledge library: the system now remembers where you got to (#2644)

Courses have shipped as files since #2642 and been readable since #2643. This
adds the half a file cannot carry: who is on which course, how far they got,
and what they still owe.

Four tables behind one rule. A lesson is finished when everything its front
matter asks for has happened — read it, pass the quiz if it has one, get the
assignment approved if it has one — and a course is finished when all its
lessons are. That rule lives in one service, because the reader, the
lesson-unlock gate and the completion report all have to give the same answer
and there is no version of this where they may disagree.

Two consequences worth knowing. Requirements are read from the course files
every time rather than frozen at enrolment, so revising a course to add a
lesson reopens the people who finished the old version instead of leaving
them certified for work they have not done. And completion is reversible: a
reviewer who withdraws an approval drops the enrolment back to in-progress,
because a certification standing on a verdict that no longer stands is worse
than no certification.

Quiz attempts are kept in full, not collapsed to the latest. A coach who
passed on the fourth attempt has a different development record than one who
passed first time, and that is exactly what a head of academy reading the
record wants to see.

Three capabilities rather than the usual two: a coach can see their own
progress without seeing their colleagues', because the roll-up is a separate
grant. Assignment attachments ride the media library rather than growing a
second upload path.

Demo data covers all four tables with a deliberately mixed cohort — some
finished, some mid-course, one overdue, one assignment waiting in the review
queue — so the completion report has something real to render before anyone
has used the feature.

Still not readable in the app: the reader view is #2646.

# TalentTrack v4.95.0 — One gate for what an install can show you (#2645)

Two corpora in the plugin carry the same four keys — `module`, `feature`,
`tier`, `capability` — and both need the same question answered: can this
install, and this reader, have this? The help topics under `docs/` and the
courses under `courses/` were about to grow two separate answers to that,
which would have drifted the first time anyone added a fifth gate or fixed a
bug in one of them.

`ContentGate` is now the single resolver, in shared space so neither module
owns it. Courses consume it today; the help corpus consumes it when its own
gating work lands.

The verdict it returns is not a boolean, because the three ways content can
be out of reach are not interchangeable to the person in front of it.
**Unavailable** means this install does not have it and no permission changes
that. **Denied** means it is here and somebody else can see it. **Locked**
means you will be able to, once you have done something first. Showing the
same message for all three is how a product ends up telling a head of academy
to ask their administrator about a feature their licence does not include.

On top of that, courses gain the two gates that are about the learner rather
than the install: a course can require another course first, and a sequential
course opens one lesson at a time.

Two decisions worth knowing. Content this install cannot have is **absent**
and returns a 404, not a 403 — a 403 confirms the thing exists here, which is
what hiding it was for. And locked content stays **listed**, because hiding a
locked lesson makes a course look shorter than it is and nobody can work
towards something they cannot see.

The gate is enforced where it can actually be walked around: submitting
progress for a locked lesson is refused, not just hidden in the reader.

An unknown key value leaves content visible rather than hiding it. A typo in a
feature name silently removing a topic is a bug found months later, if ever;
the corpus lints are what catch the typo.

# TalentTrack v4.95.0 — The knowledge library is now something you can actually open (#2646)

Three ships have built a course library nobody could read: the corpus, the
interactive blocks, the progress tables and the gating. This is the front of
it.

Four surfaces. A **library** listing the courses this reader may see, ordered
so the work in front of them comes first — in progress, then not started, then
locked, then finished, because a library that sorts alphabetically makes a
coach hunt for the course they are halfway through. A **course page** with the
lesson list, what finishing asks of them, and one button back to wherever they
stopped. The **reader** itself. And **My learning**, which sits beside My PDP
and My certifications because that is what it is: the training half of a
coach's own development.

Opening a course goes to the first lesson you have not finished, not lesson
one. Opening a lesson enrols you — a separate "enrol" step before you can read
lesson one is a step nobody would understand.

Marking a lesson read is a button, never a scroll measurement. A coach who
skims and clicks it has made a claim; a scroll listener has only measured a
thumb. It posts a real form, so the lesson still completes with JavaScript
switched off. The end of each lesson also says what it still wants — a passed
check, an approved assignment — because someone who marks a lesson read and
sees the course percentage refuse to move needs to know it is the quiz and not
a bug.

The zero-point measurement a coach takes in module 4 is still there in module
11, where the final assignment asks for it. Same for a week plan: the tools
now remember what you put in them.

Locked lessons stay in the list with the reason attached, rather than being
hidden. Hiding them would make a course look shorter than it is, and nobody
can work towards something they cannot see.

Everything here is switchable: all four routes belong to the courses feature,
so turning it off takes the URLs down as well as the tiles rather than leaving
a bookmarked lesson rendering a surface the academy switched off.

# TalentTrack v4.95.0 — How long a player's photos are kept, and who decides (#2666)

An academy asked "how long do you keep photos of my child?" now has an answer the product supports.

Media belonging to a player who has left is kept for a set period — **three years by default**, adjustable from one to ten years under
Configuration, or **Keep indefinitely** if you would rather decide case by case. When the period passes, the media appears under the new **Media
retention** screen.

**Nothing is ever deleted automatically.** The period starts a review, not a deletion. That is why a default could be shipped safely: upgrading
finds you a list to work through, never gaps in your records.

Two details worth knowing:

The clock starts when the player leaves, not when the photo was taken. A player still at the academy keeps their whole file however old — the
picture of the same player at 12 and at 18 is the point, and a period measured from the photo's own date would quietly delete the beginning of it.

Expiry applies to one player's link rather than the whole photo. A team photo showing someone who left comes off *their* file and stays on the
team, on the training, and on the other players in it. Only when nothing is left pointing at a file is the file itself deleted — and the screen
tells you which of the two just happened.

Each item can be **Kept** instead, with a reason: a safeguarding matter, an open dispute. Those are listed separately with their reasons, because a
retention policy with an invisible list of exceptions is not one anyone can check.

# TalentTrack v4.95.0 — Scene editor: correct Dutch for the line types (#2687)

The scene editor's line picker offered a Dutch coach **Geslaagd** for *Pass* and
**Uitvoeren** for *Run* — "passed" as in a test result, and "execute" as in
running a program. Both are now right: **Pass** and **Loopactie**.

`Pass` and `Run` are single English words, and the catalogue already held them
from unrelated parts of the product. Gettext returns whichever translation was
registered first, so the picker inherited a meaning from somewhere else entirely
with nothing to show for it — the English read fine and the catalogue looked
complete.

The whole diagram vocabulary — the six markers, the five line types and the four
pitch presets — is now translated under its own context, so none of these words
can pick up a sense from elsewhere, and a word added to the set later cannot
either.

# TalentTrack v4.95.0 — Photo-capture DPIA corrected against the code (#2695)

`docs/photo-capture-dpia.md` is the document an academy signs before photographs
taken at a youth academy are sent to a vision model. An audit against the shipped
code found that several of its technical assertions described safeguards that do
not exist, so the document has been rewritten to describe what the code actually
does, and now carries a prominent **not ready for signature** banner listing what
must be settled first.

The correction that matters most: the feature does **not** route to an EU-resident
endpoint by default. The document previously said it did, and that breaking that
required a deliberate opt-out. In fact the default is Anthropic's direct API,
there is no AWS Bedrock code path at all, and an operator-supplied endpoint
override is not validated.

Corrected in the safer direction: the uploaded photograph is never written to
disk. The document described a seven-day retention and a cron sweep; neither
exists, because there is nothing stored to sweep.

Also corrected: the structured extraction is **not** currently included in the
GDPR subject-access export, contrary to what the document claimed.

No behaviour changed in this release — the feature remains off by default behind
the `exercises_vision_extraction` flag. If you have already signed a copy of this
DPIA, re-read it: the version you signed misdescribed where photographs go.

# TalentTrack v4.94.1 — Test trends: numbers first, and a colour per player (#2670)

The Test trends report led with a chart in which every player's line was
drawn in the same colour — a full squad overlapped into one navy band that
no reader could trace a single player through, and nothing connected a line
to a row in the table underneath it.

The report now opens with the values: the player table, then Most improved
and Fallen back, then the chart as the summary of what they already said.
Each player's line is thinner and carries its own colour, and the same
colour appears as a short line in front of their name in the table and in
both ranking lists. Past ten players the palette reuses a colour with a
dashed and then a dotted line, so a large squad stays identifiable in
colour, in greyscale print, and for a colour-blind reader.

Presentation only — the same figures, the same
`GET /reports/test-trends` payload. Status, pass/fail and directionless
tests are untouched; they have no chart to key.

# TalentTrack v4.94.0 — What has this player actually been taught? (#2500)

The question the Training module was built to answer.

**A Training tab on every player file.** Minutes trained per principle, how
many of your methodology's principles they have touched, and when they last
trained — drawn from the trainings they actually attended.

**The principles they have never trained are listed too, at the top, marked.**
That is the whole point. A list that quietly dropped the empty rows would look
complete while hiding exactly what you opened the tab to find.

The minutes are honest about what happened rather than what was planned.
Present and late count; excused, absent and injured do not. A skipped block
contributes nothing. A block that ran twenty-seven minutes contributes
twenty-seven, not the twenty-two someone typed into the plan. A player who
guested for another team carries those minutes on their own file.

**Notes on players, from the touchline.** The sideline view now lists everyone
who is there with your academy's own scale under each name and a box for a
note. You do not have to score anyone — a note on its own is a complete
observation, and on a wet Tuesday it is the usual one. Tap a number again to
clear it. A score outside your configured range is refused rather than rounded
into it, because a rounded number on a child's record is one nobody chose.

Each note lands on that player's **Journey** straight away, dated to the
training rather than to when you typed it up.

**A coverage matrix for the head of development.** Every principle down the
side, every team across the top, and how many trainings each has spent on
each. Only "never" is marked: four shades of nearly-fine would bury the one
thing worth acting on.

**Who sees it.** Coaches for their own teams, head of development and academy
admins for everyone, and a parent for their own child. A player can switch it
off for their parent entirely, under *My settings → what your parent can see*,
alongside evaluations, goals, measurements and their PDP — training history is
a ledger of what a young person has and has not been taught, and belongs in
the same bracket as the rest.

**When it updates.** Immediately when a training is finished, for the players
who were there, and fully every night — which is what picks up a plan edited
after the fact, an exercise re-tagged with a different principle, or
attendance corrected the next morning.

**Demo academies** now carry observations as well as plans and runs:
deliberately sparse and mostly unscored, because a demo where every player has
a tidy 7 would teach the wrong idea about what this is for.

# TalentTrack v4.94.0 — MFA: a correct code no longer lands on a blank page (#2668)

Entering a valid authenticator or backup code on the two-factor challenge
could leave the user on an empty screen, still parked on the challenge URL,
with no way forward but editing the address bar. The code itself was always
accepted — only the hop to the dashboard failed.

The challenge page renders inside the dashboard shortcode, so by the time it
ran the response headers had already gone out; the post-verify redirect was
silently dropped and the `exit` behind it truncated the page. Reloading made
it worse: with the challenge now cleared, the same unguarded redirect fired
again from a second code path.

Verification, rate limiting, audit logging and the "remember this device"
cookie now resolve on `init`, before a byte of the page is written, so the
redirect is a real one. The view renders the form, the error and the lockout
countdown and nothing else. The two bounce-out cases — no challenge
outstanding, or a pending challenge on an un-enrolled account — go the same
way, and every remaining path carries a card with a link out rather than a
blank screen.

# TalentTrack v4.94.0 — Injuries overview: record an injury without opening a player file first (#2671)

The squad-level Injuries page was read-only by omission — the injury wizard
existed and was registered, but only the player file linked to it, so a coach
who opened the overview to see who was out had no way in from there. The page
now carries a **Record injury** action in its header, and the "Nobody is
currently out injured" state carries the same call to action instead of a bare
notice.

Entering from the overview starts the wizard on its team → player step, which
is scoped to the squads the coach holds. The button follows the same gate as
the player file: it is absent for roles without `tt_edit_player_medical` and
when wizards are switched off, because there is no flat-form path to fall back
on. The docs already described this entry point; the code has caught up.

# TalentTrack v4.94.0 — Fixed: uploading a photo failed with an error (#2674)

Adding a photo to a player, team or training failed — the file showed "Could not be added" and nothing was saved. Uploading video, or pasting a
video link, was unaffected, which is why the problem looked intermittent.

The cause was a thumbnail step that used a WordPress function only available inside the admin screens, so it broke as soon as the upload came from
the media wizard. Photo uploads work again, and nothing was lost: the failure happened before anything was written, so no half-saved photos or
stray files were left behind.

# TalentTrack v4.93.0 — Change a plan block by block, and see who it serves (#2498)

A training plan is no longer read-only. Open one and press **Edit blocks**.

**Reorder** with the ↑ and ↓ buttons on every block. They are the normal
control, on a phone and on a desktop alike, and they work from the keyboard —
tab to one and press Enter. On a wide screen you can drag a block by its
handle instead, but you never have to. Nothing is written until you press
Save, so rearranging costs nothing until you commit to it.

**Change a block's length** with − and +, in five-minute steps. The time strip
and the running total update as you go, so you see the shape of the session
change instead of doing the arithmetic.

**Swap an exercise** from your library — a sheet that slides up under your
thumb on a phone, a panel bottom-right on a desktop. The list is sorted by how
many of that team's open player goals each exercise would serve, and every row
carries its number, so you can see why one drill is above another rather than
having to trust the order.

**Add and remove blocks**, and write coaching points on each one. A block with
no exercise is allowed: a team talk has no drill behind it.

**The panel that makes it worth doing**: beside the blocks, the players this
plan actually works on, listed by name — and underneath, the players with an
open goal the plan misses, also by name. That second list is the one you can
do something about before Tuesday. It updates on every save, so you can swap a
block and see who it gained or lost.

**Reuse a plan** two ways. *Save as club template* makes a club-wide copy with
no team on it, so a session that worked becomes a starting shape anyone can
build from. *Copy to a new plan* makes an independent copy for the same team —
the quickest route to next week. Both copy the saved plan and say so if you
have unsaved changes; a copy never changes the plan it came from.

**Demo academies now have training history.** A generated demo academy used to
open the Training module to an empty list, which told the module's story
badly. It now comes with a plan per team per fortnight — themed, built from
the library the demo installs, with the principle links that make the coverage
panel and the coming exposure report mean something.

# TalentTrack v4.93.0 — Run a plan: on the pitch, and on paper (#2499)

A training plan stops being a document and becomes a training.

**Attach a plan** from the training in your calendar — **Run this training**,
pick the plan, done. The plan is copied onto the training as it is at that
moment, so editing it afterwards never changes what the training recorded. If
a plan is already attached the button says **Open the session** and takes you
there; attaching twice is not an error and never replaces the first copy.

**The sideline view** is the screen you hold on the pitch, and it is built for
that rather than for a desk: dark, one block at a time, big controls at the
bottom where your thumb already is.

- The timer counts up against the block's planned length. Nothing advances by
  itself — you decide when a block is done.
- Running over is a state, not a telling-off. The screen says how far over you
  are and what the block will be recorded as if you finish now, and lets you
  carry on.
- Finishing a block records how long it actually ran. Skipping one records
  that it did not happen — on this training, never on the plan, which is
  waiting unchanged for the next team that uses it.
- At the end: minutes trained against minutes planned, blocks run, blocks
  skipped.

The sideline view needs a connection. Lose signal mid-session and it tells you
the write failed rather than pretending it saved; working offline is coming
separately.

**The paper version.** Press **Print** on a plan for an A4 sheet: every block
with its start time, length, organisation and coaching points, on one page for
a normal session. If a player on that team has a growth-spurt ceiling below
the hardest block in the plan, the sheet names them — the person holding the
paper is the person who has to act on it.

**Demo academies now have a training history.** Generated academies come with
runs against their past trainings, including the blocks that ran long and the
cool-downs that got skipped, because a run record where everything went
exactly to plan teaches nobody why the record exists.

# TalentTrack v4.93.0 — Media library: foundation for photos and video on the player record (#2590)

Groundwork for attaching photos and video to players, teams and activities. This release ships the storage and data model, not yet the screens —
a new **Media library** module appears in Modules, switchable on or off like any other, and does nothing visible until the upload and gallery
surfaces land.

Files are deliberately kept out of the WordPress media library, whose addresses are public and cannot be withdrawn. Media is stored in a private
folder under randomly-generated names, with every request for a file checked by TalentTrack before any bytes are sent. Photos have their embedded
information — including the location a phone records at a training ground — read for the capture date and then stripped before storage. File types
are decided by the file's own content rather than its name, and SVG is refused outright.

Permanently deleting a player now also deletes their media. A photo attached only to that player is removed along with its file; media also
attached to a team or an activity is kept, because those records still point at it. Previously a polymorphic attachment like this would have been
missed by the deletion sweep, leaving photographs on disk after an erasure request.

Two known limits, both documented on the Media library page: video files keep their own embedded data, because stripping it needs tooling the
plugin does not ship — use a Veo, Hudl, YouTube or Vimeo link to keep footage off the server. And the folder-level block on direct web access
works on Apache but not on nginx, where TalentTrack's own permission check is the boundary.

# TalentTrack v4.93.0 — Media library: who may see a player's photographs (#2591)

The permission model for the media library. Nothing is visible in the interface yet — the screens land in later releases — but the rule that
decides who may see a photograph is now in place and enforced from a single point, so every future media screen inherits the same answer.

Staff see the media of players they are responsible for, following the same team scoping as the rest of a player's record. The player, and the
player's parent or guardian, see that player's own media. A scout reads media only for players they are actually linked to, not academy-wide —
the same narrowing that applies to evaluations, because a photograph of a child is at least as sensitive as a written judgment about them. A team
manager reads but does not upload or delete.

One consequence academies should know about: a photo or clip attached to several players is visible to all of their families. Team sport is
photographed in groups, and the alternative would hide nearly every training photo from everyone. Make sure your consent wording says so — the
Media library page explains it in full.

Existing installs pick up the new permissions automatically; no admin action is needed.

# TalentTrack v4.93.0 — Media library: the API behind photos and video (#2592)

The media library's REST surface: uploading, listing, editing, attaching to more than one record, and serving the files themselves. Still nothing
to click — the screens follow — but everything the feature will do is now reachable and permission-checked.

Photos and video are served only through TalentTrack, which checks who is asking before it sends a single byte. Video supports seeking, so a clip
can be scrubbed on a phone rather than downloaded whole. Anything TalentTrack does not recognise as a safe image or video is offered as a download
rather than displayed, and nothing served this way is stored in a shared cache.

Asking for a photo you are not entitled to see returns "not found" rather than "not allowed". That is deliberate: "not allowed" would confirm the
item exists in this academy, which is the one thing worth hiding from someone guessing.

Pasting a link to a video hosted elsewhere works for Veo, Hudl, YouTube and Vimeo. For YouTube and Vimeo the title and thumbnail are fetched
automatically, and the thumbnail is copied into the academy's own storage so viewing a clip does not tell the video provider which coach looked at
which player. Links to anywhere else are stored exactly as pasted, with a title you type — TalentTrack never contacts an address it does not
recognise.

# TalentTrack v4.93.0 — Media library: adding photos and video (#2593)

The **Add media** wizard, and the upload control behind it. Four steps: who it is for, the files, the details, and a confirmation.

Uploads are saved as soon as they finish, before the last step — so a dropped connection or a closed tab never costs you a file you already
waited for. Leaving halfway means the photos are on the record already, just without a title you can add later.

Each file shows its own progress and can be cancelled individually without disturbing the others. On a phone the camera is one tap from the
drop zone, and the largest file the server accepts is shown before you pick one rather than after the upload fails.

Video gets a thumbnail without any extra software on the server: the browser takes a frame from the clip as it uploads.

Pasting a video link works for Veo, Hudl, YouTube and Vimeo. For YouTube and Vimeo the title and thumbnail are filled in automatically; anything
else is saved as a plain link with a title you type.

The capture date is what decides where media sits on a player's timeline, so the wizard asks for the day of the training or match rather than
assuming the day of upload — and fills it in from the photo when the photo carries one.

# TalentTrack v4.93.0 — Media library: a player's photos and video, on their profile (#2594)

The first place the media library is actually visible. A **Media** tab on the player profile shows that player's photos and video, beside
Evaluations and Injuries — because a rating is a number and the clip behind it is the evidence.

The tab only appears for people whose permissions reach that player's media, and every item is checked again on the way out, so a photo attached
to a player a coach cannot see never reaches the page.

Media is ordered by when it was taken, newest first, rather than by upload time. Emptying a camera roll in November does not push August's training
above it — which is the difference between a story and a folder.

Tap to view full size, arrow keys to move between items, Escape to close. Video only starts loading when you play it, so opening the tab on a phone
costs nothing until you ask for something.

Photos and video can be added straight from the tab, and **Remove** deletes the file permanently rather than archiving it — offered only to people
who may edit that player's media.

# TalentTrack v4.93.0 — Media library: team and training media, and tagging who is in a photo (#2595)

Media now has a home on teams and on trainings, not only on players.

A team page gains a **Media** section for squad photos, tournament days and end-of-season moments. It shows what belongs to the team itself —
media of an individual player stays on their profile — and can be switched off for your own view like the other sections there.

A training or match gains its own **Media** section, and that is where the useful part lives: **Tag players**. Tick the players who are in a photo
and it appears on their profiles too, from a single upload. Each tick saves as you make it, with no Save button to forget, and reverts if it
cannot be stored. Untagging one player removes it from that player only — the photo stays on the training and on everyone else tagged.

The roster offered is the team that was actually training, so it is a short list of the people who were plausibly there rather than the whole
academy.

Worth knowing: a photo tagged to three players is visible to all three families. That is the shared-visibility policy the Media library page
describes, made concrete.

# TalentTrack v4.93.0 — Media library: demo content, storage visibility, and the off-switch verified (#2596)

The last of the media library work — the parts that make it a finished feature rather than a set of screens.

A demo academy now has media in it: a squad photo per team, a few player portraits and one external video link, so the media tabs show what they
are for instead of sitting empty. The placeholder images are drawn when the demo data is generated rather than shipped with the plugin, and nothing
is fetched over the internet, so generating a demo academy works offline.

Once an academy has media stored, the total appears as **Media stored** on the academy admin's system-health strip. Uploaded video is not small
and nothing reclaims it automatically, so the number belongs somewhere an admin already looks. There is no automatic clean-up: deciding when old
media should go is a policy question, not one to guess at.

The go-live runbook gains a media section covering the checks worth doing before an academy starts uploading — including the one that matters on
nginx servers, where the folder-level block does nothing and TalentTrack's own permission check is the only thing protecting a child's photograph.

Switching media off is now verified end to end. Turning the module off makes the whole feature unreachable; turning the feature off hides the
screens and keeps the files. Neither deletes anything, and switching back on restores exactly what was there — so an operator can try it.

# TalentTrack v4.93.0 — Messaging never fails silently any more (#2602)

A message the academy sends to a family could previously disappear without a
trace. A send whose recipient list resolved to nobody — a team with no linked
parents, say — looked exactly like a successful one, and a message naming a
template that was not registered left no record anywhere. Both now write to the
message log like any other outcome, so "was this family told?" always has an
answer.

The daily reminder run records whether each of its four checks actually ran and
whether it errored. A check that has been failing for months used to be
indistinguishable from one that simply had nothing to send.

Messaging that a person triggers can now report back per recipient — who it
reached, who has opted out, who has no usable contact details — instead of a
flat "sent". A new dry-run pass evaluates opted-out recipients, quiet hours,
sending limits and reachability *before* anything is sent, so a screen can warn
first. The surfaces that use both land with the message log and the send flows.

# TalentTrack v4.93.0 — Choose which messages your academy sends, and which ones you receive (#2603)

**Configuration → Messages** is a new screen listing every message TalentTrack can send — cancellations, selection decisions, nudges, reminders, letters — with a switch beside each one. Switching a message off stops it for everyone on every channel. It is still recorded in the message log as *switched off*, so you can always see that a message would have gone out and did not.

Previously the only lever was the whole Scheduled messaging feature: an academy that did not want goal nudges lost attendance flags, onboarding nudges and staff-development reminders along with them.

**My settings → Messages you receive** lets each person choose what the academy may send them, per message type. This was required all along and had no screen: the preference was read when sending and could not be set by anyone. Everything stays on until you change it. Safeguarding messages, and messages about getting back into your account, are shown as always-on and cannot be switched off.

The **SMS channel now defaults to off** on new installs. TalentTrack does not send SMS by itself — it needs a provider plugin — so leaving it on advertised a channel that failed every send. Installs that switched it on keep their setting.

# TalentTrack v4.93.0 — In-product notifications now follow your messaging rules (#2604)

Notifications raised inside TalentTrack — a task assigned to you, a reply on a conversation, a trial reminder — used to send email straight out, ignoring every rule the academy had set. They did not appear in the message log, they ignored quiet hours and the sending limit, and there was no way to refuse them.

They now go the same way as every other message. That means an academy can see them in the message log, they are held during quiet hours instead of arriving late at night, and **My settings → Messages you receive** has a new line for them: *Notifications about your tasks and conversations*.

A notification held back for one of those reasons is not treated as a delivery failure any more, so it is no longer retried on another channel or reported as undelivered — the message log records exactly what happened to it.

# TalentTrack v4.93.0 — Change a coach's role on a team without losing the assignment (#2608)

Functional roles → Assignments now has an **Edit** action. Promoting an assistant
coach to head coach — or the reverse — used to mean unassigning the line and
building a new one from scratch, which silently discarded the original start date.
Editing changes the role in place and keeps the assignment's history.

The change is more than cosmetic: it rewrites the person's head-coach flag on that
team, so the coach lands on the right persona dashboard on their next page load and
workflow notifications route to them. Team and person stay fixed on the edit form —
moving either is a different assignment.

An **End date** field also appears on both the create and the edit form. The
assignment record has always carried one; the form simply never offered it, so an
assignment could not be closed off anywhere in the interface.

# TalentTrack v4.93.0 — Record an injury, and record the return (#2609)

An injury is one of the transitions a player's journey is meant to carry — trial,
signing, promotion, injury, return to play. TalentTrack has modelled it since the
journey shipped, but there was never a screen to enter one, so in practice an
injury ended up in a free-text note or nowhere at all.

Players now have an **Injuries** tab. A head coach records an injury for their own
squad through a short guided flow — who, what, when — and closes it with **Record
return** when the player is back. Both ends land on the player's journey
automatically, so a coach reading the file next season can see what the player came
back from and how long it took.

A new **Injuries** tile answers the squad-level question: who is out right now,
since when, and who was expected back before today. An expected return that has
passed with nobody recording an actual one is flagged, because that row needs a
decision rather than a nudge.

Injuries stay medical data about minors: every read is audit-logged, entries on the
journey keep their medical visibility level, assistant coaches have no access at
all, and deleting one remains with the head of development and the academy admin.

# TalentTrack v4.93.0 — Buttons render in one consistent case (#2615)

Buttons rendered UPPERCASE or sentence case depending on which HTML tag they
happened to use, not on any deliberate choice. A link-styled button came out
`CANCEL` while the real button beside it in the same row read `Save`.

The casing now lives in the label rather than the stylesheet, so every button
reads the same way wherever it appears — including the sign-in card, the 404 page
and the admin screens, which sat outside the rule that was papering over it.

A side effect worth having: sentence case is roughly 12% narrower than uppercase
before letter-spacing, so button rows have more room on a phone.

# TalentTrack v4.93.0 — One create verb, one case, across every button (#2616)

Buttons that create something now all read **Add …**. The same action used to be
spelled four ways depending on which screen you were on — `+ New season`,
`New category`, `+ Add option`, `Create case` — and two labels existed in both
Title Case and sentence case at once, so `Add Goal` and `Add goal` were different
buttons for the same thing.

The leading `+` is gone from button labels. Page headers already draw their own
icon, so the glyph was duplicating an affordance the component provides — and on a
phone, where the label collapses to the icon, the `+` was invisible anyway.

A few labels also lost words they were repeating from the screen around them:
`Start 30-day Pro trial` is now `Start trial`, `Share via WhatsApp` is `Share`,
`Run Report` is `Run`.

# TalentTrack v4.93.0 — Buttons stop repeating the screen they sit on (#2617)

A button inside a section headed *Rating scale* said `Save rating scale`. One inside
*Match minutes* said `Save match minutes`. Fifty-four spellings of the same action,
each restating a title the user was already looking at. They are all just **Save**
now.

The same trim runs through the other action families: four different spellings of
"print this page" become **Print**, `Open the chemistry board` becomes **Open board**,
`Everyone was here - continue` becomes **All present**.

Where a screen genuinely has two things to save, the nouns stay — My settings keeps
its four, the PDP screen keeps Save conversation and Save verdict, and the custom-CSS
screen keeps its separate CSS and preset saves. A bare Save is only clearer when
there is one of them.

# TalentTrack v4.93.0 — English installs no longer show Dutch labels in the methodology screens (#2618)

Parts of the methodology authoring screens were written with Dutch text as the
source string — the image panel, the play-styles tab, and the Raamwerk tab label.
On an English install those rendered in Dutch, and because the source string was
already localised they could never be translated into any other language either.

The source strings are now English and carry the original Dutch as their
translation, so a Dutch academy sees exactly what it saw before while an English
one finally reads English. The image picker's "Afbeelding kiezen…" also loses its
trailing ellipsis.

The Analytics entity view drops its "← Academy view" button. It pointed at the
same place as the Analytics breadcrumb directly above it, so the screen was
offering the same route twice.

# TalentTrack v4.93.0 — Football actions are visible under every methodology set (#2620)

The Voetbalhandelingen tab came up empty on any academy whose active methodology
was not the one the plugin shipped first. The catalogue's 18 actions had been
stamped to a single set when selectable methodologies landed, and the second
shipped set was never given its own — so the Methodology library's Football
actions tab, the goal → football-action picker and the printed reference card all
showed nothing.

The catalogue is now shared across every set. A football action — "passen onder
druk" — is vocabulary of the game rather than of one club's play style, so
switching the active methodology no longer changes which actions exist. Principles,
phases, vision and formations stay per-set as before.

An action a coach adds now joins the shared catalogue instead of being visible only
under whichever set happened to be active when they wrote it, and a goal linked to
an action keeps resolving to the same action under either set.

# TalentTrack v4.93.0 — The archive filter folds into one button, and stops claiming to show everything (#2622)

Every list spent a row of buttons on a filter almost nobody touches: Alle,
Actief, Gearchiveerd. It has collapsed into a single **⋯** button at the end of
the filter row, on phone and desktop alike.

Two things were wrong beyond the wasted space. **Alle did nothing** — it
returned exactly the same rows as Actief, because a list with no filter has
always shown active records only. And it was the option highlighted when you
arrived, so the screen said you were looking at everything while showing you
active records. Alle is gone; the lists open on Actief and say so.

Switching to archived records still announces itself clearly: the ⋯ button turns
yellow and a label appears beside it naming the state, with a ✕ to go back. An
archived list can't be mistaken for an empty one.

Applies to players, teams, people, evaluations, goals, tournaments, holidays,
activities, exercises, training plans and PDP coverage. The Goals list's
Actief / Behaald / Gemist buttons are a different filter and are unchanged.

# TalentTrack v4.93.0 — One name for the archive filter across every list (#2625)

The holidays, tournaments, exercises and training-plan lists called their
archive filter `status`, while every other list called it `archived`. Same
control, two names — and `status` already meant something else on the players
and goals lists, where it selects a player's own status (trial, released) or a
goal's bucket (achieved, missed).

All four now use `archived`, and the filter is labelled "Archive" on those
screens, so "Status" consistently means a record's own status. Links you
bookmarked and views you saved before this change keep working; saved views are
migrated automatically the first time the plugin loads.

# TalentTrack v4.93.0 — Test trends: a trend you can see without colour, and names you can click (#2628)

The Test trends report showed whether a player improved or fell back in green
versus red and nothing else. That is invisible to a red/green colour-blind
reader — roughly one man in twelve — and it disappears entirely when the report
is printed in black and white, which is how it reaches most touchlines.

Every change now carries a glyph as well as a colour: green ▲ improved, red ▼
fallen back, grey ▬ unchanged. The word itself is still there on hover and for
a screen reader, so the separate Verdict column is gone and the table is one
column narrower on a phone.

Height and weight tests gained an indicator they never had: a grey ▲ or ▼ that
says which way the value moved and passes no judgement, because a taller player
is not a better one.

Player names in the tables and in the Most improved / Fallen back lists are now
proper record links — they match the colour of the text around them, and hovering
one shows the player summary card, the same as everywhere else in the app.

Both test reports now draw the indicator from one shared component, so they
cannot disagree about the same player's trend.

# TalentTrack v4.93.0 — Knowledge library: courses ship with the plugin (#2642)

TalentTrack develops players; it did not develop the people who develop
players. The knowledge library is where coach education now lives.

This first ship is the content spine. A course is a folder under `courses/`
whose `course.md` carries a front-matter block: title, summary, lesson order,
the capability and licence tier it needs, the methodology principles it
teaches, and whether its lessons unlock in sequence. Lesson order comes from
the manifest rather than from filenames, so retiring a lesson does not mean
renumbering the folder. A course also declares the language it was written in,
which is what lets a Dutch-first course sit beside an English-first
documentation corpus without either pretending to be the other.

The registry is a projection of the folder, never a list beside it — dropping
a course in registers it, deleting it unregisters it. A course the registry
cannot parse is skipped rather than fatal, so a half-written course in a branch
never breaks a reader's page; a new `course-lint` CI gate turns that silence
into a build failure, checking that every declared lesson exists, that no file
is left out of the manifest, that prerequisites resolve, and that every quiz
payload is answerable.

Shipping with it: *Periodiseren in voetbaltaal*, a Dutch trainerscursus on
football periodisation — eleven lessons, ten quizzes and a twelve-week final
assignment, built on the methodology from Raymond Verheijen's *Football
Periodisation* (World Football Academy, 2014).

Nothing is readable in the app yet: the reader, progress tracking, gating and
completion statistics land in #2643 through #2650. The module and its
`knowledge_courses` sub-feature can both be switched off.

# TalentTrack v4.93.0 — Knowledge library: lessons you can work with, not just read (#2643)

Courses are stored as markdown so they can be reviewed in a pull request and
translated like any other text. That is a storage decision, and it was never
meant to cap a lesson at prose. This ship is the render.

A lesson now carries typed blocks. Three of them are tools a coach uses
rather than reads:

The **zero-point calculator** takes the minutes a squad managed before their
action count visibly dropped and returns the overload step their next twelve
weeks start from. Guessing that step is the difference between overload and
either injury or nothing happening at all.

The **week planner** checks a proposed week against the recovery times as it
is built, and names what breaks: small-sided games on Thursday with a
Saturday match leaves 48 hours where 72 are needed.

The **pitch-size calculator** turns a game format into dimensions, and says
where the rule of thumb stops working — below 7v7 the computed width comes
out narrower than a penalty area, and a pitch that narrow quietly turns an
intensive endurance session into an extensive interval one.

Alongside them: the six-week model as three phases you can open, an action
notation that draws quality and recovery instead of asserting them, a load
matrix that recalculates for a three- or six-week cycle, callouts, and
self-check questions.

Every block renders a usable state on the server, so a reader with
JavaScript blocked still gets the tables, the model and the default matrix.
A lesson made only of prose and callouts loads no JavaScript at all. An
unrecognised block renders as a code sample rather than breaking the page,
so a course written against a newer release degrades on an older one.

Supercompensation times, step tables and pitch sizes now live in one place
that the blocks, and later the session planner, all read — a course that
teaches "72 hours" beside a planner that warns at 48 would be worse than
either alone.

Still not readable in the app: the reader view arrives in #2646.

# TalentTrack v4.92.0 — The generator: answer four questions, get a training plan (#2497)

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

# TalentTrack v4.92.0 — Head coaches can correct their own squad's player records (#2584)

A head coach could not fix a jersey number, a position or a preferred foot for
a player in their own team — every correction went through an academy admin.
Head coaches can now edit players on teams they run.

Adding and removing player records stays with the academy admin: that is a
registration act with consequences for squad size, billing and safeguarding.
Assistant coaches keep read-only access, which is one of the few places where
the two coaching roles now differ.

# TalentTrack v4.92.0 — Test reports were unreadable on desktop (#2585)

The Test trends report collapsed into a row of narrow columns — chart, rankings
and table each squeezed to a sliver with text breaking one word per line —
because a styling rule written years ago for the small player card was being
applied to any panel that happened to share its name. That rule now belongs to
the player card alone, and the report lays out as intended: full-width chart,
rankings side by side, readable table.

The Test results table also wasted most of its width on the player-name column
while team names wrapped onto three lines and dates onto two. Its columns are
now sized to their content, so each row reads on a single line.

Both reports keep their mobile card layout unchanged.

# TalentTrack v4.92.0 — Test results now show how much a player changed, not just that they did (#2586)

The Trend column showed only an arrow — you could see a player had improved,
but not by how much, which is the part that matters. It now shows the signed
change beside the arrow, e.g. "▲ −0,08 s" on a test where lower is better.

Players with only one measurement still show a dash rather than a made-up
zero. The number follows the site language, so a Dutch install reads −0,08.

# TalentTrack v4.91.1 — Staff Certifications unusable on a fresh install (#2490)

The certificate-type vocabulary the screen depends on was never seeded, so
Staff Certifications could not be used at all until an admin added types by
hand — and nothing on the screen explained that this was the blocker.

Installs now arrive with UEFA-A, UEFA-B, UEFA-C, First aid, GDPR awareness and
Child safeguarding, translated into Dutch, German, French and Spanish.
Academies that already added their own types keep them.

# TalentTrack v4.91.1 — New-evaluation wizard offered coaches the whole academy (#2567)

The player picker in the new-evaluation wizard listed every team and every
player, not the ones the coach actually coaches. It gated on a capability that
reads like an admin check but that every coach turns out to hold, so an
earlier attempt at this same fix never took effect.

It now asks whether the user holds academy-wide player access. Head coaches
and assistant coaches see only their own squads; Head of Development and
academy admins keep the full list. Scouts, who previously got an empty picker
despite being able to record evaluations, now see the academy-wide list their
role is meant to have.

# TalentTrack v4.91.1 — Lookup labels rendered in English on translated installs (#2568)

Every lookup carries an English row copied from its canonical value, and that
copy was being returned in place of the translation — hiding translated labels
the plugin already ships. Most visibly the evaluation Type dropdown, which
read "Training / Wedstrijd / Oefen / Tournament / Observation / Other" on a
Dutch install.

Dutch, German, French and Spanish installs now show the translated label
wherever one exists. An academy that has deliberately renamed a lookup in
English still sees its own wording. Tournament, Observation and Other are also
seeded with translations for all four languages, since one of them had no
translation anywhere to fall back on.

# TalentTrack v4.91.1 — Admin screens were reachable by any coach via their address (#2569)

Configuration, Custom fields, Evaluation categories, Application KPIs, Lookup
normalisation, Roles & rights and Migrations all gated on a capability that
reads like an admin check but that every coach holds. Their tiles were hidden
from a coach's navigation, so this stayed invisible — but typing the address
was enough. A coach could also call the lookup-normalisation endpoints, which
change vocabulary academy-wide.

Each screen now gates on the permission that matches what it actually does.
Coaches are refused, academy admins are unaffected. Head of Development keeps
Evaluation categories and Application KPIs and no longer reaches the
configuration screens, matching the read-only-on-config intent already applied
to their dashboard.

# TalentTrack v4.91.1 — Evaluations list showed a coach nothing while the team rating showed data (#2571)

Team assignment is stored in one table and team *scope* — the thing that
answers "which teams is this coach responsible for?" — in another. The demo
generator and the Excel importer wrote the first without mirroring into the
second, so affected coaches held no team scope at all and the Evaluations list
quietly narrowed to "evaluations I personally authored". The team rating and
the player's Evaluations tab aren't coach-scoped, which is why they kept
showing the same players' data and the contradiction looked like a phantom
rating.

A migration backfills the missing scope rows, both import paths now mirror
correctly, and deleting a team clears its scope rows instead of leaving them
behind to outlive it.

# TalentTrack v4.91.1 — Season-intake batch printing cascaded pages into each other (#2572)

Printing a whole squad's season intakes produced a couple of pages with the
sheets running into one another instead of one sheet per page. The batch was
assembled by pulling each player's sheets back out of rendered HTML, which cut
them short and left the markup unclosed, so the browser nested the sheets
inside each other and page breaks stopped working.

Printing a squad of twelve now yields the expected 36 pages — three per
player. Printing a single player's intake was never affected and is unchanged.

# TalentTrack v4.91.1 — Players with no join date showed "2028 yrs in academy" (#2573)

A player whose join date was never set rendered an absurd academy tenure on
their status chip and a raw `0000-00-00` as the join date on their profile.
An unset date of that shape is neither empty nor invalid enough to be caught,
and reading it as a real date puts it two millennia in the past.

It is now treated as no date: the tenure falls back to when the record was
created, and the profile omits the join date rather than printing a
placeholder that looks like real data.

# TalentTrack v4.91.1 — Behaviour rating can be switched off per academy (#2574)

Academies that don't score behaviour were still shown a capture button on
every player, a bulk action on every team, and a step in the evaluation wizard
they always skipped. Behaviour rating is now a switchable sub-feature of
evaluations, controlled from the feature toggles screen.

It is on by default, so nothing changes unless you turn it off. Switching it
off stops new capture and hides the entry points; behaviour already recorded
is kept, and reappears if you switch it back on. Setting a player's potential
band is unaffected either way.

# TalentTrack v4.91.0 — The exercise library gets a screen (#2495)

The merged exercise library is now browsable. Open the **Training** tile and
choose **Exercises**: search by name, code or description, filter by category,
intensity, visibility or status, and open any drill to see its setup, group
size and diagram. VCT's conditioning exercises sit alongside your own, labelled
so you can tell them apart.

**Coaches can add their own drills.** A new exercise belongs to your team and
is usable in your plans immediately — nothing waits on approval. Whether the
rest of the club gets it is a separate call: the head of development sees an
"Added by teams" panel listing what coaches have written, with how many plans
already use each one, and makes the good ones club-wide.

Editing an exercise creates a new version, so plans and trainings that used the
old one keep showing it exactly as it was.

**For administrators.** The VCT permission that used to cover the exercise
catalogue, the age profiles and the macro-blocks has been split. The library
moved to the exercises permission coaches already hold; the age profiles and
macro-blocks kept a head-of-development-only permission, renamed
`tt_vct_admin_config`. Nobody gained or lost access — in particular the age
profiles, which set the age-safe intensity ceilings for U10–U14 players, remain
restricted.

# TalentTrack v4.91.0 — Moving between screens no longer flashes, and lands faster (#2517)

Clicking through the app reloaded the whole page: the screen blanked, the
sidebar and header were redrawn identically, and you waited.

Two changes make that feel like it used to look. Hovering a link now quietly
starts loading that page, so by the time you click it is usually already there.
And where the browser supports it, moving between screens cross-fades instead
of blanking — the sidebar and header hold still and only the content changes.

Neither alters how the app works: every click is still a normal page load, so
the back button, bookmarks, refresh and opening links in a new tab all behave
exactly as before. Browsers without these features simply navigate the way they
always have.

Two details worth knowing. Prefetching is skipped when your device asks for
reduced data or is on a slow connection, and it never runs ahead of a link that
changes something. And a page loaded in advance is **not** counted as a visit,
so your usage statistics still show where people actually went, not where they
hovered.

# TalentTrack v4.91.0 — **Fixed:** the app shell's sidebar now carries the academy crest and name at

the top and the signed-in user at the foot, so which academy you are in is
answerable without looking away from the navigation. Both shrink to their mark
alone when the sidebar is collapsed to icons.

**Fixed:** icon chips ignored the active visual theme. Dashboard tiles kept
their per-module colours and Configuration tiles rendered in the old green
even under a navy theme. While a theme is active, every chip now takes the
theme's colour; under the default theme the per-module colours are unchanged.

**Fixed:** with a theme active, the collapsible navigation groups still used
light-surface colours on the dark sidebar — hovering a group painted a
near-white block behind near-black text, and the hairline between groups
rendered as a bright bar. Both now follow the theme.

# TalentTrack v4.91.0 — Search where you look: type straight into the header field (#2531)

The search box in the header used to be a button in disguise — clicking it
opened a separate window with its own box to type in. Two steps to start
something already on screen.

Now it is a real search field. Type in it and matching players, teams,
activities and sections appear directly underneath as you go; click one, or
pick it with the arrow keys and press Enter. Escape closes the list and leaves
your cursor where it was. **⌘K** (Ctrl-K on Windows) jumps to the field.

The field is also about twice as wide and sits centred in the header, so long
player and team names fit on one line instead of being cut off.

# TalentTrack v4.91.0 — The navigation column now stays put and reaches the bottom of the screen (#2533)

The sidebar stopped after its last group, leaving the page showing through
below it, and it drifted with the content as you scrolled a long page.

It now fills the left side from under the header to the bottom of the window
and stays there while the content scrolls. When there are more destinations
than fit, the list scrolls inside the sidebar rather than making the page
longer.

The column is also a little wider, so the longest section names sit
comfortably instead of only just fitting.

# TalentTrack v4.91.0 — A player's test results now have a readable history (#2536)

A test on a player's **Measurements** tab showed its latest value, a flag
against the age-group target, and a sparkline about a centimetre tall. To see
whether a player was actually getting faster over the season you had to export
to Excel.

Every test with more than one result now carries a **Show history** link that
opens the full picture underneath it: a dated chart with the value axis, each
reading labelled, and the age-group target shaded so you can see when the
player crossed into it. Where a test measures something with no better or worse
— height, weight, shoe size — there is deliberately no chart: those are grouped
together and shown as readings per date in columns, with a plain change figure
and no verdict, because a rising line would imply progress and a shaded band
would imply a norm that does not exist. Status tests show one block per date in
that level's own colour rather than a line through named states, and pass/fail
tests show a tick per date with the tally.

On a test where lower is better, an improving line goes down — every such chart
now says so in words rather than leaving the reader to work it out from the
slope. A test with a single result says so too, instead of drawing an axis
around one point.

The chart is server-rendered SVG with no JavaScript, so it also works in print
and in the PDF report path.

# TalentTrack v4.91.0 — Test trends: one test, every player, over the season (#2537)

*Test results* has always answered "how is each player doing on this test right
now". The other half of the question — **who is developing and who is
stalling** — existed only inside the Excel export's Trends sheet. It is now a
report.

**Test trends** (Analysis group) takes a test, optionally a team and a date
window, and shows a line per player over the shared date axis with a heavier
dashed squad-average line, then **Most improved** and **Fallen back**, then a
table with each player's first value, latest value, change and verdict. Every
player name links to their profile and back.

The report's shape follows the test, because a trend only means something in
the terms of its own test. A test with no direction — height, weight — gets the
readings per date and nothing else: no chart, no ranking, no verdict, because
there is no better or worse to rank. A status test gets a player × date matrix
of levels in their own colours rather than lines through named states. Pass /
fail gets ticks, a per-player tally and the pass rate per round.

**The change is read in the direction of the test.** On a test where lower is
better, −0,08 s is an improvement: green, *improved*, and ranked under Most
improved. A change smaller than 2% counts as *about the same* and appears in
neither ranking — a one-percent move on a hand-timed sprint is inside the noise.

A team-scoped coach sees only their own teams, and a link to another team's data
is refused rather than quietly widened. Integrations read the same numbers from
`GET /reports/test-trends`. Administrators can hide the report under
**Settings → Features → Test trends**.

# TalentTrack v4.91.0 — Configuration: export the academy's settings and module state as JSON (#2540)

**Configuration → Export configuration** downloads this academy's whole
configuration as one JSON file: every setting from `tt_config`, the
install-level values from `wp_options`, and — the part that had no surface
before — which modules and features are switched on or off.

Each module and feature entry carries its human label and the `?tt_view=`
screens it owns, so the file answers "which surfaces does this install
actually have?" rather than just listing class names and booleans. That is
the question worth asking before writing training material for an academy:
a module that is off takes its screens with it.

Integration credentials stored in `tt_config` — the Strava app secret, the
Spond password and token, the DeepL API key, the Google service account —
are replaced with `[redacted]` and collected under `redacted_keys`. The key
name is kept so you can still see that an integration is configured; the
value never leaves the server. No player data is included.

Also available through the API at
`GET /wp-json/talenttrack/v1/exports/config_json?format=json`, gated on
`tt_edit_settings` and recorded in the audit log. Export only for now —
there is no importer yet.

# TalentTrack v4.91.0 — Help topics are registered by the doc files themselves (#2544)

Each help topic's title, group, summary and audience now live in a front-matter
block at the top of its own markdown file instead of in a separate list inside
the plugin. Dropping a documented file into the docs folder registers it — which
is what stops shipped features from having documentation nobody can reach.

Dutch titles and summaries come from the Dutch doc, so the sidebar no longer
depends on the translation catalogue for its labels.

One topic surfaces as a result: **Trial cases** was filed under a sidebar group
that does not exist, so it had never appeared in Help & Docs and could only be
reached by typing its URL. It now sits at the end of Performance.

# TalentTrack v4.91.0 — Help links stay inside the app, and can open the screen they describe (#2545)

Following a link inside Help & Docs used to hand you to the WordPress admin —
a dead end for a coach or a parent, most of whom cannot load it. Cross-references
between help topics now stay in the help viewer you are already reading in.

Help topics can also link straight to the screen they describe. Those links know
what you can reach: a link to a screen your academy has switched off, or that
your role cannot open, is shown as plain text rather than sending you to a
permission-denied page. Following one carries a back link, so you land on the
screen with one tap back to what you were reading.

The handful of topics that genuinely document WordPress admin pages still link
there, but only for administrators, and the link is marked as leaving
TalentTrack.

Also fixes cross-references between topics, which had been rendering as
unclickable text.

# TalentTrack v4.91.0 — Two-factor sign-in no longer loops back to the code screen (#2553)

**Fixed:** entering a correct two-factor code could drop you straight back on
the code screen instead of moving you into the app. The challenge had actually
been cleared, so nothing intercepted the page any more — you were signed in,
looking at a form with nothing left to verify, and the only way out was editing
the address bar.

The cause was the sign-in form's "where to go next" value, which defaults to
whatever page you are currently on. Once the address bar held the two-factor
screen — after a refresh, a back-button, or signing in again from that page —
that screen became its own destination. It is now excluded: the two-factor
prompt and the enrollment wizard can never be a post-verification landing page,
and anything else you were genuinely headed for still survives the detour.

Also fixed: an abandoned challenge left its destination live for a quarter of an
hour, so a later sign-in inside that window inherited it. The destination is now
dropped along with the challenge.

# TalentTrack v4.91.0 — The two-factor screen no longer wears the whole app around it (#2554)

**Fixed:** immediately after signing in, before the second factor had been
entered, the two-factor screen rendered inside the full application — navigation
rail with every module, global search, notification bell, persona menu, a link
into the WordPress admin, and a breadcrumb trail back to the dashboard, with the
code field sitting underneath all of it. That reads as "you're in, now also type
a code", which is the opposite of what the screen means.

Both challenge screens — the code prompt and the enrollment wizard a user is
held at when two-factor is required but not yet set up — now render on the same
centred, branded card as the sign-in and password-reset screens: academy crest,
academy name, the challenge, nothing else. A *Log out* link on the card is the
way out for someone who can't complete it, which the navigation used to provide
by accident.

Enrollment started deliberately from the Account page is unaffected and keeps its
normal in-app wizard chrome.

# TalentTrack v4.91.0 — Head coaches can create team blueprints again (#2557)

The "+ New blueprint" button on a team's blueprint list was a dead link for
head coaches: the list rendered it from the `team_chemistry` matrix (which
grants head coaches manage on their own teams) while the wizard behind it
still gated on the raw `tt_manage_team_chemistry` capability, which only
administrators, heads of development and club admins hold. Clicking the
button just reloaded the page.

The blueprint wizard now resolves its entry gate through the same
`TeamChemistryAccess::canManage()` decision the list, the editor and the REST
writes already use, via a new optional `isAvailableFor()` hook on the wizard
registry. The other seven wizards are unchanged. A read-only viewer no longer
sees an empty-state message pointing at a button they don't have.

# TalentTrack v4.91.0 — The Spond connection page for a team opens again instead of erroring (#2559)

A head coach who opened **Spond connection** from their team's page got the
site's critical-error screen instead of the panel. The page never worked:
it looked up the team id from a place that did not hold it, so it always
asked for team "nothing" and stopped there.

It now reads the team from the address as the other per-team pages do, and
opens on the connection panel for that team. An address without a usable
team id shows the ordinary "no access to this team's Spond connection"
message rather than an error page.

# TalentTrack v4.91.0 — Top bar tidied, and the keyboard hint now names a key you have (#2563)

Three fixes to the top bar in the app shell layout.

Its contents had drifted to the far left, leaving most of the bar empty — the
academy name moved into the sidebar and nothing took its place. Notifications
and help now sit on the right where they belong, and the search box is centred
in the bar rather than tucked beside them. The search box is wider again, and
the navigation column gains a few pixels too.

The keyboard hint on the search box read **⌘K** for everyone. That is the Mac
Command key, so on Windows it named a key that is not on the keyboard. It now
reads **Ctrl K** on Windows and Linux and **⌘K** on a Mac. The shortcut itself
always worked on both — only the label was wrong.

# TalentTrack v4.90.0 — One exercise library: the VCT catalogue merges into the main library (#2494)

TalentTrack held two exercise catalogues that could not see each other — the
general library and the VCT conditioning catalogue — each with its own fields.
They are now one. Every VCT exercise moves into the main exercise library,
keeping its intensity band, player range, age window and match-day
suitability, and the VCT session planner keeps working exactly as before:
the same inputs still produce the same session.

Nothing changes on screen in this release. It is the groundwork for the
Training module, where coaches browse and build from a single library instead
of meeting two.

# TalentTrack v4.90.0 — Groundwork: training plans get their own record (#2496)

Adds the foundation for the Training module: a training plan, its ordered
blocks, the methodology principles it covers, and a record of each time the
plan is actually run against a training in the calendar. Coaches, heads of
development and academy admins get a new "training plan" permission, and the
whole thing is reachable through the API before any screen exists.

Nothing appears on screen yet. The design that matters for later: a plan is a
reusable template you can keep editing, while each execution takes a permanent
snapshot of what it contained on the day. Adjusting a plan in September can
never change what a session in August says it was — which is what makes the
per-player training history trustworthy when it arrives.

# TalentTrack v4.90.0 — A Training tile, with your training plans in it (#2496)

The Training module gets its first screen. A new **Training** tile in the
Planning & tactics group opens your training plans: search them, sort them,
filter between team plans and club templates, and archive the ones you are
done with.

Open a plan to see what it holds — the total time, a colour-coded strip
showing how the training splits across its blocks, every block in order with
its coaching points, and each training the plan has actually been used for.

Read-only for now: building and editing a plan comes next, along with the
generator that drafts one for you.

One thing worth knowing while you use it. Attaching a plan to a training takes
a permanent copy of the blocks as they were that day, so you can keep
improving a plan afterwards without ever changing what a training that already
happened says it contained.

# TalentTrack v4.90.0 — App shell now fills the window, and its header stays put (#2504)

With the app shell switched on, the application was still drawn as a centred
document: on a 27" screen at 2560px that meant roughly 550 pixels of empty page
down each side, with the sidebar floating in from the edge rather than sitting
against it. The header also scrolled away with the content, taking search,
notifications and the account menu off screen on any long page.

The app shell now owns the full width of the window, with the sidebar against
the left edge, and the header stays pinned while the content scrolls beneath
it. The sidebar pins directly below the header instead of sliding underneath,
and both allow for the WordPress admin bar where it is shown.

Classic is unchanged — it keeps the centred, width-capped reading layout it has
always had. Switching between the two is still a clean swap either way.

# TalentTrack v4.90.0 — The app shell sidebar now lists every section, in collapsible groups (#2505)

The app shell's sidebar showed three entries — Activities, VCT training
designer and Open wp-admin — while the tile overview on the same screen showed
around thirty. Everything else was simply missing from the navigation, which
rather defeats having a permanent sidebar.

All **59** destinations now appear, grouped exactly as the tile overview groups
them. Because that is a long list, groups fold: the group you are working in
opens automatically, the rest stay tucked away, and the sidebar no longer turns
into a column you have to scroll. Opening a group is one click and the sidebar
remembers nothing you did not ask it to.

The sidebar is also a little wider, so longer section names fit on one line
instead of wrapping.

# TalentTrack v4.90.0 — Two identically-named entries under "My" are now told apart (#2506)

Anyone holding both staff and player access — every academy admin, and a
player-coach — saw **My PDP**, **My goals** and **My evaluations** listed twice
under "My", with nothing to say which was which except clicking one.

The staff versions now carry a small qualifier: *My PDP (staff)*. It only
appears when the same name is genuinely shown twice, so a staff member who is
not also a player, or a player who is not staff, still sees the plain names as
before. *My certifications*, which never had a twin, is untouched.

# TalentTrack v4.90.0 — Search: players and teams no longer pushed out by section matches (#2508)

Typing the first letters of a player's name into the search box often showed
only sections — Activities, Team planner, Test coverage — and never the player.
Typing `er` matched nineteen players and showed none of them.

Sections were listed first and the whole list then cut to eight, so any search
matching eight or more sections had no room left for anything else. Since there
are around sixty sections to match against, two-letter fragments hit that limit
constantly — which is exactly when you are still typing a name.

Sections now take at most three places whenever records are also matching, and
the rest of the list goes to players, teams and activities. Nothing is lost the
other way: when a search matches no records, sections still fill the list, and a
name that matches no section gets the whole list to itself. The list also holds
ten results instead of eight.

# TalentTrack v4.90.0 — Bump: minor

**Visual themes.** The frontend's colours, corners and heading type are now a
setting. Alongside the shipped green-and-gold **Default**, there is
**Federation** — a navy chrome with a gold marker on the active section,
squarer corners and a condensed heading face. The academy picks a default
under Configuration → Appearance, and each person can pin their own under My
settings → Theme.

A theme changes appearance only — no permission, field or button changes with
it. While a theme is active it supplies the whole colour scheme, so the colour
and font settings under Appearance do not apply; your logo and academy name
still do, and the Colours panel says so rather than letting you pick a colour
that does nothing. Setting the theme back to Default is a complete rollback:
the theme's stylesheet is not loaded, no theme class is written into the page,
and your colours return exactly as you left them.

**Fixed:** with the app shell on, the navigation sidebar listed only three
destinations while the tile overview on the same screen showed thirty. Every
section is now reachable from the sidebar (#2505).

# TalentTrack v4.90.0 — Attendance reports count only activities marked completed (#2521, #2522, #2523)

Attendance statistics counted sessions that had not been held. An activity
reading **Status: Planned** on screen still reached the reports, because the
reports gated on an internal planner column that arrives set to "completed" on
every activity the planner did not create — which is every Spond import and
every activity added from the form or the wizard. The team and player
attendance reports, the leaderboard, the at-risk panel, the daily
attendance-flag notification, the team KPI tiles and the **Activities** badge on
a player's file now all read the status shown on the activity page, so a
planned session contributes nothing until it is marked completed and the
figures can be checked against what is on screen.

Because recording who was there is itself the statement that a session took
place, **saving the attendance grid now marks the past-dated planned activities
it wrote to as completed** — after asking. A column for a past-dated session
still marked planned carries an amber underline, and pressing Save opens a
dialog naming every activity whose status is about to change, with **Save and
mark completed** or **Back to the grid**; nothing is written until you choose,
and a save that changes no statuses shows no dialog. The save bar then reports
how many were marked. Future-dated sessions and activities marked Cancelled are
never completed this way. This replaces the previous behaviour, where grid entry
deliberately left completion to a separate click — under the new gate that would
have meant a coach's entry never reaching the reports.

An activity's **Attendance** card and the **Present** figure in its stat strip
counted the planned roster on top of the recorded register, so an activity with
both could report more players present than the squad holds — "28 / 15
present". Both now count recorded attendance only. The **Present** figure also
waits for the activity to be completed, matching the Attendance card below it,
rather than stating a turnout for a session that has not happened.

# TalentTrack v4.90.0 — **Fixed:** the *Visual theme* selector was on Configuration → General instead

of Configuration → Appearance, two clicks away from the colours and type it
governs — and away from the notice on Appearance explaining that a theme
overrides those colours. It now sits at the top of the Colours panel on
Appearance, directly above that notice. Navigation layout is unchanged and
stays on General.

# TalentTrack v4.89.1 — Demo data: selective generation lost its coaches, and with them every evaluation (#2503)

Unticking **Generate teams** on the demo form and generating on top of your own
squads quietly produced no evaluations at all. The run reported success.

`head_coach_user_id` is not a column on the teams table — it is attached to the
team objects the generator builds, and every downstream generator reads it from
there. The path that loads existing teams did a plain `SELECT *`, so the coach
was simply absent: activities were filed under user 0, and the evaluation
generator skipped every team because it had no coach to attribute the
evaluation to.

The coach is now resolved from the team roster, and a team with nobody marked
head coach falls back to whoever ran the generation, with a notice naming those
teams so the silence is visible.

The same shape problem hit player archetypes, which drive each player's rating
trajectory. Without them every player fell back to the same "steady" curve, so
a selective run produced a flat line for the whole squad; archetypes are now
recovered for previously generated players.

On a three-team academy this is the difference between 0 and 516 evaluations
with 12,900 ratings, spread across the configured scale instead of pinned to
one value.

# TalentTrack v4.89.0 — Demo data: coverage manifest, and journey events are wipeable again (#2462)

The demo-data module now declares its coverage in one place. Every table the
schema creates is classified in `DemoCoverage` as generated, planned, or
exempt with a stated reason, and the wipe, the generate form and the wipe
form all derive from that declaration instead of four hand-maintained lists
that had to agree.

The immediate fix an operator will notice: journey events generated during a
demo run were never tagged, so no wipe could ever reach them — an install
seeded with the `small` preset was carrying 606 orphaned timeline rows that
survived every "wipe demo data". They are tagged now and wipe with their
players. Excel-imported trial cases had the same gap and are also reachable.

Generated output is otherwise unchanged: the same seed and preset produce the
same academy, byte for byte, as before.

Two CI gates keep it that way. A migration that adds a `tt_` table now fails
the build until it is classified, and a self-check proves the delete order is
dependency-safe and that no generator can write rows the wipe cannot reach.

# TalentTrack v4.89.0 — Demo data: guardians, injuries, player profile and reports (#2463)

A generated player is now a dossier rather than a roster entry. Demo runs
fill in the guardian link and its parent-visibility grants, injury records
with return-to-play dates, age-group history, the full attribute matrix the
chemistry surfaces read, the club's own custom fields with values, links from
goals back to the evaluation that prompted them, and a spread of player
reports.

Injuries go through the same repository the Injuries screen uses, so they
raise the same timeline events and the same recovery-due workflow task a real
injury would — a demo timeline reads exactly like a production one.

Two deliberate limits. Guardians attach to the demo parent accounts rather
than minting an account per player, so each parent account gets a family of
one to three children and the rest of the roster has no linked guardian —
enough for the parent persona to sign in to something real, without a dozen
welcome emails per run. And generated reports carry no share token and no
recipient address, so nothing hands out a working public link.

# TalentTrack v4.89.0 — Demo data: measurements and the PDP cycle (#2464)

Two of the screens that best show what TalentTrack is for rendered empty on a
demo install. Both are filled now.

Demo runs create a testing battery an academy would actually use — height and
weight, 10 m and 30 m sprints, countermovement jump, shuttle run, juggling,
passing accuracy, a dribble circuit and a focus self-assessment — with target
bands per age group, team testing sessions across the window, and a result per
player per session. Each test declares which direction is better, so a sprint
time and a jump height are graded the right way round.

Results follow a per-player trend rather than noise: a player sits
consistently above or below their age group and improves across the season, so
the progression charts show something real. A few players miss a round, so the
coverage indicator isn't a flat 100%.

The PDP side gets the season, a development dossier per player, its
conversation cycle, calendar links on what's still scheduled, and verdicts on
the dossiers that have closed. Conversations that have already passed are
conducted and signed off while the next one stays open, so both halves of the
screen have something in them.

All of it goes through the PDP repositories, so the conversation cycle is
spaced by the same planning-window rules as the real flow and a signed-off
verdict raises its timeline event.

# TalentTrack v4.89.0 — Demo data: training content and match day (#2465)

A generated training used to be a calendar entry with an attendance list, and
a generated match had no result. Both have content now.

Trainings get four to six exercises from the club's library in order, with
durations that add up to roughly the session, plus the methodology principles
they work on. Per-team exercise overrides and the season's holiday windows are
filled in too.

Every fixture gets match prep — availability, a starting eleven, roles and
per-player intent — and every fixture already played gets a result, goal
events, substitutions and a light tracked-event stream. Fixtures still ahead
get prep and no result, which is what a coach's screen looks like mid-week.

Squad size follows the age group, because youth football is small-sided: an
under-9 team fields six, an under-12 eight, and eleven only from the early
teens. A twelve-player under-8 squad was never going to produce an eleven, so
without this the youngest teams generated no match data at all.

The generated match data is internally consistent, which matters because
reports read it as though it were real: availability never marks a player
present on a date their injury record says they were out, goal scorers come
from that match's lineup, and substitutions take a starter off for a bench
player, so derived minutes-played never exceed the match length and a team's
total lands exactly on squad size times it. That last point is what makes the
minutes reports usable on a demo install for the first time.

# TalentTrack v4.89.0 — Demo data: team development (#2466)

Each generated team now has a shape and a way of playing: an age-appropriate
formation from the shipped templates, a playing-style mix across possession,
counter and press, a match-day blueprint with its slot assignments, and a few
coach-marked pairings.

Chemistry snapshots are computed by the chemistry engine from the team's own
blueprint lineup rather than invented, so the stored score agrees with what a
recompute produces. The series runs across the generated window so the trend
view has a line rather than a single point.

Formations, position profiles and set pieces are shipped methodology content
that migrations already seed, so the generator assigns and uses them instead
of building a parallel set — a demo club with two formation libraries would be
worse than one with none.

# TalentTrack v4.89.0 — Demo data: scouting, trials and tournaments (#2467)

The intake pipeline was invisible on a demo install: no prospects, no scouting
visits, and trial cases only if you happened to upload a workbook containing
them. All three are generated now, along with tournaments.

Most generated players carry a historical trial case, closed with an admit
decision and dated before they joined the roster. That matters more than it
sounds: without it a demo academy's players appear fully signed from nowhere,
and the player journey the product is built around has no beginning. A couple
of players keep an open case so the surface a scout works on every week has
something on it. Each case has a staff panel of two or three, assessments from
most of them, and extensions on some of the open ones.

Trial cases fire the same hooks the Trials module fires, so the timeline gets
its trial-started and decision events in exactly the shape production writes
them.

Scouting visits run across the window in all three states — completed, planned
and cancelled — with prospects attached to the completed ones, named from the
same Dutch pools the roster uses so the pipeline reads like the same club.

Tournaments get a squad with target minutes, four short fixtures, and
per-period assignments that rotate through the squad so nobody sits out —
which is the point of a youth tournament planner.

# TalentTrack v4.89.0 — Demo data: staff development, messages and operator records (#2468)

The last uncovered corner. Demo runs now fill in staff development —
development plans, goals, evaluations with per-category ratings, and mentor
pairings — plus the conversations and operator records that make an install
look used rather than newly installed: threads with uneven read state so
unread badges are actually non-zero, saved filters, report presets, workflow
tasks, and invitations in all four states.

Nothing here sends. Invitations and workflow tasks are written directly rather
than through the services that dispatch them, so the invitations screen shows
pending, accepted, expired and revoked rows without anyone receiving anything
and without the workflow engine firing.

Staff certifications are the one thing that stays empty: they require the
club's certificate-type vocabulary, which has no default seed (#2490). The
generator skips them rather than inventing lookup entries.

With this, every table the schema creates is either generated by a demo run or
recorded as exempt with a reason — no table is unaccounted for.

# TalentTrack v4.88.0 — Usage statistics: "last N days" windows now use the site's timezone (#2444)

Every "last N days" figure on the usage-statistics surfaces was off by the
site's UTC offset. Events are stamped in site-local time, but each window
boundary was built in UTC, so on a Dutch install the window started two hours
late: activity between 00:00 and 02:00 on the oldest day of the window was
left out, and the same two hours from the day before were counted in. The
daily-active-users chart and the "events on this day" drill-down could also
disagree at those edges, filing a 00:30 event under the neighbouring day. The
90-day retention prune deleted two hours early for the same reason.

All of these boundaries are now built in the site's timezone, so the numbers
line up with the calendar days people actually worked. Counts on an offset
install will shift slightly — that shift is the correction. No data changed:
the stored events were always site-local, this fixes how they are read.

# TalentTrack v4.88.0 — Buttons that rendered as grey native controls are now properly styled (#2445)

A group of buttons across the app rendered as raw browser-default controls —
grey, square, system font — instead of TalentTrack buttons. The most visible
were on the evaluation wizard's Attendance step ("Everyone was here —
continue" and "Mark all present"), but the same fault affected the rate-confirm
Yes / Skip fork, the trials list and its tracks and letter-template editors,
the trial parent-meeting actions, the tournaments squad step, the wizards admin
page, the activities reopen-rating button, and the MFA and desktop-only
prompts.

The cause was a class name that never existed: `tt-button` and its
`-primary` / `-secondary` / `-small` variants have no styling defined
anywhere, so every element carrying one fell back to the browser default. All
32 occurrences now use the real button system, and a CI check fails any future
pull request that reintroduces the phantom name — it kept coming back because
nothing ever complained about it.

The wizard's own Cancel / Back / Next / Save-as-draft bar is unchanged: it was
already fully styled by its own rules, so it simply drops the dead class
rather than gaining a new one.

# TalentTrack v4.88.0 — Saved views are now part of the standard filter bar (#2448)

Saved views — the named filter combinations you re-apply with one click —
shipped for the five attendance and minutes reports. They were built as a
separate strip bolted on above the filter bar, wired report by report, which
meant no other screen could offer them.

They are now part of the shared filter bar itself. Nothing changes on the five
reports: the same views, saved under the same names, keep working exactly as
before. What changes is underneath — any screen built on the standard filter
bar can now switch them on, which is what lets the players, teams, evaluations
and goals lists get them next.

Two details worth knowing. Which filters a saved view captures is now worked
out from the filter bar's own configuration rather than a fixed list, so a
screen can't be wired up to save an empty view by accident. And each screen's
saved views are gated on that screen's own permission instead of the reports
permission, so a saved view can never expose a screen the user isn't allowed
to open.

# TalentTrack v4.88.0 — Saved views arrive on the lists and the standard reports (#2449)

Saved views — name a filter combination, re-apply it with one click — were
only on the five attendance and minutes reports. They now appear on the
surfaces coaches actually work in: the players, teams, people, evaluations,
goals, tournaments and holidays lists, the activities list, the audit log, and
all six standard reports.

On a list, a saved view remembers more than the filters: the search term and
the sort order go with it. Restoring a view that put the filters back but
quietly reset the sort would not be the view you saved.

Views stay personal — only you see yours — and each belongs to the one screen
you saved it on, so a players view never turns up on the teams list. Each
screen's views are gated on that screen's own permission, so a saved view can
never reveal a screen you would not otherwise be allowed to open.

Not included, deliberately: the attendance and minutes entry grids (data-entry
screens rather than browsing ones, where the strip would compete with the
grid's own controls), the custom-fields settings screen, and the trials list,
player comparison and My activities — those three decide access with composite
rules rather than a single permission, so they need their own pass rather than
a guess.

# TalentTrack v4.88.0 — Saved views: pick one to open by default (#2450)

A saved view is one click. Now it can be zero. In a saved view's **…** dialog,
tick **Open this view by default on this screen** and that view is applied
whenever you open the screen without filters of your own — arriving at the team
attendance report already scoped to your team and this season, rather than to
everything.

One default per screen, per person. Marking a new one releases the old one.
The default view is marked with a star in the strip so it is always clear which
lens you are looking through, and the address bar shows the filters that were
applied, so the page can still be bookmarked or shared.

Your default never overrides a deliberate choice. Following a link that already
carries filters, returning through a **← Back to** pill, or opening a URL
someone shared all show exactly what those addresses ask for. To see everything
unfiltered, use **Clear** in the filter bar — that escapes the default for the
visit rather than bouncing you back into it.

Available on the team, player and leaderboard attendance reports and the two
minutes reports. The lists gain it in a later release.

# TalentTrack v4.88.0 — Saved views: rename them, update them, and clearer confirmations (#2451)

Changing a saved view used to mean deleting it and saving a new one, which lost
its place in the list. Each saved view now carries a **…** button that opens a
small dialog where you can rename it, tick a box to replace its filters with
the ones you have set right now, or delete it — without losing anything else
about the view.

Saving a name you have already used on the same screen is now refused with a
message saying so, instead of quietly creating a second chip with the same
label that you cannot tell apart. The same name on a different screen, or the
same name used by a different person, is still fine.

The confirmation and error messages have moved from the browser's plain grey
pop-ups to the app's own dialog, so they are translated, readable to a screen
reader, and harder to miss. Deleting asks twice, because Delete sits next to
Save in the same dialog.

The single manage button replaces what would otherwise have been three small
icons per chip — at the size needed for comfortable tapping they did not fit
side by side on a phone, and a screen with five saved views would have carried
fifteen of them.

# TalentTrack v4.88.0 — Navigation layout is now a setting (#2456)

TalentTrack can now render its frontend in a persistent **app shell**: a grouped
navigation sidebar at laptop widths, collapsible to a strip of icons, and a
slide-out menu behind a ☰ button on smaller screens. The entries come from the
same registry that builds the tile overview, so everyone sees exactly the
sections their role already had — same names, same order, same permissions, now
always on screen instead of a trip back to the tile overview.

The layout is a choice at two levels. Academy admins set the default under
*Configuration → General → Navigation layout*; anyone can pick their own under
*My settings → Layout*, either following the academy or pinning a layout for
themselves. **Classic remains the default**, so nothing changes until someone
opts in, and switching back restores the previous chrome exactly.

# TalentTrack v4.88.0 — The player stays on screen while you scroll (#2457)

Under the app shell, a player's photo, name and team now stay pinned to the top
of their profile along with the section tabs, so scrolling a long Evaluations or
Measurements pane no longer leaves you wondering whose record you are in — and
you can switch section without scrolling back up.

The full player header still greets you on arrival; it is the slim strip
underneath that follows you down the page. Classic layout is unchanged.

# TalentTrack v4.88.0 — Jump to anything, and look without leaving (#2458)

Two additions to the app shell.

**Search.** A search box in the top bar — or ⌘K / Ctrl+K — opens a jump-to
overlay that finds sections, players, teams and activities from a few
characters. It opens showing the sections you can reach, so it works as a
launcher before you type anything. You only ever see records you already have
access to.

**Preview.** On a laptop, following a link to a player, team or activity from
somewhere else now opens a preview panel beside what you were reading instead of
navigating away. Check the detail, then either open it properly or close the
panel and carry on exactly where you were — no more losing your place and your
scroll position to answer a small question. On phones and tablets the link
navigates as before.

Both are app-shell only; classic layout is unchanged.

# TalentTrack v4.88.0 — Thumb-zone navigation bar on phones (#2459)

Under the app shell, phones now get a fixed navigation bar along the bottom of
the screen — four destinations plus **More**, which opens the full tile
overview. It sits in the thumb zone and clears the iOS home indicator, so the
things you reach for at the side of a pitch are one tap away instead of a trip
through the slide-out menu.

Which four you get is derived from your role: the first four everyday sections
you have access to, in the standard group order. Setup and configuration
sections are never placed there. The slide-out menu still carries everything, so
nothing is hidden — the bar is a shortcut, not a filter.

# TalentTrack v4.88.0 — Ratings grid: collapsed categories no longer pull the header off its columns (#2474)

Opening the ratings grid on an activity whose categories have sub-categories
showed a header detached from the data: the first main category stretched
across every score column and the ones after it sat over empty space. It hit
every not-yet-rated activity, because groups start collapsed until a
sub-category holds a score.

The main category headers were spanning their sub-columns even while those
were folded away. A folded column is removed from the table altogether, so the
extra width was columns no row ever filled, and each following group drifted
one block to the right. The header now spans what is actually on screen, and
follows along when a group is folded open or shut. A main category with no
score of its own keeps an empty placeholder column while collapsed, so its
label and expand toggle still have a column to sit over.

# TalentTrack v4.88.0 — Teams, activities and staff stay pinned while you scroll (#2479)

Under the app shell, team, activity and staff pages now keep a slim strip at the
top carrying the record's name and a line of context — the age group, the date,
the role. The full header still greets you on arrival and scrolls away; the strip
is what follows you down the page, so working through a long roster or an
attendance list no longer leaves you checking which record you are in.

Same treatment players got in the previous release, now shared rather than
rebuilt per page. Classic layout is unchanged.

# TalentTrack v4.87.3 — Explorer: relative date bounds now actually narrow the results (#2440)

The dimension explorer offered a relative date bound — its *Date after* box
even suggests `-30 days` — but nothing ever expanded it. The raw text went
straight into the query, where MySQL read it as `0000-00-00` and matched every
row, so the filter looked applied while quietly doing nothing. Four KPIs that
ship a 30-day default window were unbounded for the same reason.

Relative bounds are now resolved to a real date before the query runs.
`-30 days`, `-12 months` and `+7 days` all work, in `day` / `week` / `month` /
`year`, singular or plural. They stay relative: a saved explorer link keeps
meaning "the last 30 days" instead of freezing to the day it was saved.

A bound that is neither an exact date nor a recognised relative form — a typo
like `30 dayz ago`, or an impossible date like `2026-02-30` — is now dropped,
and the report renders without that bound rather than guessing at one. A filter
that silently narrows to the wrong window is harder to catch than one that
plainly isn't there.

# TalentTrack v4.87.3 — Save buttons follow your button colours again (#2446)

The shared Save button helper mishandled its own default. When a form didn't
name a button style explicitly — which is nearly all of them, 50 of the 55
call sites — the helper emitted a PHP warning and then rendered the button
without its `tt-btn-primary` class.

The visible consequence was that those Save buttons ignored the Buttons colour
settings under Design: instead of your configured button background, text and
hover colours, they fell back to the brand primary colour. On an install that
hasn't customised those tokens nothing looked wrong, which is why it went
unnoticed.

Save buttons now get the primary style whenever no style is named, so they
follow the Design settings like every other button. Forms that explicitly ask
for a secondary or danger button are unaffected.

# TalentTrack v4.87.2 — Ratings grid: category column headers now follow your language (#2430)

The ratings grid showed its evaluation-category column headers in English even
on a Dutch install, while the rest of the screen was translated. The grid's
read model was reading the stored category label straight out of the database
instead of resolving it the way every other evaluation surface does, so the
translation layer never got a look in.

Headers now resolve through the same display-time translator the evaluation
form, the evaluation detail view and the radar-chart legends use, which means
operator-maintained translations show up here too. A category nobody has
translated keeps its stored name, so nothing goes blank. Stored data is
untouched — scores still write against the category, never against its label.

# TalentTrack v4.87.2 — Ratings grid: out-of-range scores are flagged as you type, and can no longer fail silently (#2431)

A score outside the academy's rating scale used to be accepted by the grid,
dropped by the server, and then reported back as saved. The grid cleared its
unsaved markers and announced that all changes were stored, so a coach who
typed 12 on a 5–10 scale had no way to know the score never landed — and
because the rejected value became the new baseline, pressing Save again
wouldn't retry it either.

Scores are now checked against the configured scale as you type. An offending
cell is marked, the line under the grid says what the allowed range is, and
Save stays disabled until it's corrected. Nothing you typed is rewritten
behind your back: an out-of-range score stays on screen for you to fix rather
than being clamped, and a score that misses the scale's step is refused
instead of being quietly rounded to the nearest one.

The bulk ratings endpoint now reports refused cells separately from blank
ones, so a partial save is honest about what it did and didn't write. Valid
cells in the same batch still save, so one bad score can't cost a whole
squad's worth of typing.

# TalentTrack v4.87.2 — Ratings grid: main and sub categories are now visibly separate columns (#2432)

The grid's column headers were a single flat row, so there was no way to see
which columns were main categories and which were sub-categories underneath
them — the structure every other evaluation screen makes visible was lost
here. Worse, the columns were sorted on display order alone, which did not
keep a sub-category next to its own parent, so related columns could end up
scattered across the grid.

The header is now two rows. A main category spans its own block, its
sub-categories sit underneath it, and a main you rate directly keeps its own
column labelled *Main score* alongside them — so you can score at main level,
sub level, or both. Sub-categories are always adjacent to their parent, and a
separator marks where each main's block begins so the eye can track it while
scrolling sideways.

Sub-categories start collapsed and each main expands on its own, which keeps a
detailed methodology from spreading a squad across an unusably wide grid. A
main whose sub-categories already hold scores for that activity opens expanded,
so reopening a detailed rating shows what was entered rather than hiding it.
Collapsing never hides pending work: the header counts the unsaved scores
folded away, those scores still save, and a score outside the scale forces its
main back open because it blocks saving until corrected.

Keyboard navigation now walks the visible cells rather than counting header
cells, so the arrow keys stay correct across two header rows and after any
expand or collapse.

# TalentTrack v4.87.2 — Minutes reports: honest match count, and a filter bar on both (#2433, #2434)

The Team · Minutes distribution report could show a match count that
contradicted the squad beside it — "19 wedstrijden" next to an empty player
list. The match count was the only query on the page that carried none of the
exclusions its sibling queries carry, so archived, binned, cancelled and
not-yet-played fixtures all counted towards it.

The tile now reports what the report can actually account for. *Matches
recorded* counts the matches that produced recorded minutes — the same matches
the player bars are built from, so the two can never disagree — and carries the
honest denominator underneath: how many matches were played in the window. When
they differ the tile is flagged ("3 gespeelde wedstrijden hebben geen minuten"),
which names the gap as a recording gap rather than leaving a coach to guess.
Fixtures dated in the future no longer count as played. The counting rule moved
out of the view into `MinutesQuery::matchCountsForTeam()`, beside the
predicates it has to agree with, and is covered by tests.

Both minutes reports also gained the shared filter bar the other standard
reports have had since v4.80: period pills plus a manual From/To range. Every
figure follows the chosen window — KPI tiles, per-match rows, each player's
drill-down and the Explorer link. The default is unchanged at a rolling 12
months, so no existing number moves; the empty state's "widen the window"
advice is now something a user can actually act on. As a side-effect the
Explorer drill-through is bounded for the first time: it previously passed the
literal string `-12 months` as a date, which matched every row.

# TalentTrack v4.87.2 — Timestamps no longer render hours into the future (#2437)

Dates and times shown across the plugin were read as UTC and then printed
in the academy's timezone, adding the offset twice. On a Dutch install the
"Team last synced from Spond" line on an activity claimed a sync two hours
into the future — a sync at 22:24 printed as 00:24 the next day. The same
skew quietly affected the created/changed audit footer, PDP sign-off and
acknowledgement stamps, and the scout-report history.

Timestamps stored by the plugin are now read in the academy's timezone, so
they print the wall-clock time they were recorded at. Two columns that
genuinely hold UTC keep converting first: a scout link's expiry date now
shows the same moment the expiry check uses, and new scout-report rows
record their creation time in the academy timezone instead of whichever
timezone the database server happens to run in. Date-only values (activity
dates, evaluation dates) also stop slipping to the previous day for
academies west of UTC.

# TalentTrack v4.87.2 — Force a Spond re-sync straight from the activity (#2438)

An activity imported from Spond now carries a **Sync team from Spond**
button in its page header. A head coach who spots that an event moved in
Spond, or that the roster changed, pulls the team's calendar again on the
spot instead of waiting for the scheduled sync or asking an academy admin.

It re-pulls the team's whole calendar — Spond offers no way to re-fetch a
single event — so the button and its confirmation say "team", and the
change you were after may land on a different activity in the list. When
the team synced less than a minute ago the confirmation says so, so a
second click is an informed one. The button appears only for someone who
may manage that team's Spond connection: an academy admin for any team, a
head coach for their own. Archived activities don't show it.

# TalentTrack v4.87.1 — MFA QR codes now scan (#2425)

The QR code on the MFA enrollment step could not be read by any authenticator
app. The encoder wrote the 15 format-information bits in reverse order, so the
result was not a valid BCH(15,5) codeword — conforming scanners locate the
symbol, fail format validation, and stop before reading any data. Every QR
version the encoder can emit (v1–v10) was affected, so scanning has never
worked; only the manual-entry fallback did.

The fix is one expression in `QrCodeRenderer::writeFormatInfo()` — the bits are
now placed most-significant-first per ISO/IEC 18004 §7.9.1. The rest of the
encoder was already correct: data encoding, error correction, mask selection,
alignment patterns and version-info blocks all verified module-for-module
against an independent encoder.

The round-trip CI gate missed this because its decoder shared the encoder's bit
order — it read back LSB-first what the encoder wrote LSB-first, recovered the
right mask, and passed. Two encoders agreeing proves nothing when one wrote the
other. The verifier now reads the strip most-significant-first and additionally
asserts the format bits are one of the 32 legal BCH codewords encoding ECC
level L, and that the primary and mirror copies agree. That check needs no
third-party decoder and fails loudly if the bit order is ever reversed again.

Users who enrolled via manual entry are unaffected and need not re-enroll.

# TalentTrack v4.87.1 — MFA issuer no longer doubles the brand name (#2426)

On an install whose site name already opens with the brand, the MFA enrollment
step showed a doubled issuer — site name `TalentTrack Local` produced
`TalentTrack TalentTrack Local`, both on screen and inside the otpauth URI.
That string is what the user then sees as the account name in their
authenticator app, and re-enrolling is the only way to change it.

The guard matched only the exact string `TalentTrack`, so anything merely
starting with it fell through to the concatenation. A site name that already
begins with the brand is now used as-is; one that doesn't still gets
`TalentTrack ` prepended, and an empty site name still falls back to the bare
brand. As a side benefit the URI gets shorter — the issuer appears in it twice,
so the duplication was costing the QR-version budget double.

Existing enrollments are unaffected; the issuer is display metadata recorded by
the authenticator app at scan time, not part of the shared secret.

# TalentTrack v4.87.0 — Head coaches pick their team's Spond group themselves (#2399)

Connecting Spond for your own team stopped half-way: a head coach could save
the team's Spond login and test it, but linking the actual **group** still
happened on the team edit form, which most coaches can't open — so activities
didn't flow until an admin stepped in.

The **Spond connection** panel now includes the group picker. It appears once
the login works (listing groups needs a working Spond login, so before that the
panel says what to do rather than showing an empty dropdown), and the list is
cached for five minutes so re-opening the panel is instant.

If the group you pick is already linked to another team, the panel names that
team and warns you — then lets you save anyway. Two teams sharing one Spond
group is a normal setup for a combined age-group calendar; both teams simply
import the same events.

Access is scoped to the exact team, the same as the credential and test actions:
a coach can finish the setup for their own team and no one else's.

# TalentTrack v4.87.0 — Activities calendar keeps the filters you set on the list (#2400)

Switching the activities page to **Calendar view** used to reset the window: the
grid always showed its own default forward range and ignored the period, the
From/To dates and the activity Type you had scoped the list to. Now those carry
across, so the calendar shows the same activities over the same dates you were
just looking at.

Two things the grid states plainly rather than doing silently: it paints whole
weeks, so a window starting mid-week is drawn from that week's first day (never
less than you asked for), and with the period set to **All** there is no bounded
range to draw, so it falls back to the default forward window. The dates being
shown now appear above the grid either way.

The calendar stays a read-only glance — creating and editing activities is still
the list's job, and the editable planner keeps its own page.

# TalentTrack v4.87.0 — Archiving a team can archive its activities too (#2411)

Archiving a team used to leave its trainings and matches fully active, so a
retired age group's sessions kept turning up on planners, dashboards and
reports long after the team was gone.

The confirmation dialog now offers **"Also archive this team's N activities"**,
ticked by default, with the count taken from the team's still-active
activities. Untick it to archive the team on its own.

**Players are deliberately left alone.** A player outlives their team — they
move up an age group or transfer the same week — so their record stays active
and simply has no team until you assign one.

**Restoring the team brings those activities back**, but only the ones this
cascade archived: anything you had archived by hand beforehand stays archived,
so restoring a team never revives something you deliberately put away.

Upgrading also sweeps up the activities of teams archived *before* this
shipped, so they stop cluttering live views.

# TalentTrack v4.87.0 — Ratings grid: rate a whole squad on one screen (#2414)

A new **Ratings grid** completes the desktop entry grids (epic #2381). Open an
activity, click **Ratings grid**, and you get the squad down the rows and the
categories that activity is rated on across the columns — one score per cell,
typed directly, one Save for the lot.

It's deliberately per-activity rather than per-period like the attendance and
minutes grids. A rating isn't one number but a score per category, so a
players × activities grid would have to collapse several scores into one cell
and show a computed average instead of what you typed. Fixing the activity and
making the categories the columns keeps every cell a real score.

Details that matter in daily use: an empty cell means "not rated" and never
erases a score somebody already recorded; saving twice updates the player's
existing evaluation rather than creating a second one; edited cells stay
highlighted until you save; and arrows plus Enter move around the grid so you
can rate a category straight down the squad without touching the mouse.

The evaluation wizard and the evaluation form are unchanged — the wizard stays
the phone/pitch path, and notes and player feedback still live on the form. The
grid is desktop-only and can be switched off per academy under
*Modules → Activities → Ratings grid*.

# TalentTrack v4.86.1 — Updates: hourly release check + a "Check for updates" action (#2405)

TalentTrack now checks for a new release **every hour** instead of every 12
hours, so a fix reaches a pilot site the same morning it ships rather than up
to half a day later. A **Check for updates** action was also added to the
plugin's row on wp-admin → Plugins: it forces a check on the spot and reports
what it found — the version now available, or that the site is already up to
date. The action is limited to users who may update plugins.

# TalentTrack v4.86.1 — Modules can be marked "under development", and the dashboard tile says so (#2409)

The **Under development** marker now works at module level, not just per
feature: tick the checkbox on a module's card at *Modules* and every view that
module owns shows the informational pill. A core (always-on) module can be
flagged too — the marker gates nothing, so there is no reason to exempt it.

The marker also reaches the **dashboard tile** now. A tile shows a small amber
**Under development** badge when its own feature is flagged *or* when its
module is, so people see that a surface is still being built before they click
into it rather than after. The badge appears on the persona dashboard, the
classic tile grid, the "My work" rail and a parent's child tiles.

As before the flag is purely cosmetic — it never disables or hides anything,
and it is independent of the on/off switch, so a module can be live and flagged
at once. Only admins who can manage modules can set it; everyone sees the
result. It is stored per club on `tt_module_state` and is readable and settable
through the `/talenttrack/v1/modules` REST endpoint.

# TalentTrack v4.86.1 — Archived teams no longer appear in team pickers and dashboard tabs (#2410)

Archiving a team is supposed to take it out of day-to-day use, but until now it
only greyed the team out on the Teams list: the team kept appearing in every
team dropdown in the app — creating an activity, the coach dashboard's team
tabs, the planner picker, measurement and test-result pickers, PDP, match
execution, the role-grant scope picker and every analytics team filter. A team
sitting in the **recycle bin** showed up in all of them too.

Both shared team helpers now exclude archived and trashed teams by default, and
the hand-rolled team dropdowns were moved onto the same lifecycle vocabulary, so
a retired team disappears from all of these at once. Restoring the team brings
it back everywhere. Unchanged on purpose: the Teams list's own Archived tab, and
the team's own detail page, which must still open for a retired team.

# TalentTrack v4.86.1 — Recycle bin: the delete-impact preview no longer wrongly reports "nothing depends on this" (#2413)

Before a permanent delete the recycle bin shows what else the delete would
remove or clear. Two problems made that statement untrustworthy. The preview
was gated on the settings capability rather than the recycle-bin one, so an
admin who manages the bin could be refused it — and when the request was
refused, the dialog opened anyway and reported **"No other records depend on
this one."** even though the delete could cascade across eleven tables.

The preview is now gated on the same capability as the delete it precedes, and
a preview that fails for any reason no longer opens the dialog at all: the
error is shown and the delete cannot proceed without a successful preview.
Deleting a record whose impact really is nil looks exactly as it did before.

# TalentTrack v4.86.0 — Minutes entry grid — a desktop, spreadsheet-style companion to the attendance grid (#2386)

A new desktop **Minutes grid** (`?tt_view=minutes-grid`, reachable from the
Attendance/Minutes toggle on the grid surface) records match minutes for a
whole period at once: players down the rows, matches across the columns, a
minutes box per squad cell, one Save for the lot. It's the sibling of the
attendance grid (epic #2381), restricted to match activities.

Only players in a match's squad are editable; non-squad cells are hatched and
informational, mirroring the Minutes-audit matrix. Each edit is routed through
the same minutes-ownership arbiter the per-match editor uses — a match run
through match-execution keeps your figure as an override that survives a
recompute, while a paper match writes the minutes directly — so the grid, the
Minutes-audit tool, and the Minutes-played report always reconcile.

Gated on the `tt_edit_activities` capability and a new **Minutes grid** feature
toggle (on by default; switch it off to hide the grid and block its route; the
per-match minutes editor stays available). Also exposed over REST
(`GET /activities/minutes-grid`, `POST /minutes/bulk`).

Both grids are now also reachable straight **from an activity's detail page** —
an "Attendance grid" action on every activity and a "Minutes grid" action on
matches, each opening the grid for that team pre-filtered to the activity's
date, with a back-link that returns to the activity.

# TalentTrack v4.86.0 — Activities are completable again when the guided wizard is off (#2401, #2407)

Switching the guided attendance/evaluation wizard off left an activity with no
way forward: the **Complete activity** button vanished from the activity page,
its card in the list and the edit form, and nothing on the remaining path ever
marked an activity completed.

Both halves are fixed. With the wizard off, the completion button stays and now
reads **Mark attendance**, opening the desktop attendance grid on that
activity's own column; the dashboard's **Mark attendance** hero goes there too
instead of dropping the coach on an unfiltered activities list. A new **Mark
completed** action on a planned activity flips its status, so a wizard-off
academy no longer accumulates activities stuck at "planned" — which had been
quietly distorting the attention and up-next groupings. Recording attendance in
the grid deliberately does not auto-complete anything, because one grid save can
span weeks of sessions.

With the wizard switched on, nothing changes.

# TalentTrack v4.85.0 — Attendance reports: "This season" pill now spans the whole season (#2384)

The *This season* period pill on the attendance reports (team, player and
leaderboard) now covers the full season — from the season's start date
through the season's own end date — instead of stopping at today. Picking
the pill mid-season no longer silently truncates the window to the part of
the season that has already happened. The silent default window shown when
no pill or manual range is chosen is unchanged: it still runs season-start
through today, so reports stay retrospective by default.

# TalentTrack v4.85.0 — Reports: save a filter set as a named view (#2385)

Every standard report with the shared filter bar — the team, player and
leaderboard attendance reports and the minutes reports — now has a **Saved
views** strip above the bar. Set the filters you keep returning to, click
**Save current filters…**, name it (e.g. "U17 league games"), and it
becomes a one-click chip. Saved views are personal (only you see yours) and
belong to the report you saved them on. A period pill is remembered as a
relative choice (*This season* stays relative next month); a manual From/To
range is frozen to the exact dates. Presets live in a new `tt_saved_filters`
table (club- and user-scoped, with a uuid) and are managed over REST
(`GET/POST /reports/filter-presets`, `DELETE /reports/filter-presets/{id}`)
gated on `tt_view_analytics`.

# TalentTrack v4.85.0 — "Under development" pill for features (#2387)

Admins who manage modules can now mark any feature as **under development**
from the module/feature page (`?tt_view=modules`) with a checkbox beside its
on/off switch. When set, every view that feature owns shows a small,
informational amber "Under development" pill at the top, visible to everyone
(coaches, players, parents) so they know the surface is still being built and
may change. The flag is purely cosmetic — it never disables or hides
anything — and is independent of the on/off switch, so a feature can be live
and flagged at once. The flag is stored per club on `tt_feature_state` and is
readable/settable through the `/talenttrack/v1/features` REST endpoint.

# TalentTrack v4.85.0 — Head coaches connect their own team's Spond account (#2388)

A head coach can now link their team's own Spond account themselves, from a
**Spond connection** action on the team's page — save the team email +
password, test the login, and trigger a sync — without waiting for an
academy admin. Previously only an admin could connect Spond, on the
club-wide page.

Access is scoped to the exact team via change authority on the
`spond_integration` matrix entity (admin globally, head coach for their own
team). This also **closes a scoping hole**: the per-team Spond credential
endpoints previously gated on the any-team `tt_edit_spond_credentials`
capability, which let a head coach write another team's credentials; they
now require change authority on that specific team, and the affordance is
hidden for anyone without it.

# TalentTrack v4.85.0 — Spond sync: matches with no end time now default to kick-off + 105 min (#2389)

Spond match events frequently carry no end time, which left imported
**matches** with a blank end while trainings (which do carry ends) looked
right — the "end time is wrong only for matches" report. The kick-off +
105 minute default already used by the "+ New activity" wizard (#1863) was
never wired into the Spond sync. Now, when a Spond match gives a start but
no end, the sync fills the end with kick-off + 105 min (clamped to
end-of-day for a very late kick-off). A real Spond end always wins — the
default only fills the blank — and trainings are unaffected.

# TalentTrack v4.85.0 — Activities: switch between list and calendar view (#2390)

The activities page now has a **Calendar view** toggle in the header that
swaps the chronological list for a week-grid calendar — the same read-only
grid the Team Planner uses, days as columns, one row per team — and a **List
view** button to swap back. The choice is remembered per user. The calendar
honours the same team scope as the list, narrowing to one team when a
`?team_id` filter is set. It's a read-only glance; creating and editing
activities stays on the list and the activity form, and the full editable
planner remains on its own Team planner page. Reuses the Team Planner's
condensed multi-team grid rather than adding a second calendar.

# TalentTrack v4.84.0 — Attendance entry grid — a desktop, spreadsheet-style alternative to the wizard (#2382)

A new desktop **Attendance grid** (`?tt_view=attendance-grid`, reachable from
the Activities screen) lets a coach record attendance for a whole period at
once, the way an Excel register works: players down the rows, activities
(training + matches) across the columns, one dropdown per cell, one Save for
the lot. It is the power-entry alternative to the step-by-step
mark-attendance wizard (epic #2381) — the wizard stays the mobile/pitch path.

Columns come from the team's active roster, so a brand-new activity still
shows every player; a per-column "all present" fills a whole session in one
click; the full five statuses (present / late / absent / excused / injured)
are all available, shown as an abbreviation in the cell and the full word in
the dropdown. Edits are tracked and written in one batch, and the grid
reads/writes the same recorded attendance the reports and the wizard use, so
everything reconciles.

Gated on the `tt_edit_activities` capability and a new **Attendance grid**
feature toggle (on by default; switch it off to hide the grid and block its
route). Also exposed over REST (`GET /activities/attendance-grid`,
`POST /attendance/bulk`) for a future non-WordPress front end.

# TalentTrack v4.83.1 — My activities list: 2026 FilterBar chrome (#2074)

The player and parent **My activities** list now reads as crisply as the staff
Activities list. Its filter row already renders through the shared 2026 filter
bar (a single inline row on tablet and desktop, a bottom sheet on phones); this
release brings the table itself up to the same standard, giving the column
headings the 2026 small-caps treatment and adding a subtle row hover. Your own
attendance status (Present / Absent / …) stays the list's status column. No
change to what you see or how filtering works — only the polish.

# TalentTrack v4.83.1 — Lineup card: two-column, styled Starting XI / Bench (#2232)

The activity/match detail line-up card now presents Starting XI and Bench
side-by-side on tablet and desktop (≥768px) and stacks to a single column
on phones. Each player renders as a structured row — jersey number, name
(first name + last initial, matching the sideline convention), and a
position chip using the resolved short codes — consistently aligned and
spaced. Group headings carry a player count. No raw JSON positions, no
horizontal scroll at 360px.

# TalentTrack v4.83.1 — Printable methodology reference card follows the active methodology (#2376)

The printable methodology reference (`?tt_methodology_ref_print=1`) now reflects the **active methodology set** instead of merging every shipped set onto one card. It reads through the scoped repositories, so the Spelprincipes, Voetbalhandelingen and Leerdoelen pages show exactly the methodology the read view shows — and JO13-1's `VD`/`AV` principles, previously dropped because the card bucketed by the JO14 code prefixes, now render (principles are grouped by team-function and team-task instead). A club's own (non-shipped) active set prints too.

# TalentTrack v4.83.0 — Team · Minutes distribution: fix "18 matches / 0 players" (#2339)

The Team · Minutes distribution standard report resolved its squad from
`tt_players.team_id` while counting matches from the team's activities, so a
team whose players had no `team_id` set showed a match count but zero players
and no minutes. The squad is now derived the same way the rest of analytics
resolves a team — players with recorded attendance on the team's
match / game / tournament activities — so the player list and the match count
share one team-membership definition, and a player appears even with 0 recorded
minutes. Minutes still come only from persisted `record_type='actual'`
attendance rows (never estimated), so a match with no recorded minutes
contributes 0.

# TalentTrack v4.83.0 — Standard reports: shared filter bar + season-default window (#2345)

Team · Squad evaluation summary, Season summary, Season · Trial funnel and the
Scout report card now carry the shared filter bar — retrospective period pills
(Last week / This month / This season) plus a manual From / To range — with the
same season-default window the attendance and minutes reports use (current
season start → today, 90-day fallback). Each report's query, page sub-line and
Explorer drill now follow the selected window, replacing the hardcoded rolling
6- / 12-month bounds.

# TalentTrack v4.83.0 — Standard reports: auditability drill-downs on KPIs (#2356)

KPI tiles on the standard reports now drill to the filtered list they count:
Team · Minutes distribution's Players tile opens the team roster and its Matches
tile the activities list filtered to that team's matches; Season summary's
Active players / Active teams / Matches tiles open their lists; the Trial funnel
Prospects logged tile opens the prospects list. Every drill carries a
"← Back to …" hint and is hidden when the viewer lacks the destination's
capability.

# TalentTrack v4.83.0 — Minutes-audit per-match editor (#2367)

The Minutes-audit tool gains an editable per-match surface. From the audit
overview, admins and head coaches can now open a match and correct the
recorded minutes per player, writing the authoritative recorded value — the
same source the reports and the match-execution screen read, so a match
opened in match-execution after an audit edit reflects the changed numbers.

The editor routes each write automatically: a match with a match-execution
takes an explicit per-player override that survives every recompute and
stays correctable once finalized; a paper match writes the recorded minutes
directly. Editing is conservative — an emptied field is an explicit clear,
never a silent zero, and untouched rows are never written. The overview's
edit link is hidden for users who cannot save.

The audit overview now reflects effective minutes
(`COALESCE(minutes_override, minutes_played)`), consistent with the minutes
report and the minutes-authority arbiter.

This first version edits total minutes per player; editing the starting
line-up per half and the substitution log is a planned follow-up.

# TalentTrack v4.82.2 — Edit attendance on a completed activity, and see the roster without opening Edit (#2371)

The activity detail page now has a collapsible **Show roster** list under the
attendance breakdown — every registered player with their status (guests
tagged), so you can see who had which attendance without opening the edit
form.

A completed **training** now also carries an **editable attendance table** on
its edit form: correct a missed or wrong status (and note) per player and hit
Update activity. This restores the flat-form path as the fallback for when the
guided wizards are switched off — previously, with wizards disabled, recorded
attendance could not be corrected at all. Reuses the existing recorded-
attendance write path (no new write logic); match-type activities keep their
minutes-aware completion flow. The completed-activity wording no longer implies
attendance is still to be captured.

# TalentTrack v4.82.0 — First-class sub-principles + JO13-1 formation diagram fix (#2369)

Methodology sub-principles are now a first-class entity: the concrete per-line
coaching points that support each main principle (grouped by game phase, then
by line — aanvallers / middenvelders / verdedigers / algemeen). They have their
own read surface under the **Spelprincipes** tab, a **Sub-principes** authoring
tab, and full REST CRUD at `/methodology/sub-principles`. The JO13-1 Hedel set
ships with its complete per-line sub-principes seeded from the playing-style
document. Separately, the JO13-1 **1-4-3-3** formation now renders correctly on
both the Formaties and Visie tabs — its diagram coordinates were missing, so it
previously fell back to a generic shape.

Wizard plan: exemption (a) — a sub-principle is a lookup-like, single-line
coaching note authored under an existing principle/phase, so it takes the flat
inline-editor path like the other methodology vocabulary tabs; a multi-step
wizard would add friction without value.

# TalentTrack v4.82.0 — Match execution: minutes-authority arbiter, tracked players, frontend cleanup

Rebuilt the match-execution surface to remove the "semi-connected parts"
friction:

- **Minutes have one owner.** When a match has a running/recorded execution,
  its per-player minutes are derived from the starting XI + substitution log,
  and the only way to hand-correct a figure is the per-player override on the
  match-execution screen (Recorded minutes → Correct). The manual minutes
  field on the attendance screen now defers to the execution (it reports that
  minutes are managed there). Matches that were never run through execution
  keep manual minute entry exactly as before.
- **Tracked players.** Players flagged in the match plan (a specific goal or an
  attention note) get a live +/- counter during the match to tally a
  development action. These are recorded as their own timed events — separate
  from goals, so they never affect the score.
- **Cleaner, faster surface.** The four match-execution stylesheets are
  consolidated into one, and the last inline styles and scripts were moved out
  of the page into the enqueued sheet and JS module.

# TalentTrack v4.81.0 — Minutes-audit overview: read-only games × players matrix (#2368)

New **Minutes audit** report (Reports launcher → *Playing time*, or
`?tt_view=minutes-audit`): a read-only games × players auditability matrix that
makes recorded-minutes gaps obvious. Rows are the team's games in the window,
columns are the squad (resolved from attendance on those games, not the player's
team assignment), and each cell shows the minutes recorded for that player —
green for recorded, red for on-squad-but-zero, hatched for not-in-squad. Every
row carries a total and a completeness chip (Complete / Incomplete / Not
recorded), with a column-total footer. Four clickable gap KPIs (Games, Fully
recorded, Incomplete, Not recorded) summarise and filter the matrix. Each row
deep-links to the game's activity detail to record its minutes.

The audit reads the same recorded, actual, non-guest minutes as the minutes
report, so its numbers reconcile exactly; a team with games but no recorded
minutes shows an honest "not recorded" state rather than a misleading "0
players". Reachable via a REST read endpoint (`GET /reports/minutes-audit`) gated
on `tt_view_analytics` plus the `report_minutes_audit` toggle, with the caller's
team scope enforced.

# TalentTrack v4.80.0 — Manage methodology sets + per-team selection (#2320)

Academies can now manage their methodology sets from the frontend. A new **Speelwijzen** tab leads the methodology manage surface: it lists every set (with Actief and Shipped badges), creates and renames sets through a flat multilingual form, makes any set the install-wide active one with a single "Maak actief" action, and archives sets it no longer needs — refusing to touch shipped reference sets or strand the install with zero methodologies. Each team can override which set it uses through a new "Methodology set" dropdown on the team edit form, defaulting to the install-wide active set. The same operations are exposed over REST at `/methodology/sets`, including `PUT /methodology/sets/{id}/default`, so a future SaaS front end gets identical answers.

Wizard plan: exemption — methodology set is a single-record named container, analogous to a lookup/vocabulary edit (§3 exemption a).

# TalentTrack v4.80.0 — Second shipped methodology: JO13-1 Hedel (#2321)

TalentTrack now ships a second methodology alongside the default JO14-1 Hedel (1-4-2-3-1): JO13-1 Hedel, a 1-4-3-3 playing style converted from the club's "Speelwijze Jeugd 13-1 Hedel" document. It carries its own vision, formation with eleven position cards, sixteen coded principles (defending, both transitions and attacking), a framework primer with its phases, and learning goals — all scoped to the new set so its content stays isolated from the default. Building on the selectable-methodology foundation (epic #2316), a team can be pointed at whichever set fits its playing style, and the Methodology tabs then show that set's content. Shipped content remains read-only; clone an entry to edit your own copy.

# TalentTrack v4.80.0 — Methodology periodisation combined with VCT week-cycle (#2322)

The VCT macro-block week schedule now carries an optional per-week speelwijze theme (`tactical_theme`) alongside the existing conditioning phase and intensity multiplier, reusing the canonical `vct_tactical_theme` vocabulary. A new "Periodisering" tab on the methodology library reads the club-default cycle for the current season and shows, per week, the speelwijze theme + conditioning phase + intensity — the single surface that combines the methodology and VCT views. The VCT configuration tile gains a per-week theme picker inside each block's advanced editor, and a JO13-1 5-week speelwijze reference template ships as a seed. Feeding the per-week theme into VCT exercise selection is a deliberate follow-up and is intentionally out of scope here.

# TalentTrack v4.80.0 — Animated per-phase tactical scenes (#2323)

The methodology library gains a **Speelwijze** tab with animated per-phase tactical scenes: an SVG pitch that plays out player and ball movement for each game phase, with coaching points alongside. Scenes are grouped by aanvallen / verdedigen / omschakelen and ship for the JO13-1 Hedel set. Play / Pause / Restart controls are keyboard-accessible and honour reduced-motion (no autoplay — the final frame renders statically). Scenes are authorable over a new REST resource (`/methodology/tactical-scenes`); an in-app drag-and-draw scene editor is a planned follow-up.

Wizard plan: exemption — this ships no in-app record-creation flow. Scenes are shipped seed content, read-only in the app; creation is REST-only for a future editor. No "+ New" affordance, so no wizard applies (the drag-and-draw editor, when built, gets its own wizard decision).

# TalentTrack v4.80.0 — Clickable KPI tiles on the standard reports (#2343)

The standard-reports KPI strip can now turn a tile into a drill-down link:
each KPI accepts an optional `href` (and an optional `cap` to gate it, hiding
the link for viewers who lack the capability). Tiles without an `href` render
exactly as before, so no existing report changes. The clickable tile gains a
visible keyboard focus ring and keeps its 48px touch target.

# TalentTrack v4.80.0 — Honest, context-aware empty states on the standard reports (#2344)

When a standard report has nothing to show it now says why in plain terms —
"No matches recorded in this period", "No evaluations recorded for this team in
this window", "No prospects logged in this window", and so on — instead of the
old generic "adjust a filter and try again" copy (most of these reports have no
filter to adjust). The Season summary no longer renders a blank page below its
headline tiles when no teams exist.

# TalentTrack v4.80.0 — Standard-reports query fixes: archived join, honest window + cap, last-evaluated date (#2346)

Three mechanical corrections to the standard reports. The Season summary's
per-team match counts now exclude soft-archived activities on the join itself,
not just in the count, removing a source of inflated joins (values are
unchanged). Player · Minutes played now states its 12-month window in the page
sub-line and surfaces the "showing the 50 most recent matches" cap so a longer
history is never silently dropped. Team · Squad evaluation summary shows a
**Last evaluated** date per player so a stale row is visible at a glance.

# TalentTrack v4.80.0 — Trial funnel reconciles: pending row, window label, scout links (#2347)

The Season · Trial funnel's Per-decision table now lists the outcomes of cases
opened in the window plus a **Pending (not yet decided)** row and a **Total**
row that sums to *Trial cases opened*, so the breakdown reconciles. The
Decision rate tile carries a one-line note that its numerator (cases decided,
by decision date) and denominator (cases opened, by open date) use different
windows. Each scout name in the Per-scout table links to that scout's Scout
report card, gated on the same `tt_view_reports` capability the card enforces.

# TalentTrack v4.80.0 — One shared per-match minutes breakdown component (#2348)

The per-match minutes breakdown table used by the Team · Minutes distribution
report and the Analytics minutes-played report is now a single shared component
(`MinutesBreakdown`), replacing two near-identical copies that had already
drifted in markup. Both reports render identical rows that still reconcile
exactly to the player's total. Presentation-only — no query or data change.

# TalentTrack v4.80.0 — Minutes-played (team) report: shared filter bar + KPI strip (#2349)

The Minutes-played (team) report now uses the shared filter bar (team,
retrospective period pills — Last week / This month / This season — a match-type
select and a manual From/To range) and the shared KPI strip, matching the
attendance reports. The default window is the current season; on a phone the
filters collapse into the standard bottom-sheet with 48px touch targets. The
report's one-off stylesheet was trimmed to the few genuinely report-specific
rules that remain.

# TalentTrack v4.80.0 — Attendance leaderboard: filter + chrome parity (#2350)

The attendance leaderboard now shares the same filter bar and chrome as the player attendance report: a team picker, retrospective period pills, an activity-type filter and a manual date range, plus the leaderboard's "How many" cap. Opening it with no filters defaults to the current season. A KPI strip above the tables summarises the ranked players (total, average attendance, at-risk count), computed from the data already fetched — no extra query. Flagged players in the "Needs attention" table keep their missed-count badge, and the empty-state messages now say what to try next.

# TalentTrack v4.80.0 — Attendance reports: type filter + at-risk drill-down + season-pill state (#2351)

The team and player attendance reports now surface the silently-seeded
season-default window as an active **This season** pill instead of reading
"Custom range", so the filter bar reflects the window you're actually looking
at on first open. When a coach only sees the teams they're assigned to, the
empty-state message now says the report is limited to those teams, so an empty
window no longer reads as "the academy has no data". On the player report the
inline at-risk ⚠ badge — and each name in the At-risk players panel — is now a
link that drills to the player's missed-activities list (this player, the
report's team, the report's window), matching the existing Activities-count
drill-down and carrying a back hint to the report.

# TalentTrack v4.80.0 — Team ratings report: fix N*M query fan-out (#2352)

The admin Team rating averages report now computes its numbers with two grouped database queries instead of one query per team and per category cell. On academies with many teams and categories this cuts the report from dozens of queries to two, so the page loads noticeably faster. The displayed averages and evaluation counts are unchanged.

# TalentTrack v4.80.0 — Coach activity report: club scope guard + name fallback (#2353)

The Coach activity report now scopes its per-coach evaluation counts to the current club, so it can never surface a coach from another academy in a multi-tenant install. Coaches whose user account has been deleted are labelled **Unknown coach** instead of a raw account number, while still keeping their saved evaluations in the count.

# TalentTrack v4.80.0 — Explorer: visible row-cap notice + filter validation (#2354)

The dimension explorer now surfaces its hidden 5000-row cap: when a drill-down hits the limit, a notice under the table tells the user the tail is being dropped and to group the data to aggregate larger sets. Filters are also validated against the KPI's declared explore dimensions — a filter for a dimension the KPI doesn't offer is ignored instead of being applied, so the filters shown on screen always match the ones applied to CSV/PDF exports.

# TalentTrack v4.80.0 — Usage statistics: season default, truncation labels, better empty states (#2355)

The Application KPIs dashboard now defaults to a season-aware window instead of a fixed 30 days, picking the smallest period that spans the running season so far. Truncated tables (Active users, Dormant users) carry a "(Showing top N)" label so it's clear the list is capped, not complete. A collapsible "How these numbers are measured" note explains that stickiness is always a 30-day MAU ratio, that visits end after 30 minutes idle, and that observed time online is a lower bound. Role labels now render as shared role chips, and the empty states for "Top features used" and "Dormant users" suggest a next action.

# TalentTrack v4.80.0 — Reports launcher: honest empty state when no tiles are available (#2357)

When every report tile was filtered out — all reports switched off for the academy, or none within the viewer's scope — the Reports launcher rendered a blank grid with no explanation. It now shows a clear notice explaining that no reports are available and pointing the user to ask an administrator to enable a report or widen their scope. When any tile survives the filtering, output is unchanged.

# TalentTrack v4.79.0 — Centralized cross-view link authorization affordances (#2304)

Cross-view navigation links, tiles and buttons that point at another view are now hidden through one shared helper (`CrossViewLink`) backed by a registry that mirrors each target view's actual access guard, instead of hand-rolled inline capability checks that drifted from the destination. The measurements execution links (Manage tests, Record measurements, Testing coverage), the team-detail Planner link, the team-development chemistry and blueprint tiles, the activity methodology link, and the player "Chemistry attributes" action all route through it — same users see each link, with the player-attributes entry now correctly tightened to the per-player evaluation check the target enforces. A new diff-only CI gate stops future cross-view links from skipping the helper.

# TalentTrack v4.79.0 — Methodology sets — schema foundation (#2317)

Internal schema groundwork for selectable methodologies (epic #2316). A new `tt_methodologies` table makes a methodology a first-class, named set, and every methodology entity (principles, vision, formation, phases, learning goals, influence factors, set pieces, football actions, framework primer) gains a `methodology_id` linking it to one. Existing shipped content is backfilled into a default "JO14-1 Hedel" set, so nothing is orphaned and the read view is unchanged. No user-visible behaviour yet — selection and the second methodology land in follow-ups.

# TalentTrack v4.79.0 — Methodology sets — per-team selection + install default (#2318)

Adds the resolution layer for selectable methodologies (epic #2316): an install-wide default set stored in `tt_config` (`active_methodology_id`) plus an optional per-team override (`tt_teams.methodology_id`). A new `ActiveMethodologyResolver` picks the set for a given team — team override, then install default, then the club's default set — degrading gracefully to legacy behaviour before the tables exist. No user-visible surface yet; the read view and admin selector consume this in follow-ups.

# TalentTrack v4.79.0 — Methodology sets — content scoped to the active set (#2319)

The methodology library, its repositories and the authoring REST endpoints now read and write within the active methodology set (epic #2316). A new ambient `MethodologyScope` — parallel to how club tenancy already works — makes every list read and every create resolve to the install's active set by default, so the read view shows one methodology at a time and new content is stamped into it. REST callers can scope to a specific set with an optional `methodology_id` query param. With a single set installed there is no visible change; it's the switch that lets two methodologies coexist without their content bleeding together.

# TalentTrack v4.79.0 — Hide "Complete activity" when the user can't complete it (#2325)

The "Complete activity" button on the activity detail page (and the quick-action on planned activity cards) is now hidden when the current user can't reach the completion flow, instead of rendering a dead button that silently reloaded the page. Completing a training or paper-match routes through the evaluation wizard (which needs evaluation rights); completing a match with a running match-execution routes through its finalize view (which needs activity-edit rights). The gate now mirrors whichever destination applies, via a domain-layer `ActivityCompletionResolver::canComplete()` used by both buttons. Head coaches and evaluators are unaffected; assistant coaches who can't evaluate no longer see a button that does nothing.

# TalentTrack v4.79.0 — FilterBar: filters no longer revert on Apply (#2327)

The shared filter bar renders each control twice inside one form — a desktop inline row and a mobile bottom sheet — both carrying the same field name. On submit the browser sent both values and PHP kept the stale sheet copy, so editing the Date range From/To or changing the Team/Type select silently reverted on Apply. The change-sync that #2201 added for toggle checkboxes now covers every control: date inputs, text inputs and selects mirror their value onto the same-named sibling before the form submits, so the inline and sheet copies always agree. Progressive enhancement and the JS-off Apply fallback are unchanged.

# TalentTrack v4.79.0 — Reports default to the current season's date window (#2328)

The standard reports (player attendance, team attendance, the attendance leaderboard and team minutes) now seed their From/To filter to the current season — from the season's start date through today — when you open them without a period pill or a manual range. This matches the *This season* pill and how the academy thinks about the year, instead of an arbitrary rolling window that spanned season boundaries. When no current season is configured the reports fall back to the previous 90-day window, so they never render empty or fatal. Period pills and manual From/To ranges still override the default. The default now lives in one shared helper (`ReportFilters::seasonDefaultWindow()`) so the four reports can't drift.

# TalentTrack v4.79.0 — Archived-record actions hidden without permission + correct confirm titles (#2330)

The archived/trashed record card now hides lifecycle buttons whose REST route the
current user can't reach: "Move to recycle bin" only shows for users who can manage
settings, and "Restore to archive" / "Delete permanently now" only for recycle-bin
managers. Head coaches no longer hit a dead-end "Action failed." on an archived
record. The confirm-modal title now matches the action ("Move to recycle bin",
"Restore record", "Delete permanently") instead of always reading "Archive record".

# TalentTrack v4.79.0 — Players list: fix count/rows mismatch and unreachable players (#2331)

The players list could show fewer rows than its own total (e.g. "1–15 of 15" while only 11 rows rendered), and players sorted past the first page were unreachable. Cause: per-player view permission was applied *after* SQL pagination, so a page under-filled and authorized players beyond it were both miscounted and unpageable. The list endpoint now authorizes the full result set first and paginates the authorized players, so the total always matches the rows you can page through and every player you may see is reachable. No change to which players a user may view.

# TalentTrack v4.78.4 — Hide unauthorized navigation affordances across seven views (#2306, #2307, #2308, #2309, #2310, #2311, #2312)

Buttons and links that pointed at capability-gated destinations are now hidden from users who lack the matching capability, instead of leading to a "you are not authorized" dead end (CLAUDE.md §7). Affected affordances: the "New player" and "New team" header buttons (require the respective edit capability), the "Manage tests" link and the "Record measurements" / "Testing coverage" cross-links on the measurements surfaces (each gated on its own measurement entity), the "Team chemistry" / "Team blueprints" tiles on the team edit form (team-chemistry read access), the "Planner" link on the team detail page (plan-view access), and the "Methodology" library link plus principle pills on the activity detail card (methodology-view access — the linked principles still display, just not as links). Each affordance now checks the same capability its target already enforces; the server-side gates are unchanged.

# TalentTrack v4.78.3 — Search box on the Modules & features page (#2300)

The frontend **Modules & features** page (`?tt_view=modules`) now has a
search box at the top that filters the module cards and their nested feature
toggles live as you type, matching on name or description. A match inside a
feature auto-expands its module; empty categories drop out and an empty-state
line shows when nothing matches. With dozens of per-report and per-export
toggles, finding a specific one no longer means scrolling the whole list.
Client-side only — no reload, and the full list still renders with JavaScript
off.

# TalentTrack v4.78.3 — Player comparison and Podium are now switchable features (#2302)

The **Player comparison** and **Podium** analytics tiles can now be turned
off per academy from the Modules & features page (`?tt_view=modules`), the
same way as the other analytics surfaces. Both ship **on**, so nothing
changes on upgrade; switching one off hides its dashboard tile and blocks a
direct link to its `?tt_view` route. Until now these two tiles were
hard-wired on and had no toggle.

# TalentTrack v4.78.3 — Upgrade dompdf to 3.x (security) (#2313)

Bumped the `dompdf/dompdf` dependency from `^2.0` to `^3.0`. Every 2.0.x
release is now flagged by published security advisories, which blocked
`composer install` in CI. dompdf 3.x carries no advisories and still supports
PHP 7.4, so the plugin's minimum PHP is unchanged. PDF export behaviour is
unaffected — the renderer uses only the stable dompdf API.

# TalentTrack v4.78.2 — Holidays: "New holiday" button always available on the list (#2290)

The academy Holidays list now shows a persistent "New holiday" button in
its header, gated on the manage-holidays capability. Previously the only
create affordance was the empty-state card, which disappears once at least
one holiday exists — so a manager with full rights had no visible way to
add another and had to reach the wizard by URL. The empty-state CTA is
unchanged.

# TalentTrack v4.78.1 — Fixed the live match surface so Finalize, Re-open and the late goal/substitution forms work again. They were hitting a 404 (`/undefinedfinalize`) because the inline script read its REST config before the footer defined it; the config is now resolved when the button is tapped. (#2288)

Fixed the live match surface so Finalize, Re-open and the late goal/substitution forms work again. They were hitting a 404 (`/undefinedfinalize`) because the inline script read its REST config before the footer defined it; the config is now resolved when the button is tapped. (#2288)

# TalentTrack v4.78.0 — Spond: per-team accounts that override the club login (#2286)

A team can now sync with its own Spond account instead of the club-wide one.
Each team on the Spond page shows which account it uses ("Uses club account" or
"Own account: <email>"); expand its Account panel to set a per-team email +
password, which overrules the club login for that team's syncs. Leave the email
blank (or hit "Use club account") to fall back to the club account. Per-team
passwords are encrypted at rest and each team keeps its own cached token; the
resolution (`CredentialsManager::forTeam`) is the single seam the sync,
preview and monitor all use.

# TalentTrack v4.77.0 — Spond integration monitor — see live what the Spond API returns (#2284)

New diagnostic page (Spond → **Open integration monitor**) that fetches the Spond
API **live** for a team and shows exactly what's coming in — every event with its
classified type, date, times and location — plus a per-event **diff**: whether a
real sync would create it, or update an existing activity (and precisely which
fields it would overwrite), and which stored activities would be archived. It is a
**dry run**: nothing is written. This is the tool for answering "why does the
printed activity differ from what I set in Spond?" — a stale cache, a changed
event UID, or a field Spond owns all become visible at a glance.

# TalentTrack v4.76.3 — Week plan: keep the printed "Aftrap" (kickoff) in step with the activity's start time (#2282)

The weekly team-plan print shows a match/tournament's kickoff ("Aftrap") from the
`kickoff_time` field, but the activity edit form only ever wrote `start_time`
("Begintijd"). For a Spond-imported match — where Spond had seeded `kickoff_time`
— editing the start time in TalentTrack left the printed kickoff stale, so the
print disagreed with the form. Saving a game or tournament now mirrors the start
time into `kickoff_time` (and clears it for non-match types), so the two always
match. (This does not change Spond's re-sync behaviour, which still owns the
schedule fields.)

# TalentTrack v4.76.2 — Match execution: bench above the timeline, inline Undo, clearer "Match goals" label (#2280)

Polish on the after-match review:

- The **Bench** now sits directly above the **Squad timeline**, so a player's
  bench status reads right next to their played-minutes bar.
- In edit mode the **Undo** ("Ongedaan maken") button now sits inline at the end
  of each event row instead of wrapping onto its own second line under every
  goal and substitution.
- The Dutch label for the scored-goals section changed from the ambiguous
  "Wedstrijddoelen" (reads as match *objectives*) to **"Doelpunten"**.

# TalentTrack v4.76.1 — Match execution: fix the redesign's cards, bench minutes and opponent-goal feed (#2278)

Follow-up polish on the v4.76.0 match-execution redesign, verified against the
mockups with a headless render:

- The **bench** and **tracked-players** sections now actually show their pastel
  card backgrounds (yellow / green) — the 2026 chrome sheet was overriding them
  with white.
- A bench player's **minutes** now sit inline on the right instead of dropping
  onto their own line, and the **↑ Bring on** button no longer wraps to a second
  row in edit mode.
- An **opponent goal** in Live progress now reads "Opponent goal · <team>" with a
  distinct grey chip instead of a blank "Goal", and the **running score counts
  each side separately** — an opponent goal no longer bumps our tally.
- After a match the review screen still opens read-only, but the **Edit button is
  now prominent** (filled) so the correction controls are easy to find.

# TalentTrack v4.76.0 — Match execution: post-match squad timeline, contained cards, paired swaps, opponent goals (#2273)

The after-match review now leads with a **Squad timeline** — one 0'→full-time bar
per player showing on-pitch (green) versus bench time, substitution in/out markers,
own-goal marks and minutes played, grouped into the starting XI and the bench.

Live-match cards are now visually contained: the bench reads as a pastel-yellow card
and tracked players as a soft-green card. When a player is substituted off, their bench
row shows a transient "just came off" pill and a "Just came off for …" line that clears
after a minute.

Substitutions in the live progress feed render as a paired ▲ on / ▼ off card. In the
after-match review the coach can now add, remove, or correct the minute of an opponent
(away) goal; the away score stays in sync.

# TalentTrack v4.75.0 — Match execution: correct a substitution's minute after the match (#2273)

Coaches often log a substitution a little late, so the recorded minute is
wrong — and because playing minutes are derived from the substitution times,
that skews both players' totals. With Edit on, every substitution in the Live
progress feed now shows a **Correct minute** stepper. Changing it saves the
corrected minute and re-runs the minutes calculation, so the player who came
off and the player who came on both move to match. You fix the *time* of the
event; the minutes follow — you never edit minutes directly. The corrected
minute is range-checked and blocked once the match is finalized, the same as
every other post-match edit. New `PATCH /match-execution/{id}/substitution/{uuid}`
endpoint backs it.

This is the first slice of the match-execution redesign (#2273); the squad
timeline, contained bench/tracked cards and timed opponent goals follow in
their own changes.

# TalentTrack v4.74.0 — Match execution: timer no longer keeps running after the match ends (#2267)

The live-match screen now understands the real post-match states
(pending review and finalized) instead of a legacy value the server never
sends. On a finished match the clock stops, the state pill and the sticky
bottom action read correctly, and a reload stays in step with the server.

# TalentTrack v4.74.0 — Match execution: reject impossible subs and out-of-range minutes (#2268)

Logging a substitution now checks the roster on the server: you cannot take
off a player who is not on the pitch or bring on a player who is already on.
Goal and substitution minutes outside the match length (plus a short
stoppage allowance) are rejected instead of being silently clamped. The
same checks run in the browser so a mistake is caught before it is sent.

# TalentTrack v4.74.0 — Match execution: undo a substitution, reload-safe goal/sub undo (#2269)

Every logged goal and substitution in the Live progress feed now carries an
inline Undo that works even after a page reload, because it is keyed to the
stored event rather than a short-lived tap memory. A just-logged
substitution can also be undone straight from its confirmation toast.

# TalentTrack v4.74.0 — Match execution: sideline robustness polish (#2270)

Small reliability fixes on the live-match screen: a failed goal-undo rolls
the count back instead of drifting, the late-event forms cannot be
double-submitted, the timer stops the instant you finalize, and the header
meta line wraps rather than clipping the team names on a very narrow phone.

# TalentTrack v4.74.0 — Match execution: adjust every datapoint after the match (#2271)

After a match ends you can now correct every measured datapoint — score,
substitutions, goals and minutes — from the post-match review state, and
corrections re-run the minutes calculation so the reports stay consistent.
A finalized match is no longer a dead-end: a new "Re-open for corrections"
action returns it to review so any datapoint can still be fixed. Re-opening
is capability-gated to the same coaches who edit the match, and is recorded
in the audit log.

# TalentTrack v4.73.5 — Reopen / Cancel confirm dialog now shows the right title (#2265)

The confirm dialog for an activity's Reopen and Cancel actions showed the
title "Archive record" (it reused the shared archive modal). It now shows
the correct title for the action — "Reopen activity", "Cancel activity",
"Restore activity" — so the dialog no longer contradicts itself. The
archive dialog everywhere else is unchanged.

# TalentTrack v4.73.4 — Live match execution: sub controls visible by default again (#2261)

Fixes a regression where, during a live match, the substitution controls
(the bench "→ on" buttons and the "who comes off" panel) plus the score /
goal steppers were hidden behind the "Edit" toggle — so a coach on the
sideline saw only the bench list and couldn't sub. The read-only-by-default
edit gate now applies only to post-match editing: a live in-progress match
(first half / half time / second half) opens with the mutating controls
already revealed, while the post-match review window keeps the accidental-edit
guard (tap Edit to enable) and finalized matches stay fully read-only.

# TalentTrack v4.73.4 — Reliable plugin updates: auto-install + missing-token notice (#2262)

TalentTrack now installs its own updates automatically once a new release
is detected — no click needed. It also shows a clear admin notice when the
GitHub token is missing from wp-config.php: without a token the update
check runs unauthenticated and GitHub rate-limits it (HTTP 403) after a few
tries, which is why updates sometimes stopped being detected. The notice
explains the one-line fix (`define( 'TT_GITHUB_PAT', 'ghp_…' );`).

# TalentTrack v4.73.3 — Reports: exclude cancelled activities from minutes and attendance (#2259)

Cancelled matches and trainings no longer contribute to the minutes or
attendance reports. An activity counts as cancelled when either its
`plan_state` is `cancelled` or its `activity_status_key` is `cancelled`, and
both markers are now honoured across the team and player minutes reports, the
standard-report minutes queries, and the attendance-ranking and team
attendance reports. Previously the minutes reports counted cancelled
activities entirely, and the attendance reports only caught the `plan_state`
marker — a completed-then-cancelled activity still skewed the numbers.
Non-cancelled activities, including manual "paper match" minutes, are
unaffected. Query-only change.

# TalentTrack v4.73.2 — Reports: exclude archived + trashed activities from minutes & attendance (#2257)

Minutes and attendance reports no longer count activities that have been
archived or moved to the recycle bin. Every report surface — team minutes,
player minutes, the attendance team report, the attendance leaderboard, and
the at-risk list — now filters out both `archived_at` and `trashed_at`
activities, so an archived or binned match can no longer inflate minutes,
starts, attendance %, or activity counts. Numbers for clean (live) data are
unchanged. Query-only change.

# TalentTrack v4.73.1 — Wizard: cleaner branched progress rail + Cancel always exits (#2254)

Wizards that branch (like the evaluation wizard's "Evaluate an activity"
vs "Evaluate 1 player" choice) now show a clean progress rail: only the
steps on the path you actually picked appear, instead of listing both
branches with half of them greyed out as "Not applicable". The step
counter reflects the active path too.

Cancel no longer loops. Previously, after moving through a step or two,
the Cancel link could send you back into the same wizard (its own URL had
become the browser referer) — an inescapable loop. Cancel now always
returns you to where you opened the wizard from (the list you came from,
otherwise the dashboard), never back into the wizard. Framework-level, so
every wizard benefits.

# TalentTrack v4.73.0 — Team minutes report: planned (unrecorded) matches no longer counted as starts (#2252)

The team minutes report (Reports → Minutes played per player) could show more
starts (basisplaatsen) than matches — e.g. "3 basisplaatsen, 1 wedstrijd" —
which is impossible, and it inflated the "% available" figure to match. The
cause: starts, available minutes and substitutions were accumulated from every
planned prep line-up, including matches that were planned but never played or
recorded, while matches and total minutes correctly counted only recorded
matches. Now a match contributes to starts, available minutes and subs only when
it actually produced recorded minutes, exactly like matches and totals already
did. A planned-but-unrecorded match contributes 0 across the board, so starts can
never exceed matches. Recorded minutes totals are unchanged.

# TalentTrack v4.73.0 — Tournament minutes: recordable and counted in the minutes reports (#2253)

Tournaments are now treated as a minutes-bearing activity type just like matches
and games, everywhere. A single-game tournament can be planned and run through
the live match surface (match prep + execution) exactly like a match; a
multi-game-day tournament records minutes with the by-hand per-player minutes
entry on the attendance screen. Both write the recorded minutes to the attendance
row. The team and player minutes reports now use one consistent activity-type
set (match, game, tournament), so a player who played tournament minutes shows
those minutes instead of a 0. No fabrication: a tournament with no recorded
minutes still shows 0, and for a multi-game day the line-up-derived starts are
approximate — the recorded minutes are the meaningful figure.

# TalentTrack v4.72.0 — Complete-activity buttons launch the type-aware evaluation flow (#2245)

Completing an activity is now an explicit button, not a status dropdown.
A planned activity shows **Complete activity** on both its list card and
its detail view; the button is type-aware — training and paper matches
open the evaluation wizard (matches also collect minutes), while a
live-tracked match routes to its Resume/Finalize flow. The activity only
flips to completed when the flow finishes, so abandoning leaves it
planned. The detail view gains **Cancel activity** / **Reopen** as direct
confirmed status changes. The edit form no longer changes status or holds
the inline attendance table — it edits details only.

# TalentTrack v4.72.0 — New-evaluation wizard opens with an explicit activity/player choice (#2246)

The New-evaluation wizard now starts with a clear two-way choice —
**Evaluate an activity** or **Evaluate 1 player** — instead of guessing
the path from a hidden smart-default. Choosing an activity leads to the
activity picker, attendance and rating; choosing a player leads to the
player picker and deep rating. Previous returns to the two buttons, so
switching paths is one tap. An empty activity list now shows guidance
rather than silently jumping to the player path.

# TalentTrack v4.72.0 — One evaluation wizard behind every door (#2249)

The dashboard "Mark attendance" hero, the activity completion buttons and
the New-evaluation wizard now all reach the same unified flow. The old
`mark-attendance` wizard is now a thin alias that seeds the activity
branch, so existing links and bookmarks keep working. The activity path
is attendance → "rate now?" → quick rating; behaviour rating moved to the
"Evaluate 1 player" deep path so it isn't lost. No data-model change —
the same attendance and evaluation rows are written as before.

# TalentTrack v4.71.0 — Planned attendance is now editable on the activity edit form (#2248)

The planned (expected) roster is no longer frozen at activity creation. Edit
a not-yet-completed activity and you get a **Planned attendance** section: one
row per planned player with a status you can set — **Expected**, **Not coming**
or **Maybe** — plus an optional note (e.g. "texted, injured"). Activities
created with "Set attendance later" seed the section from the current team
roster so you can start a plan from scratch. The detail page's Expected
attendance panel now summarises who is away ("2 not coming · 1 maybe") and
links straight to **Edit plan**.

Marking a player "Not coming" early carries into the later attendance defaults
via the match-prep availability step. Planned rows are stored as
`record_type='expected'` and are written independently of recorded
(`actual`) attendance, so the attendance reports are unaffected. Reachable via
`PUT /activities/{id}` (a `planned_attendance` sub-resource) and
`GET /activities/{id}/planned-attendance`; gated on `tt_edit_activities`. No
migration — "Maybe" reuses the existing `excused` status.

# TalentTrack v4.70.0 — Frontend authoring for the club vision + framework primer (#2226)

The methodology authoring surface gains two more tabs: **Vision** and
**Framework primer** (Raamwerk). Both are single-record editors — each club
has exactly one vision and one framework primer — so the tab opens straight
onto its edit form (no list, no "+ New", no delete). The Vision tab edits the
formation, style of play, way of playing, important traits and notes; the
Framework primer tab edits the title, tagline and every intro section
(inleiding, per-theme toelichtingen for voetbalmodel, voetbalhandelingen, the
four phases, learning goals and influence factors, plus reflection and
future). Every field carries side-by-side Dutch + English inputs, and the
first save creates the record while later saves update it. The shipped sample
vision and shipped primer stay read-only. What you save is reflected on the
read view's Visie and Raamwerk tabs.

Both are also exposed over REST at
`/wp-json/talenttrack/v1/methodology/vision` and
`/wp-json/talenttrack/v1/methodology/framework-primer` (GET + PUT, read and
update only — no create/delete for the singletons), club-scoped and gated on
`tt_edit_methodology`, so a future SaaS front end gets identical answers.

# TalentTrack v4.70.0 — Methodology authoring: Formations tab with nested positions (#2227)

The frontend methodology-authoring surface gains a **Formaties** tab.
Editors can now create, edit and delete formations (slug, Dutch/English
name and description, optional diagram-data JSON) and manage each
formation's position cards (jersey number, Dutch/English short and long
names, and newline-separated attacking and defending task lists) — no
wp-admin needed. Dutch and English round-trip; shipped reference
formations and positions stay read-only.

A matching REST surface ships alongside at
`/wp-json/talenttrack/v1/methodology/formations` (and the nested
`/{id}/positions`), gated on `tt_edit_methodology` and club-scoped, so a
future non-WordPress front end gets the same CRUD.

# TalentTrack v4.70.0 — Frontend authoring for set pieces (#2228)

Academy editors can now author **set pieces** from the frontend, no wp-admin
required. The methodology "Manage" surface gains a **Spelhervattingen** tab:
list, create, edit and delete club-authored set pieces with a slug, a
kind (corner, free kick, penalty, throw-in) and side, side-by-side Dutch +
English inputs for the title, a Dutch and English coaching-point list (one
bullet per line) and an optional diagram-overlay JSON blob. Shipped reference
set pieces stay read-only, and saved set pieces show up in the read view's
Set pieces tab.

The same data is exposed over REST at
`/wp-json/talenttrack/v1/methodology/set-pieces` (GET/POST/PUT/DELETE),
club-scoped and gated on `tt_edit_methodology`, so a future SaaS front end
gets identical answers. Built on the #2225 tab-registry + REST-base scaffold.

# TalentTrack v4.70.0 — Frontend authoring for phases, learning goals and influence factors (#2229)

Academy editors can now author the framework primer's three children from the
frontend, no wp-admin required. The methodology "Manage" surface gains three
tabs, each scoped to the club's framework primer:

- **Fasen** — the four attacking and four defending phases, each with a side,
  a phase number (1–4) and side-by-side Dutch + English title and goal.
- **Leerdoelen** — coachable learning goals per side, optionally tied to a
  teamtaak, with a Dutch + English title and a per-language bullet checklist.
- **Factoren van invloed** — the factors shaping development, with a Dutch +
  English title and description plus an optional array of sub-factor cards.

All three list, create, edit and delete club-authored rows; shipped reference
content stays read-only, and a tab points the editor to the Raamwerk tab first
when no primer exists yet.

The same data is exposed over REST at
`/wp-json/talenttrack/v1/methodology/phases`,
`/methodology/learning-goals` and `/methodology/influence-factors`
(GET/POST/PUT/DELETE), club-scoped and gated on `tt_edit_methodology`, so a
future SaaS front end gets identical answers. Built on the #2225 tab-registry +
REST-base scaffold.

# TalentTrack v4.69.0 — Configurable player-profile cards (#2207)

The player-profile **Profile** tab cards can now be shown or hidden
academy-wide from **Configuration → Profile cards**. Uncheck a card —
Academy, Parents · Guardians, or Discovery — to hide it for the whole
academy; the Identity card always stays. Useful for hiding cards you do not
use, such as Discovery when you do not run scouting. The choice is stored
per club and hides a card for display only — no data is deleted, and the
existing staff-only rule on Parents · Guardians and Discovery still applies
on top.

# TalentTrack v4.69.0 — Goal detail: widen the goal pane, reduce wasted horizontal space (#2217)

On the goal detail page the left goal pane no longer sits in a narrow
column beside a large empty gutter. The `max-width: 640px` clamp on the
goal card is lifted and the desktop split is rebalanced to `1.3fr 0.7fr`,
so the goal fills its column while the conversation pane stays readable.
CSS only; mobile stays a single column.

# TalentTrack v4.69.0 — Goal detail now shows progress %, connected principle and football action (#2218)

The goal detail page (coach and player views) now surfaces three fields
that were captured on the goal but never displayed: the progress
percentage as a bar, the connected methodology principle, and the
connected football action. A goal with no progress set shows a dash
rather than a fabricated 0%; unset links are hidden. Principle and action
names are resolved in the repository layer so the coach and player
surfaces show identical values, matching what the edit form saved.

# TalentTrack v4.69.0 — My sessions: Revoke now works, and the current session is detected (#2219)

On the "My sessions" screen, revoking another device no longer fails with
"Could not identify the session to revoke." The list now enumerates
sessions keyed by their verifier hash (read straight from the
`session_tokens` usermeta) instead of via `WP_Session_Tokens::get_all()`,
which strips those keys and left the revoke form carrying a numeric index.
The active session is once again correctly marked "This session" and hides
its Revoke button.

# TalentTrack v4.69.0 — Activity detail: grouped panel + compact stat strip (#2220)

The activity detail page now reads as one cohesive record. The hero, a new
compact stat strip and the section cards sit inside a single softly-tinted
**grouping panel**, giving three tonal layers (page → tinted panel → white
cards) so the detail looks deliberate even when only a couple of sections
apply. The de-elevated hero is followed by a stat strip of the key numbers:
a match shows Present · Substitutes · Match length, a training shows
Present · Duration, each cell dropping out gracefully when its number is
unavailable. Every section card (Attendance, Line-up, Principles, Notes,
Tournament) keeps its titled header and only renders when it has content.
The line-up card's internal layout is unchanged here (its restyle is
tracked separately). Numbers are derived in the domain layer; the view only
composes them.

# TalentTrack v4.69.0 — Spond source indicator on the activity list and detail (#2221)

Activities imported from Spond now show their provenance. On the activities
list, a Spond-sourced card carries a small blue **Spond** chip alongside its
type and status pills; manually-created and generated activities show none.
On the activity detail page, Spond-sourced activities show a
**Team last synced from Spond: <time>** line in the audit footer — the
team's most recent Spond sync (the timestamp is team-level, and the label
says so, keeping the freshness claim honest). No schema change: the source
flag and the team sync time already exist. Both `activity_source_key` and
the team's `team_spond_last_sync_at` are exposed on the activity REST
payload so a future front end can render the same chip and freshness line.

# TalentTrack v4.69.0 — Match execution: completed matches are read-only, editing is opt-in (#2222)

The match-execution screen now opens read-only and hides its mutating
controls (score steppers, +action / →on buttons, and the post-match
late-goal / late-substitution panels) behind an explicit **Edit** toggle in
the header. Editing is only offered while the execution still accepts
writes — during play, half-time, and the post-match review window. A
**finalized** match shows no Edit affordance and keeps its live controls
locked, matching the read-only state the REST layer already enforced. This
removes the confusing "the match is done but the buttons still work"
behaviour. Reuses the existing `tt_edit_activities` capability — no new
permission.

# TalentTrack v4.69.0 — Match execution: pitch labels players by first name + last initial (#2223)

The vertical pitch on the match-execution screen now labels each player by
first name plus last initial (e.g. "Daan P.") instead of the surname —
matching how a coach names a player from the sideline while staying
unambiguous when two players share a first name. Single-word names render
as-is with no stray dot. Display formatting only; the label still fits the
360px pitch slot.

# TalentTrack v4.69.0 — Match execution detail: linked activity + correctable recorded minutes (#2224)

The match-execution screen now links its parent activity through the
breadcrumb chain (Dashboard / Activities / {activity} / Match execution),
so the activity is both visible and one tap away — no hand-rolled back
button. On a **finalized** execution it also adds a **Correct recorded
minutes** action: a coach with `tt_edit_activities` can edit each player's
recorded minutes with numeric inputs and Save (or Cancel back to the
read-only detail). Minutes are only correctable post-finalize, where no
auto-recompute can clobber the manual value; the correction writes through
the existing row-scoped `PATCH /attendance/{id}` path (its minutes column
now accepts a clamped 0–200 value), so the figure flows straight into the
minutes reports without reopening the locked match. No new endpoint,
capability, or schema change.

# TalentTrack v4.69.0 — Frontend authoring for the methodology library — foundation + Principles (#2225)

Academy editors can now author methodology content from the frontend, no
wp-admin required. A new "Manage methodology" surface lives alongside the
read view (`?tt_view=methodology&mode=manage`), gated on the existing
`tt_edit_methodology` capability, with a "View published methodology" link
back to the library. It opens with **Principles**: list, create, edit and
delete club-authored principles with side-by-side Dutch + English inputs for
the title, explanation, team-level guidance and per-line guidance. Shipped
reference principles stay read-only. The same data is exposed over REST at
`/wp-json/talenttrack/v1/methodology/principles` (GET/POST/PUT/DELETE), so a
future SaaS front end gets identical answers.

Under the hood this ships the reusable scaffold the rest of the methodology
entities build on: an extensible tab registry (each entity registers its own
manage tab without touching a shared switch) and a shared REST base
controller. Formations, set-pieces, visions, framework primers and the other
entities follow in later releases.

# TalentTrack v4.69.0 — Methodology authoring: Football actions (voetbalhandelingen) (#2230)

Editors can now create, edit and delete football actions
(voetbalhandelingen) straight from the frontend Methodology → Manage
surface, alongside principles. Each action has a slug, a category (met
balcontact / zonder balcontact / ondersteunend) and side-by-side Dutch and
English name + description. The same CRUD is available over REST at
`/wp-json/talenttrack/v1/methodology/football-actions`. Deleting an action
that a goal still links to is blocked (with a clear message) so the
`linked_action_id` reference is never orphaned. Club-scoped; shipped rows
stay read-only.

# TalentTrack v4.69.0 — Fixed age-banded measurement targets never resolving because the player's age group was read from a non-existent `tt_players.age_group` column; it now resolves via the player's team (`tt_teams.age_group`).

Fixed age-banded measurement targets never resolving because the player's age group was read from a non-existent `tt_players.age_group` column; it now resolves via the player's team (`tt_teams.age_group`).

# TalentTrack v4.68.0 — Test-results Excel export: a "Trends over time" sheet with a line chart (#2194)

The per-test Excel export now includes a second **Trends** sheet: one row per
player, one column per recorded date (chronological), the value in each cell,
plus a line chart that plots every player as a series over the shared date
axis. Numeric and scale-score tests are charted; status tests list each
player's recorded level per date for reference without a chart. Built from the
same result reads as the existing sheet — no extra queries.

# TalentTrack v4.68.0 — Test settings: "Direction" now saves on scale-score tests (#2195)

Editing a test on Configuration → Manage tests dropped the "Direction"
(higher / lower is better) setting on scale-score tests: the save clamped it
to "neither" for every non-numeric value type, even though the Direction
dropdown is shown for scale tests. Direction now round-trips for both numeric
and scale-score tests; pass/fail and status tests correctly stay neutral.

# TalentTrack v4.68.0 — Line-up bench: clean position codes instead of raw JSON (#2196)

The match-day line-up card's Bench row now shows a reserve player's
position as the clean short code (`LW`, `CDM`) instead of the raw stored
JSON array (`["LW"]`). Multi-position players join cleanly (`LW, CDM`) and
an empty position renders nothing. Starting XI was already clean; only the
bench fallback needed decoding.

# TalentTrack v4.68.0 — Match-prep: both half-pitches share one light background (#2197)

In match preparation the 2nd (right) half-pitch carried an orange tint
that read as a dark background. Both half-pitches now render with the same
light pitch surface, matching the printed sheet. CSS only.

# TalentTrack v4.68.0 — Match-prep PDF: empty fields print blank, not placeholders (#2198)

The match-prep PDF no longer prints empty-field placeholder text. In the
image-capture export, empty goal / attention inputs and unassigned
set-piece roles ("Goal 1…", the "…" hints, "— Pick player —") now render
blank; on-screen editing keeps its placeholders. The standalone print /
DomPDF sheet likewise drops the "—" dash for an empty attention note or an
unassigned role. CSS + printable-renderer only.

# TalentTrack v4.68.0 — Activities: Archive is now delete-class, not edit-class (#2199)

Archiving an activity is a soft delete, so it now requires the activities
create/delete capability rather than the edit capability. An assistant coach
who can only edit activities no longer sees the Archive (or Restore) button and
no longer hits a 403 on click; a head coach who can create/delete still does.
Both the detail-header buttons and the archive/restore REST routes gate on the
`activities:create_delete` matrix entity via the new
`tt_delete_activities → activities:create_delete` legacy-cap mapping — no new
matrix entity or seed migration.

# TalentTrack v4.68.0 — Activities: past-but-open activities surfaced by default (#2200)

Past activities that were never closed off (still Planned, not Completed or
Cancelled) now render in their own explicit "Past — still open" section at the
very top of the activity list — above the collapsed Past toggle — in a tinted,
orange-accented block. A coach sees overdue follow-ups without extra clicks;
completed and cancelled past activities stay in their normal collapsed Past
bucket.

# TalentTrack v4.68.0 — Activity list: the "show cancelled" toggle can be switched off again (#2201)

The "Geannuleerde tonen" toggle on the activities filter bar could be turned
on but not back off — once enabled the flag stayed set. The shared toggle
control now supports an explicit off-value: turning the switch off submits
`show_cancelled=0` (via a hidden companion field) instead of merely omitting
the param, so the cancelled filter clears and the switch reflects the off
state on reload.

# TalentTrack v4.68.0 — Goals list: status filter defaults to Active, no "All" (#2202)

The Goals list status filter no longer wraps to a second line. It now offers
three semantic buckets — Active, Achieved and Missed — rendered as pills with
coloured status dots, drops the "All" option, and defaults to Active so the
list opens on the goals a coach is actively working on. The REST endpoint maps
these buckets onto the canonical completed / cancelled status codes and still
honours raw status codes on existing deep links.

# TalentTrack v4.68.0 — FilterBar: status group always last and right-aligned (#2203)

On the shared filter bar the status pills now always render as the last
control on the inline (desktop) row and hug the right edge, regardless of
the order the calling view passes its filter groups. Other groups keep
their order and the mobile bottom sheet is unchanged. Component-wide change
— no caller edits needed.

# TalentTrack v4.68.0 — Per test: choose whether its results show on the player profile (#2204)

Each measurement test now has a **Show on the player profile** checkbox
(on by default) in the Manage-tests editor. Clear it to keep a test out of
the player-profile measurements view while it still records results and
appears in the results browser, reports and exports — handy for internal or
experimental tests. A new migration adds the `show_on_profile` column with a
default of 1, so every existing test stays visible on upgrade.

# TalentTrack v4.68.0 — Attendance leaderboard now defaults to all players (#2205)

The attendance leaderboard's *How many* field no longer defaults to 10.
Leaving it blank now ranks **every** player in the chosen window in both
the *Needs attention* and *Most reliable* tables. Typing a number still
narrows each table to that many rows, and the field is no longer capped at
50. The REST endpoint (`GET /reports/attendance-leaderboard`) follows the
same rule: an unset `n` returns all players.

# TalentTrack v4.67.1 — Minutes reports: one source of truth — actual recorded minutes only (#2193)

The minutes reports now agree with each other. Every minutes report reads
only the minutes that were actually recorded for a match — persisted on the
player's attendance row when the match was finalised or when a coach entered
the minutes by hand. Reports no longer estimate, calculate, or reconstruct
minutes from a planned line-up: a match that was played but never finalised
now shows a truthful 0 / — everywhere, instead of one report inventing an
estimate (e.g. 70′) while another correctly shows nothing.

Concretely, the Analytics "Gespeelde minuten per team" report dropped the
report-time recompute-from-line-up fallback it still carried, bringing it in
line with the Player · Minutes and Team · Minutes-distribution reports and
the minutes audit REST endpoint, which already counted recorded minutes only.
Matches that do have recorded minutes are unchanged.

# TalentTrack v4.67.0 — Archived activity detail page offers Restore, not Archive (#2183)

Opening an archived activity's detail page now shows a **Restore** action in
the header instead of a second **Archive** button. Restoring returns the
activity to the active list in one click. An archived activity is read-only
until restored — its Edit and match actions stay hidden until it is active
again. The read-only detail now resolves archived rows too, so an archived
activity no longer reads as "not found".

# TalentTrack v4.67.0 — FilterBar: explicit Apply button for the date range on the inline bar (#2184)

The shared filter bar now shows an explicit **Apply** button next to a
from/to date range on the inline (desktop) layout, so changing a date
range has a clear, keyboard-reachable way to commit — the inline bar
previously had no visible commit action for a date change. The mobile
bottom sheet keeps its single footer Apply (no duplicate). The button is a
plain submit: on a bare filter bar it reloads with the new range, and on a
list that filters live it hands off to the existing hydrator instead of
double-submitting. Every view using the filter bar with a date range
(audit log, comparison, and others) benefits.

# TalentTrack v4.67.0 — Attendance-per-player report: drill down from the activity count to the source sessions (#2185)

The **Activities** count on the player attendance report is now a link. Open
it to see the actual sessions behind the number: the activities list opens
filtered to that player, the report's team, and the report's date window,
showing only activities the player has a recorded attendance row for — and
each activity's detail shows the recorded attendance status. This lets a
coach trace any attendance figure back to real, dated sessions, mirroring
the minutes-played drill-down. The report already listed every player in the
window (worst-attendance-first) with no cap; that behaviour is unchanged and
now documented. The activities list gained optional `player_id` /
`date_from` / `date_to` filters to support the drill-down.

# TalentTrack v4.67.0 — Help buttons now hide when the Documentation module is disabled (#2186)

The contextual **Help** buttons (on goals, wizards, and anywhere else that
uses the shared help-drawer trigger) now render only when the **Documentation**
module is enabled under Configuration → Modules. Disabling the module removes
the buttons everywhere, matching the promise that a disabled module leaves no
dangling entry points. The gate reads the same module-state registry the
Modules admin page writes — no hardcoded check — and never fatals if the
disabled module class isn't loaded.

# TalentTrack v4.67.0 — Modules admin page is now matrix-driven (#2187)

The Modules admin page (wp-admin `tt-modules` and the frontend
`?tt_view=modules`) previously gated access on a WordPress role-name compare
(`current_user_can('administrator')`), which the authorization matrix could
not govern. It now checks the `tt_manage_modules` capability, bridged to a
dedicated `module_management` matrix entity, so the matrix decides who can
enable or disable modules — the same as every other admin surface. A new
migration re-seeds the grant onto existing installs (Academy Admin retains
access; WordPress administrators bypass unconditionally, so no one loses the
page on upgrade).

# TalentTrack v4.66.1 — Reports module: on/off toggles for the attendance, minutes-per-team and rate-card reports (#2126)

The Reports module-settings page now exposes a toggle for all 15 reports, not
just 10. The three attendance reports (team, player, leaderboard), the
minutes-played-per-team report and the rate cards were on the Reports launcher
but had no feature toggle, so an academy could not switch them off. They now
join the per-report catalog like the others: switching one off hides its
launcher tile and rejects a direct `?tt_view=…` link. All five default to on,
so existing installs keep showing them until an admin turns them off. No schema
change — the toggle state already accommodates new catalog keys.

# TalentTrack v4.66.1 — Test results browser: fix empty list caused by a bad age-group column (#2165)

The Testresultaten browser and `GET /measurement-results` returned no rows
because the underlying query referenced `pl.age_group`, a column that does
not exist on `tt_players` — age group lives on `tt_teams`. The query now
reads age group from the team, so the browser lists every player with a
value for the chosen test and the Leeftijdscategorie filter narrows
correctly. Repository-only change; no schema or UI change.

# TalentTrack v4.66.1 — Metingen vastleggen: status picker no longer clipped by the roster (#2166)

On the record-measurements roster, the coloured status picker's option list
was cut off — on a short roster only the skip option was visible — because
the roster used `overflow: hidden` to clip its rounded corners, which also
clipped the absolutely-positioned dropdown. The roster now uses
`overflow: visible` and the rounded-corner look is preserved by rounding the
first and last rows, so the full level list opens above the following rows
with its shadow intact. CSS-only.

# TalentTrack v4.66.1 — Trials list: filters moved to the shared FilterBar (#2174)

The Trials list filter bar (Status, Track, Decision, Include archived) now
uses the shared FilterBar component: an inline single-line row on desktop
and a "Filters" button + bottom sheet on phones and tablets. Filtering
behaviour is unchanged — same parameters, same results. The bespoke
filter-form styling was removed in favour of the shared component's sheet.

# TalentTrack v4.66.1 — Audit log: filters moved to the shared FilterBar (#2175)

The audit-log filter bar (Action, Entity, User #, date From/To) now uses
the shared FilterBar component: an inline single-line row on desktop and a
"Filters" button + bottom sheet on phones and tablets, with Clear as the
sheet's reset action. Filtering behaviour is unchanged — same parameters,
same results. The old hand-rolled, inline-styled form was removed.

# TalentTrack v4.66.1 — Player comparison: filters moved to the shared FilterBar (#2176)

The player-comparison filter block (Date from/to and Evaluation Type) now
uses the shared FilterBar component: an inline single-line row on desktop
and a "Filters" button + bottom sheet on phones and tablets. The date range
and evaluation-type filter drive the comparison identically — same
parameters, same results — and the Compare action still submits the player
picks together with the filters. The bespoke filter styling was removed.

# TalentTrack v4.66.0 — Strava console: in-context "Before you start" setup checklist (#2127)

The Strava operator console now opens with a short "Before you start" checklist
of the one-time steps that happen on Strava's side — create the API
application, set the Authorization Callback Domain to this site, then paste the
credentials and verify. It expands automatically until the app is configured,
then collapses. Dutch included.

# TalentTrack v4.66.0 — Strava console: Dutch translations + self-healing webhook subscription (#2127)

Translates the Strava operator console into Dutch (the new strings shipped
English-only) and makes the webhook subscription robust against Strava's
one-subscription-per-application rule. "Create / re-verify" now adopts an
existing subscription instead of failing when one already exists at Strava,
and the subscription status reconciles against Strava's real state on load —
so an id this install lost is recovered and a subscription deleted from
Strava's side clears here automatically. Backed by a new read of Strava's
`GET /push_subscriptions` endpoint.

# TalentTrack v4.66.0 — Attendance reports no longer count not-yet-held activities (#2135)

The team and player attendance reports — and the leaderboard and at-risk
panel that share their query — now exclude activities dated in the future.
An activity created via the normal "+ New activity" form defaults to
`plan_state = 'completed'`, so a future activity with pre-filled attendance
used to slip past the existing guards and inflate the statistics. The
reports now also require `session_date <= CURDATE()` (an activity dated
today still counts), matching the established predicate in
`ActivitiesRepository`. Query-only; past windows show identical numbers.

# TalentTrack v4.66.0 — Attendance reports: period quick-pills + activity-type filter (#2136)

Both the team and player attendance reports now carry the same filtering
vocabulary as the activities list: retrospective period quick-pills (Last
week, This month, This season) that set the From/To window for you — with
the manual date range still overriding — and an activity-type filter
(training / game / tournament). The type filter narrows every figure
consistently: the KPI tiles, the table, the leaderboard and the at-risk
panel. Filters render through the shared FilterBar (a Filters bottom sheet
on mobile, inline on desktop) and the filter flows into the shared
`AttendanceRankingQuery` plus a new `activity_type_key` parameter on the
attendance REST endpoints, so a SaaS consumer gets the same answers.

# TalentTrack v4.66.0 — Team attendance report: expandable rows to drill into players (#2137)

Each team row on the team attendance report is now tap-to-expand: tapping
the team name opens an inline sub-table of that team's players (player ·
present %, with at-risk players marked), loaded on demand for the active
window and filters from the shared `AttendanceRankingQuery`. One team is
open at a time. The disclosure is a semantic `<button aria-expanded>` and
is keyboard-operable; without JavaScript a "View players" link beside each
team opens the player report pre-filtered to that team instead, so the
drill-down is always reachable. The per-player slice is exposed at a new
`GET /reports/attendance` REST endpoint for non-WordPress consumers.

# TalentTrack v4.66.0 — Measurements: Record-measurements roster and profile cards readable in dark mode (#2142)

The Record measurements page and the player-profile Measurements cards rendered
dark text on a dark background when the operating system or browser was in dark
mode — the stylesheet darkened the card backgrounds without lightening the text,
while no other dashboard surface offers a dark variant. Removed those two
half-implemented dark-mode overrides so the measurement surfaces stay light and
legible in both modes.

# TalentTrack v4.66.0 — Measurements: coloured status picker on the Record-measurements roster (#2144)

Recording a status-type test now offers a custom, accessible status picker per
player instead of a plain native dropdown. Both the closed control and every
option in the open list show the level's colour square next to its label, and
the control sizes to the longest label so level names are no longer clipped to
the numeric column width. The picker is fully keyboard- and touch-operable
(Enter/Space or the arrow keys to open, ↑/↓ to move, type-ahead, Escape to
close) and progressively enhances the native `<select>` — with JavaScript off
the working native dropdown remains. The chosen level still posts and saves
exactly as before. Numeric, scale and pass/fail inputs are unchanged.

# TalentTrack v4.66.0 — Test results browser: navigate every measurement result in one place (#2145)

A new **Test results** tile in the Analysis group opens a dedicated browser
(`?tt_view=test-results`) for exploring measurement results across players.
Pick a test, optionally narrow by team, age group or date range, and read
each player's latest value: status tests show the level's colour chip and
label; numeric and scale tests show the value with a ▲/▼ trend against the
previous result and a green/amber flag against the age-group target. The
grid is sortable and every player name links through to their profile, and
the per-test Excel export is one click away. Team-scoped staff only ever see
results for their own teams. The same rows are exposed at
`GET /wp-json/talenttrack/v1/measurement-results` for a future SaaS front end.

# TalentTrack v4.66.0 — My activities now shows only your own sessions (#2150)

The dedicated **My activities** page could fall through to the broader
team/club result set when the player's linked-player resolution was missing
or mismatched, leaking activities that weren't theirs. The activities REST
list now fails closed for player and parent callers: it re-derives the
scoped player id from the session (a player's own linked player, or a
verified child for a parent) instead of trusting the request, and returns an
empty list when nothing resolves — never the unscoped set. Staff lists are
unchanged.

# TalentTrack v4.66.0 — "My card" tile renamed to "My profile" (#2151)

The player overview tile, header and breadcrumb now read **My profile**
instead of "My card", and the matching parent tile reads **My child's
profile** ("Het profiel van mijn kind"). Display string only — the internal
slugs (`my_card`, `overview`) and all routes and permissions are unchanged.

# TalentTrack v4.66.0 — My development: open a goal, activity or milestone in one tap (#2152)

The goal, upcoming-activity and journey-milestone rows on the player and
parent "My development" home are now tappable. Each row title links to that
record's player-facing detail (My goals, My activities, My journey),
carrying a back hint so the detail view shows a "← Back to My development"
pill. Evaluations stay list-linked — there is no per-evaluation player
detail to open. Makes the development narrative one tap deep instead of
forcing a trip through the full list.

# TalentTrack v4.66.0 — Players can connect their own Strava (#2153)

A logged-in player can now connect their own Strava account from their
profile without hitting a "not authorized" error. The player persona was
missing the `strava_integration` matrix entity, so under matrix gating the
self-service connect flow denied the athlete even on their own record. The
authorization seed now grants the player a self-scoped Strava grant
(`rc[self]`, mirroring `my_profile`), and a re-seed migration backfills it on
existing installs. The self scope means a player can only ever manage their
own connection — never another player's. Coach and admin behaviour are
unchanged.

# TalentTrack v4.66.0 — Long position descriptions on profiles, cards and dashboards (#2155)

Player positions now read as their full description (Centre back, Striker)
instead of the short code (CB, ST) across the profile/cards/dashboards
group: the player detail hero, profile tab and archived card; the coach and
player dashboard cards; the teammate card; the overview hero meta; the
my-profile card; the rate-card hero widget; and the FIFA-style player card
(including podium cards). Lists, rosters, PDFs, CSV exports and the REST
player payload keep the short codes. The long forms are already translated,
so no new strings.

# TalentTrack v4.66.0 — My team podium links now open the teammate profile (#2156)

On the player **My team** page, tapping a podium player led to the
staff-only unified profile and returned "not authorized". Podium cards in
player-facing contexts now link to the minimal, team-scoped teammate profile
— the same authorised page the roster links already used. Staff surfaces
that render the same podium are unchanged and still open the full profile.

# TalentTrack v4.66.0 — Configurable email sender — name + address for plugin email (#2157)

Configuration → General gains an **Email sender** group: set the name and
address every TalentTrack email is sent from, instead of inheriting the
WordPress default "WordPress <wordpress@…>". The values are applied to all
plugin email — account invitations and notifications as well as Comms
messages — via the wp_mail_from / wp_mail_from_name filters. Blank or invalid
values fall back cleanly to the WordPress default, so the From header is never
broken. Stored per club in tt_config, so a future multi-tenant install keeps
each academy's sender separate.

# TalentTrack v4.66.0 — Minutes reports: harden aggregation and stop fabricating estimates (#2158)

The minutes-played reports now count only canonical recorded attendance —
`record_type = 'actual'`, non-guest — and sum each player's minutes per match
before joining, so a player with a duplicate attendance row for the same match
is counted once instead of being doubled by a JOIN fan-out. The "Player ·
Minutes played" and "Team · Minutes distribution" reports also now join
attendance on the correct `activity_id` column (the previous join used a column
renamed away years ago, which was one cause of reports showing zero minutes).

A match with no persisted minutes, no execution and no lineup now contributes
nothing — the old "credit each starter half a match" estimate is gone, so a
total never mixes recorded minutes with invented ones. Correctly-recorded past
matches show identical numbers.

# TalentTrack v4.66.0 — Manual match-minutes entry on the attendance screen (#2159)

A coach who runs a "paper match" without the sideline match-execution flow can
now record minutes per player directly on the activity's attendance screen.
The minutes land in `tt_attendance.minutes_played` as actual, non-guest rows —
the single source the minutes reports read — so they flow straight into the
Player · Minutes and Team · Minutes reports. The minutes report now also surfaces
such matches even when they have no match-prep lineup.

The orphaned "Minutes Played" field on the evaluation form is removed and the
plugin no longer writes `tt_evaluations.minutes_played` (a column no report
read). Precedence: a later match-execution recompute remains authoritative and
overwrites manually-entered minutes for the same match.

# TalentTrack v4.66.0 — Minutes audit / trace-back: report drill-down and raw rows (#2160)

Every player's minutes total in the Team · Minutes reports (both the standard
report and the Analytics minutes report) now expands to the per-match rows that
sum to it — date, match, type, source (`actual` vs recomputed) and minutes —
reusing the same hardened query so the breakdown reconciles exactly with the
total. The trace is also exposed over REST at
`/teams/{id}/players/{pid}/minutes` for a non-WordPress front end.

The raw `tt_attendance` minutes rows — `minutes_played`, `record_type`,
`is_guest`, `activity_id` — are now documented and browsable in the Data
Browser for ad-hoc verification.

# TalentTrack v4.66.0 — Data Browser search now matches column names (#2161)

The Data Browser index search now also matches column names, so typing
"minutes", "club_id" or "uuid" surfaces every table that has a matching column —
not just tables whose name or description mention it. When a table surfaces
because of a column, the result row shows which column matched. Existing
table-name / description matching and the table-page row-value search are
unchanged. Column lists are already cached per table, so there is no extra
query cost.

# TalentTrack v4.66.0 — PDP planning: remove the misplaced "Show archived" button (#2162)

The PDP/POP planning matrix no longer shows a "Show archived" button. It
implied it toggled archived rows in the matrix, but the planning view is a
live aggregate that never includes archived conversations — the button just
navigated away to the PDP manage list. Restoring archived PDPs still lives in
the PDP manage list's archived filter, which is the right place. Removing the
button also keeps the planning view within the two-affordance navigation
contract.

# TalentTrack v4.65.0 — Tests & measurements: REST CRUD for the test catalogue (#2120)

Test definitions are now fully CRUD-able over the `talenttrack/v1` REST API
at `/measurement-definitions`: list (optionally including deactivated tests),
read a single test with its per-age-group target bands, create, edit, upsert
a green/amber band for one age group, and soft-archive. A hard-delete path
is gated on the recycle-bin capability so no purge is weaker than the bin's
own. Every route is matrix-gated on the `measurement_definitions` entity
(read / change / create_delete) and delegates straight to the existing
definitions and targets repositories — no business logic in the controller —
so a future SaaS front end gets the same answers as the plugin's Configure
view.

# TalentTrack v4.65.0 — Tests & measurements: a “Manage tests” surface for the catalogue (#2121)

Academy admins and heads of development get a dedicated “Manage tests”
configuration surface for the test catalogue, reached from a new tile under
Configuration. It lists every test definition — name, category, unit,
direction and cadence — with its active state, and offers per-row Edit,
Activate / Deactivate, and Archive actions. Creating a test still runs
through the existing “+ New test” wizard; editing is a flat form (Save +
Cancel) covering the definition fields plus the per-age-group green/amber
target bands that drive coverage flagging. The view is matrix-gated on
`measurement_definitions` change and composes the same repositories the REST
catalogue contract uses, so a future SaaS front end gets identical answers.

# TalentTrack v4.65.0 — Player profile: Measurements signal in the At-a-glance panel (#2123)

A player's measurement standing now reads as part of their journey
narrative, not just a separate tab. The profile's **At a glance** panel
gains a **Measurements** signal beside Avg rating, Attendance and Goals:
the number of tests the player currently has a value for, with a hint of
how many sit below their age-group target band (or "on target" when none
do). It links straight to the Measurements tab for the full per-test
timeline. The signal is gated on `measurements:read`, so it never leaks a
player's test standing to a role that can't open the underlying results.

# TalentTrack v4.65.0 — Tests & measurements: cross-linked surfaces and consistent framing (#2124)

The three test surfaces now read as one "Tests & measurements" module and
link to each other so staff move between configuring, recording, and
reviewing without going back to the dashboard. Record measurements links to
Manage tests; Manage tests links to Record measurements and Testing coverage;
Testing coverage links to Manage tests (shown only to staff who can edit the
catalogue). Every cross-link carries a contextual back-pill on arrival. The
three dashboard tiles keep their specific names but share a common
"Tests & measurements:" lead-in so they're recognisable as one product.

# TalentTrack v4.65.0 — Strava operator console in Configuration → Integrations (#2127)

Adds a Strava integration tile to Configuration → Integrations, next to Spond,
opening an operator console (`?tt_view=strava-admin`) where an academy admin
registers the Strava app Client ID + secret, creates or deletes the club-wide
webhook subscription, and sees every player who has connected — their status,
imported-activity count, last activity and last sync. Previously these were
only reachable over the REST API with no UI.

The operator surface is now matrix-gated instead of `manage_options`: viewing
follows the new `tt_view_strava` capability and credential / webhook changes
follow `tt_edit_strava_credentials`, both bridged to the `strava_integration`
matrix entity and tunable per persona. A new `GET /strava/connections` endpoint
backs the roster and never returns tokens or the client secret. A top-up
migration seeds the entity on already-installed sites so admins and heads of
development keep access on upgrade.

# TalentTrack v4.65.0 — Tests & measurements: a status value type with coloured levels (#2138)

Tests can now use a **status** value type — a simple, manually maintained,
dated player status built on the measurement framework. The operator defines
an ordered set of coloured levels (e.g. *At risk* red, *Watch* amber, *On
track* green) from a curated palette on the test's edit screen. Recording a
status shows a level dropdown per player in the bulk team-entry grid instead
of a number field, and the player's latest level shows as a coloured chip in
the Measurements tab of their profile. Each change is a dated entry, so the
player's status history is queryable over time. The levels are exposed over
REST at `/measurement-definitions/{id}/levels` (matrix-gated read / change),
and the colour is stored as a token key — never a raw hex — so the swatch
lives in the design system. A seeded **Player status** category groups these
tests.

# TalentTrack v4.65.0 — Export a test's results to a formatted Excel workbook (#2139)

The **Manage tests** view now offers an **Export to Excel** action on every
test row and in the test's edit view. It downloads a formatted `.xlsx` for
that one test: a header block (test name, unit or *status*, date range and
club) over a frozen, bold column-header row, then one row per recorded result
with the player, team, recorded date, value, age group and recorded-by —
grouped per player so a player's series reads together.

Status-type results show the recorded level label in the value column, filled
with the level's colour to mirror the player-profile chip. The export reuses
the existing export pipeline (no new REST route) and is gated on the
`measurements` read permission.

# TalentTrack v4.64.0 — Match prep PDF: white panels + consistent player boxes (#2112)

The **Export as PDF (A4)** capture rendered the doen-per-speler and rollen
panels with a grey background and tinted the on-pitch player boxes
differently over the blue (1e) and orange (2e) halves — html2canvas can't
resolve the nested `--tt-mp-paper` custom property, so the panel fill
dropped out and the translucent pills blended with the pitch. The capture
now forces opaque white panels and player boxes (and drops the card
shadows that printed as grey halos); the on-screen view and the pitch
colours are unchanged.

# TalentTrack v4.64.0 — Measurements: restore admin & coach access on upgraded installs (#2114)

On sites upgraded from before the Measurements module shipped, academy
admins, heads of development and coaches were silently denied access to
**Record measurements** and **Testing coverage** — the dashboard tile
appeared but the screen reported "no permission". The authorization rows
for the module were added to the seed but never back-filled into existing
installs (the matrix reseed is manual and destructive). A new idempotent
migration adds the missing `measurements` / `measurement_sessions` /
`measurement_definitions` matrix rows, leaving any operator edits intact.
The two staff tiles now gate on the same matrix entity the views enforce,
so a tile can no longer appear for someone the screen will refuse.

# TalentTrack v4.64.0 — Measurements: dashboard tiles show their icon again (#2115)

The **My measurements**, **Record measurements** and **Testing coverage**
dashboard tiles rendered an empty icon chip — they referenced an `activity`
glyph that does not exist in the icon set. They now use real bundled glyphs
(`trend-up` for My measurements, `track` for the two staff tiles).

# TalentTrack v4.64.0 — Team roster: hide the player STATUS column when Player Status is off (#2118)

The team detail roster gated its STATUS column (the traffic-light dot per
player) on whether the `PlayerStatusRenderer` class existed — but that class
is always autoloaded, so the column showed even when the Player Status module
was switched off. It now checks `ModuleRegistry::isEnabled()` for the module,
matching how the VCT panel on the same page is gated. With the module off the
roster shows only Jersey # and Player, no per-player status is calculated, and
the status styles are no longer enqueued.

# TalentTrack v4.64.0 — Team detail: hide squad rating from users without evaluation-view rights (#2119)

The team detail page's **At a glance** strip showed the **Squad rating
("Selectiebeoordeling")** tile to everyone who could open a team — including
an assistant trainer with no evaluation-viewing rights. The score is an
average of the roster's evaluation ratings, so it leaked gated data. The
tile is now shown only to users who hold `tt_view_evaluations`; without it
the tile is omitted entirely (not blanked to "—"), so the strip doesn't
hint that a hidden score exists. The Upcoming and Attendance tiles are
unchanged for all roles.

# TalentTrack v4.64.0 — Analytics: switch Evaluation coverage and Cohort decision board off individually (#2128)

The two Head-of-Development analytics surfaces — **Evaluation coverage**
and **Cohort decision board** — can now be hidden independently from
**Modules → Analytics**, without disabling the whole Analytics module or
touching the shared `tt_view_analytics` permission. Each is a per-tile
feature toggle: turning one off hides its tile and blocks its
`?tt_view=` route, while the central Analytics surface, the standard
reports and the analytics engine keep working.

Note for existing installs: both toggles ship **off by default**, so the
two tiles disappear on upgrade until an admin re-enables them under
Modules → Analytics. This is a deliberate change — academies that want
the surfaces switch them back on there.

# TalentTrack v4.63.5 — Week-PDF: icon-led meta line + revised default toggles (#2108)

Each info block on a weekly-planner-PDF activity card now leads with a
small icon — a clock before the time(s) (one clock, even when a match
shows both presence and kickoff), a pin before the location, and a note
icon before the notes line. The compose dialog's defaults changed to
match how coaches actually print: Duration and Principles are now off by
default, and Notes is on. Two new line icons (`clock`, `map-pin`) were
added to the shared icon set.

# TalentTrack v4.63.4 — Match prep: 3-4-3 diamond now draws as a diamond (#2099)

The **Aanvallend 3-4-3 (ruit)** formation drew a flat midfield on the
match-prep pitch (and the live match surface, the printable sheet and the
attendance projection) because positions were keyed by the formation's
shape string — so every template sharing the `3-4-3` shape collapsed onto
one flat layout. A formation template's own geometry (its `slots_json`)
is now authoritative when it carries slot numbers, so the diamond
positions its midfield as DM / LCM / RCM / AM. Formations without custom
geometry are unchanged. A migration adds slot numbers to the seeded
diamond template.

# TalentTrack v4.63.4 — Players and parents land on the one unified profile (#2107)

Opening "My card" (and a parent opening their child's card) now lands on the
same unified, permission-aware player profile that staff use — defaulting to
the Player card tab — instead of a separate card page. A player sees their card,
profile, goals, activities and Strava; staff-only surfaces (evaluations, PDP,
trials, notes, the guardians and discovery cards, the maturation/PHV flag, and
the status-history link) stay hidden for a player or parent. The breadcrumb is
framed for the viewer ("My card" for a player, the child's name for a parent,
the Players chain for staff). The Print report action carries over to the card
tab. Bookmarks to the old card URL resolve to the unified profile.

# TalentTrack v4.63.3 — Spond sync captures venue name AND address (#2096)

A Spond event's location carries both a venue name and a street address;
the sync previously kept only the first non-empty field, so the address
was dropped whenever a venue name was present. It now keeps both on one
line — `Venue | Address`. Single-value locations are unchanged, and a
name already contained in the address isn't duplicated.

# TalentTrack v4.63.3 — Match prep: Formation KPI tile now follows the dropdown (#2098)

Changing the formation in the match-prep dropdown now updates the
**Formation** summary tile immediately. Previously the tile kept showing
the value the page loaded with while the pitch below it re-drew, so the
two could disagree. The shared KPI-tile helper gained an optional `data`
attribute map to give the tile a stable JS hook.

# TalentTrack v4.63.3 — Match prep: Export as PDF now captures the live on-screen view (#2102)

The match-prep toolbar's **Export as PDF (A4)** button now takes a picture
of the on-screen match-prep grid exactly as it appears — both formation
pitches (blue 1e / orange 2e with the white name pills), the Selection ·
minutes table, Wedstrijddoelen, Doen per speler and Roles & set pieces —
and lays it out on **portrait A4**, scaled to page width and split across
pages on overflow. Previously the export captured a separately-styled
print document, so it never matched what the coach laid out on screen.
The capture engine (html2canvas + jsPDF) stays lazy-loaded on first click.
The standalone print route and the browser print dialog remain available
as fallbacks; the team-sheet print is unchanged.

# TalentTrack v4.63.2 — Fix critical error when editing a match activity (#2097)

Opening any match in the activity edit form raised a WordPress critical
error. The match-length / participation block referenced an `$id` variable
that was never defined in that render method, so a null id was passed to
methods expecting a non-nullable integer and PHP aborted with a TypeError.
The id is now resolved from the loaded activity, and create-mode matches
(which have no id yet) skip the lookups. Editing matches works again.

# TalentTrack v4.63.1 — Player card folded into the unified profile as a tab (#1988)

The player-card showcase that used to live only on a player's own "My card" —
the skills radar, the FIFA-style player card, and the rating KPIs (Latest,
Last 5 with its momentum delta, All-time, Evaluations) — is now a "Player card"
tab on the one unified player profile. A coach, head of development or parent
viewing the player now sees that at-a-glance standing in context, without
leaving the page. Same audience as the rest of the profile; no extra permission,
and the card keeps its own coming-soon state before the first rated evaluation.

# TalentTrack v4.63.0 — Parent dashboard mirrors the player's tile grid (#2081)

A parent's dashboard now mirrors their child's own development tiles —
the same Me-group surfaces a player sees, in the same order — relabeled
to the child's first name as an Anglo possessive ("Sven's development",
"Sven's card", "Sven's evaluations"). This replaces the hardcoded
five-tile curation shipped in #1992.

Because the tiles are resolved through the normal tile registry, the
parent surface inherits module and `player_*` feature gating
automatically: switching off a player feature (e.g. `player_goals`)
removes that tile for both the player and the parent, with no
parent-specific list to maintain, and adding a new player Me-tile
surfaces for parents with no extra work. "My tasks" is included so a
parent can help remind their child of pending tasks. Account-level
tiles (settings, password) stay the parent's own — not child-scoped or
relabeled. The child anchor (name + photo), the multi-child switcher,
and `player_id`-scoped URLs (with `canViewPlayer` authorization) are
unchanged.

# TalentTrack v4.63.0 — Record-state filters render as one-tap status pills across list views (#2083)

The active/archived record-state filter on the Goals, Players, Teams, People,
Holidays, Tournaments, Evaluations and PDP-coverage lists is now the
mobile-first FilterBar status-pill control (Active / Archived / All) instead
of a dropdown — record state is the same one-tap pill on every surface. Same
query params and results as before. The PDP setup list drops its bespoke
Active/Archived links in favour of the shared control (operators who can act
on archived files still see it; the `filter[archived]` param and coverage
endpoint are unchanged). Dead CSS left behind by the FilterBar adoption
(#2082) in the prospects-overview and admin sheets was removed.

# TalentTrack v4.63.0 — Prospects status filter no longer shows "All" twice (#2093)

The Prospects overview status filter listed an explicit "All" option on top
of the FilterBar placeholder's own "All", so the dropdown showed it twice
after the FilterBar migration. The redundant option is removed; the filter
behaves identically.

# TalentTrack v4.62.0 — My PDP redesigned to a timeline-first player development view (#1990)

The player's *My PDP* surface is rebuilt around the season as a timeline. The
development conversations now sit on a horizontal rail as markers — completed,
the next planned talk, and later talks — with a progress fill up to the most
recent completed conversation. Tapping a marker expands that conversation's
detail in place (notes, agreed actions, agenda, goals discussed, saved
reflection and the acknowledgement button), so there is no long scroll.

Below the timeline the player sees their active focus goals with goal-specific
status labels, then a single self-reflection input for the one next-planned
conversation only — past and future talks never show an input, and there is
never more than one form. Any previously saved reflection appears to the right
of the input on wider screens and stacked below it on mobile. The 2-week
pre-talk window guard, the coach sign-off display, the acknowledgement flow and
the end-of-season verdict card are preserved.

The "which talk is next planned" and "is its reflection window open" decisions
live in the PDP domain layer (PdpCycleState), so the REST API and the rendered
view derive the same answer.

# TalentTrack v4.62.0 — Parents can open their child's development me-views (#1991)

A parent linked to a player but with no own player record was denied every
"Mijn …" me-view ("This section is only available for users linked to a
player record"). The dispatch gate checked "is the current user a player"
instead of "can the current user view this player".

The gate now authorizes the resolved target via
`AuthorizationService::canViewPlayer`, and the subject resolution falls back
to the parent's linked child (from `tt_player_parents`) when no explicit
`?player_id` is present: a single-child parent auto-resolves to that child;
a multi-child parent gets a child picker first and chooses. A user with no
own player and no linked child is still denied, and there is no cross-family
or cross-academy leakage (every read still passes `canViewPlayer`). The same
authority backs `GET /players/{id}`, so a non-WordPress front end gets the
same answer.

# TalentTrack v4.62.0 — Parent dashboard is now anchored on the child, not empty player-self tiles (#1992)

On the legacy tile-grid dashboard a parent saw an empty "Werk van vandaag"
column plus a "MIJN WERK" rail of player-self tiles that all denied (the
parent has no own player record). The grid had no parent-awareness.

A parent viewer (no own player, at least one linked child) now lands on a
child-scoped surface: the child's name and photo anchor the screen, a
curated parent tile subset is shown (development, player card, evaluations,
activities, development plan), each tile carries the child's `?player_id=N`
so the me-views resolve and authorize that child, and the empty
work-of-today column is hidden. A child switcher appears when the parent is
linked to more than one child. Which tiles and which child are domain
decisions (`ParentDashboardTiles` / `ParentChildResolver`), kept out of the
view.

# TalentTrack v4.62.0 — tt_player_parents is now the single source of parent → child links (#1993)

The `tt_player_parents` pivot is now the only live answer to "which children
does this parent have". The parent dashboard child switcher, the parent KPI
resolver, and the goal-thread participant graph previously matched
`tt_players.guardian_email` against the parent's WordPress email — a second,
divergent model that could disagree with the authorization layer (which
already read the pivot). They now all resolve through the new
`ParentChildResolver`, so the switcher and the me-view authorization list the
same children, club-scoped, with no email matching.

`guardian_email` is demoted to an invite/seed hint: it may still create a
pivot row when a parent is invited, imported, or seeded, but is never queried
to decide access. This is a code-only change with no migration — a parent
linked solely via `guardian_email` will surface once re-linked through the
invite/seed path or by an admin.

# TalentTrack v4.62.0 — Match executions: coach team scope no longer silently empty (#2016)

A head coach who owned teams could open Wedstrijduitvoeringen (match
executions) and still see "No teams visible to you yet", because the view
scoped coach teams via a hand-rolled JOIN on `tt_user_team_link` — a table
no migration ever creates, so the query returned nothing for every
non-admin coach. The same dead-table join silently emptied the
"Matches needing review" persona-dashboard widget. Both now resolve a
coach's teams through the canonical `QueryHelpers::get_teams_for_coach()`
(active `tt_user_role_scopes` grants plus the legacy backfill), so coaches
see their squad's match executions and pending-review reminders. A coach
with no team grants still sees the empty state. Admin / academy-wide lens
unchanged. Query-layer fix only — no schema or data changes.

# TalentTrack v4.62.0 — Recycle-bin foundation: schema, capability, retention config (#2020)

Lays the groundwork for the recycle bin (archive → trash → purge). Every
archivable record type now carries `trashed_at` / `trashed_by` columns, so a
later release can stage records for permanent deletion with a recovery window.

A new academy-admin-only capability, `tt_manage_recycle_bin`, owns permanent
deletion — it is never granted to coaches, Heads of Development, or anyone
holding only settings rights. A per-club retention window
(`tt_recycle_bin_retention_days`, default 30) is seeded for the future purge
process, and the bin gets its own `recycle_bin` authorization-matrix entity.

No user-visible behaviour changes yet — this is the substrate the bin's UI and
purge logic build on. See the new Recycle bin help page for the retention and
GDPR right-to-erasure basis.

# TalentTrack v4.62.0 — Recycle bin: archive → trash → purge lifecycle core (#2021)

Adds the recycle-bin domain core to `ArchiveRepository`: a third soft-delete
tier (active → archived → trashed → purged) layered on the existing archive.
Entities can now be moved to a recycle bin, restored back to archived, or
permanently purged through the existing fail-closed cascade. Trashed records
of minors are hidden behind the `tt_manage_recycle_bin` capability and scoped
to the club on every query, and each transition is recorded in the audit log.
Domain layer only — the bin's list view and REST endpoints land in follow-up
work; no user-visible screens change yet.

# TalentTrack v4.62.0 — Recycle bin: read-only archived/trashed detail (#2022)

Fixes Bug 1: opening an archived or trashed record showed "does not exist",
because every detail view's lookup ends in `WHERE archived_at IS NULL` and so
never received a non-active row. Detail views (players, teams, evaluations,
goals) now retry through the archive-aware visibility gate and render a
**compact read-only summary card** for archived and trashed records instead —
the record's identity plus a few key fields and a status banner, with no Edit
affordance (restore first, then edit).

An **archived** record shows an amber banner with who archived it and when,
plus **Restore** and **Move to recycle bin**. A **trashed** record shows a red
banner counting down to the purge, plus **Restore to archive** and **Delete
permanently now**, wired to two new `tt_manage_recycle_bin`-gated REST routes
(`POST recycle-bin/{entity}/{id}/restore`, `DELETE recycle-bin/{entity}/{id}`).

Privacy-critical: a trashed record is a soft-deleted minor's record. A
non-admin who opens a trashed record's link gets a clean "not found" — never a
permission-denied page that would confirm the record exists. The card lives in
a single shared `ArchivedDetailCard` renderer so the banners and actions can't
drift per entity.

# TalentTrack v4.62.0 — Recycle bin: archived-list affordances + payload audit (#2023)

Fixes a bug where archived holiday rows showed only Restore and no
destructive action: the holiday REST payload omitted `archived_at`, so the
list-table visibility check hid both archived-row actions. A new shared
`LifecycleFields` helper now emits `archived_at` plus the new `trashed_at`
on every list/detail payload that surfaces lifecycle state, so the field
can't drift per entity.

The archived-tier destructive action is relabelled from "Delete permanently"
to **"Move to recycle bin"** and re-pointed at a new reversible
`POST {entity}/{id}/trash` route (the irreversible purge stays inside the
recycle bin). Moving a record now shows a full itemized cascade preview in
the confirm dialog, and the success banner offers one-click **Undo**. The
per-entity "All" status tab is dropped — trashed records never appear in
ordinary lists, leaving Active and Archived as the only views.

# TalentTrack v4.62.0 — Recycle bin: centralized view + REST + settings entry point (#2024)

Adds the centralized recycle bin — a single admin-only screen
(`?tt_view=recycle-bin`, reachable from Configuration → System) that lists
every trashed record across all 20 archivable entity types, grouped by type
with counts. Each row shows its identity, who and when it was binned, and a
days-until-purge badge that turns red in the final week. Two inline actions:
**Restore** returns the record to the archive, and **Delete now** permanently
purges it after a cascade-preview confirm. A blocked purge surfaces the
dependency report and leaves the record in place.

The bin is academy-admin only (`tt_manage_recycle_bin`). Three new REST
routes back it: `GET /recycle-bin` (cross-entity list), `POST
/recycle-bin/{entity}/{id}/restore`, and `DELETE /recycle-bin/{entity}/{id}`.
Every mutating route verifies both the capability and that the target belongs
to the current academy before it runs, so a forged or foreign-tenant id is a
not-found, never a silent success. The `{entity}` segment is validated against
the archive's entity allowlist.

Closes the "no purge path weaker than the bin" gap: every legacy per-entity
permanent-delete endpoint (`DELETE …/permanent`) is re-gated onto
`tt_manage_recycle_bin`, so all permanent-deletion paths now require the same
capability as the bin's own purge.

# TalentTrack v4.62.0 — Recycle bin: 30-day automatic purge (#2025)

Adds the unattended purge that empties the recycle bin after the retention
window. A daily sweep finds every record trashed longer than the club's
retention window (default 30 days) and permanently deletes it — no one has to
remember to empty the bin.

The sweep runs through the same fail-closed deletion path as the manual
"Delete now": player and person records are erased across every linked table
via their cascade services, so a minor's child PII is never stranded. It runs
on the workflow engine's existing background schedule (not a separate cron),
self-throttles to once per day, and is scoped per academy so a record is only
ever purged within its own tenant. Because the job runs with no one logged in,
its audit entries are attributed to the system, so the audit log never implies
a person pressed delete.

Records the purge cannot delete — because other records still reference them —
are skipped, left safely in the bin, and surfaced in the recycle-bin view with
a banner ("N records couldn't be auto-deleted — still referenced"). A few
record types (measurement definitions and trial tracks) are templates that can
never auto-purge by design; the bin now flags those so the 30-day countdown is
never read as "these vanish at 30 days".

# TalentTrack v4.62.0 — Shared filter bar, adopted on Activities (#2026)

A new reusable filter bar replaces the bespoke filter row on the
Activiteiten list. On desktop it lays the controls out on a single line —
each under its own label, with the four control types kept visually
distinct (Team/Type selects, a Period pill-dropdown, Active/Archived/All
status pills, and a Show-cancelled switch). On a phone or tablet the bar
collapses to a **Filters** button with an active-count badge plus summary
chips; tapping it opens a bottom sheet with the same controls and an
Apply / Clear footer. Keyboard- and screen-reader-operable, with the
sheet closing on Escape, scrim tap, or the close button.

All existing Activities filtering is unchanged — Team, Type, period
quick-windows, archive status, and Show-cancelled keep the same query
params and produce the same results. The new `FilterBar` component is
data-driven and carries no Activities-specific logic, so the other list
views can adopt it in later phases of the filter-bar epic (#2017).

# TalentTrack v4.62.0 — Teams and activities can now be permanently deleted, player data preserved (#2027)

Permanently deleting a team or an activity used to be refused outright while
anything still referenced it, so a trashed team or activity could never be
purged and accumulated in the recycle bin forever. Both now have a complete,
player-centric delete plan.

Deleting a **team** removes only pure team configuration (formations, playing
styles, chemistry, blueprints, staff assignments, per-team exercise overrides
and the VCT periodization stack). The team's players, their team history, the
team's activities (with their attendance and evaluations), tournaments and
measurement sessions are all kept and re-homed to "unassigned" rather than
deleted. Open invitations, workflow tasks and staged ideas pointing at the
team simply have the link cleared.

Deleting an **activity** removes the execution data that only lives inside it
(attendance, planned exercises, principles, and the match-prep and
match-execution trees) plus its journey events, while evaluations, behaviour
ratings and tournament/VCT bindings survive with their link cleared.

A development record is never destroyed by deleting a team or activity — worst
case it is left unassigned. The deletion framework gains a "reset to
unassigned" disposition for required links that can't be emptied, and a
fail-closed completeness check guarantees a future schema change can't quietly
make teams or activities un-deletable again.

# TalentTrack v4.62.0 — Goal-detail page: goal left, conversation right two-column layout (#2029)

The standalone goal-detail pages now place the goal card on the left and the
Gesprek (conversation) on the right in a two-column grid on tablet and wider
screens (>=768px), stacking goal-then-conversation on phones. This applies to
both the coach view (`?tt_view=goals&id=N`) and the player/parent view
(`?tt_view=my-goals&id=N`), matching the existing two-column treatment on the
POP detail. Layout-only change: the grid and spacing moved out of inline
styles into the enqueued `frontend-goals.css` sheet; no data or query changes,
and the conversation pane (bubbles + compose box) is unchanged.

# TalentTrack v4.62.0 — Team detail: hide the chemistry teaser when the feature is off (#2033)

The "Team chemistry" card and its *Open the chemistry board* link on the
team detail view no longer appear when the `team_chemistry` sub-feature is
switched off, or for personas without chemistry read authority. The teaser
now uses the same access gate as the chemistry board itself, instead of
rendering whenever the module class is loaded.

# TalentTrack v4.62.0 — Branded TalentTrack 404 replaces the theme 404 and "Unknown section" (#2035)

A bad URL or an unknown `?tt_view=` slug now lands on one consistent,
branded "Offside! This page is out of play" page instead of the active
theme's 404 or a bare "Unknown section." line. The real WordPress 404 is
rendered through the same theme-free canvas chokepoint as the dashboard —
HTTP 404 status preserved, no theme chrome leaking — and offers a single
"Back to dashboard" button. The in-app fallback shows the same branded
content inside the dashboard, with the breadcrumb chain as the way back.
Operators running TalentTrack alongside other content can disable the
takeover via the club-scoped `tt_handle_wp_404` config flag or filter
(defaults on).

# TalentTrack v4.62.0 — PDP conversation breadcrumb returns to the player's file (#2038)

Opening a conversation from a PDP file and then using the breadcrumb back
affordance now returns to that player's PDP file, not the whole PDP list.
The conversation page previously reused the file-detail breadcrumb chain,
whose only clickable step was the PDP list. It now renders its own chain —
"PDP → PDP file detail → Conversation" — with the file-detail crumb as the
back-to-file step. Navigation only; no data or query changes.

# TalentTrack v4.62.0 — PDP coverage list: remove redundant "Open" button (#2039)

Rows in the PDP coverage list that already have a development plan no longer
show a separate "Open" button — the whole row is already clickable, and the
green coverage pill is a link to the same file for keyboard and assistive
tech. The "Create PDP" action on players without a plan is unchanged.

# TalentTrack v4.62.0 — PDP list: one player-centric list with Active/Archived + team gate (#2040)

The PDP tile now opens on a single player-centric list — the old Coverage /
Files tab split is gone. Archived PDP files moved into the same list behind
**Active / Archived** state pills (for operators who can unarchive or delete),
each archived row keeping its Restore / permanent-delete actions. Users who
span more than one team (or have global scope) now pick a team first ("Select
a team to see its players.") instead of facing an unscoped all-players list; a
single-team coach goes straight to their roster. The redundant per-row Open
button stays gone (#2039). The `pdp-files/coverage` REST endpoint gained an
`archived` parameter for the new view.

# TalentTrack v4.62.0 — PDP conversations: only the active conversation is fully editable (#2041)

PDP conversations now run strictly in order. Only the active conversation —
the earliest one not yet signed off — is fully editable. Later conversations
in the cycle are read-only except for their planned date, so a coach can
schedule the whole season ahead without filling in a talk out of turn. A
later conversation opens for full editing once the one before it is signed
off. Enforced both in the form and in the REST endpoint. Signed/acknowledged
conversations keep their existing end-to-end lock.

# TalentTrack v4.62.0 — PDP: coach can record player/parent acknowledgement (#2042)

When a development conversation is held in person, the coach can now record
the player's and/or parent's acknowledgement on their behalf, straight from
the conversation form — *Record player acknowledgement* / *Record parent
acknowledgement*, each behind a confirm dialog. It writes the same
acknowledgement a player or parent would record themselves, available once
the coach has signed the conversation off. The player/parent self-service
acknowledgement on *My PDP* is unchanged.

# TalentTrack v4.62.0 — PDP verdict: gated and moved next to the conversations (#2043)

The end-of-season *Record verdict* button moved out of the top action bar to
sit with the conversation list, below the cycle. It now stays disabled until
every conversation in the cycle is signed off, showing the progress on the
button itself — e.g. *Record verdict (3/5 conversations closed)* — so it's
clear why it isn't available yet rather than simply missing. Once all
conversations are closed it enables and opens the existing verdict form.

# TalentTrack v4.62.0 — Strava integration — schema foundation (#2055)

Adds the database foundation for the per-player Strava integration (epic
#2002): a `tt_player_strava_connections` table holding one encrypted-token
connection per player, and a player-scoped `tt_player_activities` table for
the personal training (runs, rides, conditioning) those connections import.
Both carry the `club_id` + `uuid` tenancy scaffold. Activities store
distance, duration, pace and elevation only — no heart-rate data, by design.
Schema-only; no behaviour change until the connect flow ships.

# TalentTrack v4.62.0 — Strava integration — OAuth connect flow (#2056)

Adds the per-player Strava account connection flow (epic #2002). Players (and
coaches/admins acting on a player) can start a one-time OAuth authorization
that links a Strava account to the player's TalentTrack record. The OAuth
callback authenticates via a signed, time-limited `state` — the one route
that can't use a WordPress nonce — exchanges the code for tokens server-side,
and stores the access + rotating refresh token encrypted at rest, per player.
Disconnecting revokes the grant at Strava and clears the stored tokens.

No activities sync yet — this slice is the connection plumbing; the token
refresh, webhook, and ingest slices follow. Access tokens are never exposed
to the browser; the Strava app client secret is write-only.

# TalentTrack v4.62.0 — Strava integration — token refresh service (#2057)

Keeps connected players' Strava access tokens fresh (epic #2002). Strava
tokens expire after six hours and the refresh token rotates on every refresh,
so a connection is kept alive two ways: a proactive sweep on the workflow
engine's hourly heartbeat refreshes any token nearing expiry, and an on-demand
refresh runs immediately before an activity sync if needed. The rotated
refresh token is always saved atomically with the new access token. If Strava
rejects a refresh (the grant was revoked), the connection is flagged so the
player can reconnect, instead of retrying a dead token forever.

# TalentTrack v4.62.0 — Strava integration — activity ingest (#2058)

Imports a connected player's Strava activities onto their TalentTrack record
(epic #2002). When an activity is recorded, it's fetched with the player's
token and saved to the player's own activity list — distance, duration, pace
and elevation only. Heart-rate and other biometric data are never read or
stored, by design, so the integration works for the academy's mostly-minor
cohort without tripping Strava's under-16 heart-rate restriction. Deleting an
activity in Strava (or disconnecting) archives it on our side. A new read
endpoint exposes a player's imported activities for the profile timeline.

# TalentTrack v4.62.0 — Strava integration — webhook sync (#2059)

Wires up live, push-based syncing for the Strava integration (epic #2002).
Instead of polling, TalentTrack registers a single academy-wide webhook
subscription with Strava and reacts to pushes: a new or edited activity is
imported within minutes, a deleted activity is archived, and when an athlete
disconnects from Strava's side their connection is revoked and their imported
activities are archived automatically. The subscription is operator-managed
(create / view / delete), and the validation handshake is answered securely
with a per-install verify token.

# TalentTrack v4.62.0 — Strava integration — consent capture + audit (#2060)

Adds the consent gate for connecting a Strava account (epic #2002, Gate 2).
Connecting now requires an explicit, audit-logged consent acknowledgement,
and the consent is recorded before any redirect to Strava — enforced on the
server, so the authorization step cannot be reached without it. The recorded
consent (and when it was given) is surfaced on the connection status.

Per the product decision of 2026-06-28, consent is captured on the player's
own profile rather than a parent's view — a deliberately simpler flow whose
minor-safeguarding trade-off is recorded for future legal review.

# TalentTrack v4.62.0 — Strava integration — connect panel on the player profile (#2061)

Adds the player-facing Strava panel (epic #2002): a mobile-first "Connect with
Strava" surface reachable at its own page (`?tt_view=strava`) and as a Strava
tab on the player profile. It shows connection status, a consent checkbox that
must be ticked before connecting, a disconnect button, and the imported
activities (distance, duration, pace — no heart-rate). Connecting sends the
player through Strava's authorization and brings them back to the profile with
a clear confirmation. Fully translated into Dutch.

# TalentTrack v4.62.0 — Player profile: hide the PHV panel when the VCT module is off (#2064)

The player profile's PHV control (the "Speler heeft een PHV-vlag" checkbox +
reason dropdown) is VCT functionality, but it rendered even when the VCT
module was switched off. The PHV hero pill, the Profile-tab panel, and the
PHV form POST handler now all gate on the VCT module being enabled, so a club
that doesn't use VCT no longer sees misleading conditioning controls on a
player's record. Behaviour is unchanged when VCT is on.

# TalentTrack v4.62.0 — Team profile: squad panel no longer shows archived or trashed players (#2065)

Archiving a player removed them from active rosters everywhere except the
team profile's squad panel (and, for trial players, the trials sub-panel),
where they kept appearing — an archived or released minor resurfacing in a
roster a coach was browsing. The three player-fetch helpers behind those
panels (`QueryHelpers::get_players()`, `QueryHelpers::get_players_for_teams()`,
and the team-detail trial loader) filtered on `status` alone, which is
orthogonal to the archive/trash lifecycle introduced with the recycle bin.
They now append the canonical active-lifecycle clause
(`ArchiveRepository::filterClause('active', 'p')`), so archived and trashed
players drop out of the squad panel, the trials sub-panel, and coach-dashboard
rosters immediately. Active players are unaffected. Query-layer fix only — no
schema or data changes.

# TalentTrack v4.62.0 — Chemistry settings: dark-mode legibility + compact number inputs (#2069)

The Chemistry settings page (`?tt_view=chemistry-config`) is now legible in
dark OS/browser modes again. The partial `prefers-color-scheme: dark` block
darkened the block background but never lightened the text, leaving dark-on-dark
legends, labels and hints — the surface has no real dark variant, so that block
is removed and the page stays on its light design system. The numeric weight
inputs also no longer blow out to full row width: the selector now wins over the
global `.tt-input { width: 100% }` rule, restoring compact ~5rem right-aligned
boxes with the label-left / input-right flex row intact. CSS only.

# TalentTrack v4.62.0 — Chemistry settings page + tile hidden when team chemistry is off (#2071)

With the Team chemistry feature switched off, the Chemistry settings view
(`?tt_view=chemistry-config`) and its dashboard tile stayed reachable while
the main formation board correctly hid. The `team_chemistry` feature now
claims the `chemistry-config` slug too, so the dispatcher renders the
standard module-disabled notice before the view loads — for administrators
as well as other roles — and the settings tile carries the feature tag so it
disappears from the dashboard when the feature is off. With the feature on,
the page and tile behave exactly as before.

# TalentTrack v4.62.0 — Goal conversation restyled to the green/gold timeline (#2072)

The conversation ("Gesprek") on a player's goal-detail page used a generic
blue chat style — a right-aligned blue self-bubble and navy author names —
that clashed with the 2026 green/gold design shown to parents and players in
the pilot presentation. It now renders as a single left-aligned timeline:
each message carries a green ring marker, a muted date above a bold green
author name, and a white bubble with a thin border, and the Send button is
green. The change is in `frontend-threads.css` only — markup, REST, polling,
and the edit/delete affordances are untouched, so both initially-rendered and
newly-posted messages share the look. Mobile-first rules are preserved
(360 px single column, 48 px Send, 16 px textarea, focus-visible rings,
reduced-motion).

# TalentTrack v4.62.0 — List filters get the mobile-first FilterBar chrome (#2082)

Every list surface built on `FrontendListTable` (players, goals, teams, people,
evaluations, holidays, tournaments, prospects, functional roles, custom fields,
my activities, PDP, …) now renders its filter row through the shared, mobile-first
FilterBar: a single inline row on wide screens that collapses to a "Filters"
button and a bottom sheet on phones. Filters are the same as before — the team /
type / status selects, the search box, and the from/to date ranges all filter
exactly as they did, with the same URL parameters, sorting, pagination and
live-filtering — they just gain a touch-friendly layout on small screens.

The list table keeps owning rows, sorting, pagination and per-page; only the
filter chrome moved. FilterBar gained free-text/search and date-range group
types and an opt-in status-pill rendering for views that want one. No view
needed changes to inherit the new chrome.

# TalentTrack v4.62.0 — Week-PDF: match cards show the typed title (#2089)

Match activities on the team-planner Week-PDF now print the title entered
on the activity form (e.g. "Candia 66 – Vv hedel 14-1") instead of
collapsing to just the team name. The card previously synthesized its
title as "Team — Opponent" and ignored the activity's own Title field;
since the form captures a required Title but no opponent, matches printed
only the team name. The card now prefers the entered title and falls back
to "Team — Opponent" (or the team name) only when no title is set. Match
location is unchanged — it already prints when the Location field is filled.

# TalentTrack v4.61.0 — Holiday rows now open an enriched read-only detail view (#1997)

Clicking a holiday row used to drop managers straight into the edit form and
left read-only viewers with inert rows. It now opens a scheduling-centric,
read-only detail page at `?tt_view=holidays&id=N` for every viewer who can see
holidays. The page shows the holiday name, the period formatted in the active
locale (e.g. "21 dec 2026 – 4 jan 2027"), the inclusive duration in days, the
note (or a dash), the colour swatch when one is set, and a one-liner reminding
the user the holiday banners across these days on every team planner. Managers
get an Edit button into the existing edit form; non-managers see the summary
only. The list-table row link points read-only viewers at the detail view, so
their rows are clickable for the first time.

A computed `day_count` (inclusive day span) is now exposed on the holiday REST
payload (`GET /holidays` and `GET /holidays/{id}`); the day-count maths lives
in `HolidaysRepository::dayCount()` so the REST API and the rendered view stay
in lockstep.

# TalentTrack v4.61.0 — Head coaches can open the Trial cases tile again (#2005)

The Trial cases list view gated entry on `tt_manage_trials`, which maps to
`trial_cases:create_delete`. Head coaches hold `trial_cases [read, change]`
at team scope in the authorization matrix but not `create_delete`, so the
tile let them in but the view returned a "no permission" page. The view now
gates entry on a matrix read check (matching the tile), scopes the list to
the players on the head coach's own teams, and keeps the "New trial case"
create action plus the create/delete write paths gated on `tt_manage_trials`.
Head coaches can now view and edit trial cases for their teams; only managers
can create or delete them. Scout, head-of-development and admin behaviour is
unchanged.

# TalentTrack v4.61.0 — Player comparison selectors now respect coach context (#2006)

The Player comparison team and player selectors no longer expose the whole
academy roster to a team-scoped coach. Both the frontend tile and the
wp-admin Player Comparison page now narrow the selectors to the coach's own
teams, exactly like the standard reports surface and the `reports/player-radar`
REST endpoint: staff with academy-wide reporting access (head of development,
academy admin, scout) still see every team and player, while a team-scoped
coach sees only their assigned teams and the players on them. The scope is
also enforced on players addressed directly by `?pN=` link, so an
out-of-context player can't be pulled into a comparison.

# TalentTrack v4.60.0 — My journey: position-change events show friendly position names (#1983)

A position-change entry on a player's journey timeline now reads the
human-friendly position names ("Centrale verdediger, Linksback") instead of
the raw codes — or, for older entries, the raw JSON array `["CB","LB"]`. The
event formatter resolves each code through the shared position-label
translator, and a one-time backfill rewrites existing position-change events
so historical entries read the same. Unknown / custom positions pass through
unchanged.

# TalentTrack v4.60.0 — Evaluations: the staff-only note field is now clearly labelled (#1984)

When writing an evaluation (both the rate-players wizard step and the flat
coach form), the free-text note field was labelled simply "Notes" — with no
sign that it is staff-internal and never shown to the player. Coaches typed
player-directed feedback there, expecting the player to read it, while the
separate "Feedback for the player" field stayed empty. The field is now
labelled "Internal notes (staff only)" with a "Not shown to the player"
placeholder, so the two audiences are unmistakable. The player-facing
feedback continues to appear on the player's My evaluations detail; the
internal note stays staff-only.

# TalentTrack v4.60.0 — Goals: the "pending" status reads "In ontwikkeling" in Dutch (#1985)

A player goal that is still pending now reads the more development-minded
Dutch label **"In ontwikkeling"** instead of "In behandeling". Goal statuses
now carry their own gettext context, so this wording is specific to goals —
the generic "Pending" label used elsewhere in the app is unchanged.

# TalentTrack v4.60.0 — My activities: full-width on desktop, all info inline (#1986)

The player's **My activities** list now uses the full dashboard width on
desktop instead of a narrow 860px column. Rows are no longer clickable — the
old row link pointed at the staff activity-detail view, which a player isn't
authorised for (it returned "niet geautoriseerd"). Everything a player may
see is now shown inline in the table, including a new **Location** column
alongside date, title, type, team and their own attendance status.

# TalentTrack v4.60.0 — Academy admin can switch off individual player dashboard tiles (#1987)

The player dashboard tiles — My journey, My team, My evaluations, My
activities, My goals and My PDP — are now per-academy features under the
Players module on the Modules &amp; features screen (`?tt_view=modules`). They
ship on; switching one off hides that tile from players *and* blocks its
`?tt_view` URL for this academy, reusing the existing feature-toggle plumbing
(per-club state, REST-managed). The player profile remains the always-on
anchor and is intentionally not toggleable.

# TalentTrack v4.60.0 — My team: next match and recent results for players (#1989)

A player's **My team** view now shows two pieces of non-sensitive team
information beyond the podium: the team's **next match** (date, opponent,
home/away, location) and a **recent results** form line — the last few match
outcomes framed from the team's perspective (win / draw / loss with the
score). No individual teammate ratings or rankings are exposed. The match
result fields are also surfaced on the activities REST payload.

# TalentTrack v4.60.0 — Academy toggle to switch off the install-on-mobile prompt (#1994)

Configuration → General gains a **Show the install-on-mobile prompt** toggle.
Players and parents get a post-login banner inviting them to install the app
on their phone; an academy admin can now switch that banner off for everyone in
the academy. It ships on, so existing installs are unchanged. The setting is
per-academy (`club_id`-scoped via `tt_config`), capability-gated, and saved
through the config REST endpoint.

# TalentTrack v4.60.0 — Per-report feature toggles for the Reports module (#1995)

The Reports module now exposes a feature toggle per report on the Modules &
features screen — the eight standard reports plus the two wp-admin reports
(10 in all) — mirroring the Export module's per-tile toggles. They ship on, so
a fresh upgrade shows every report. Switching one off hides its launcher tile
(frontend launcher + wp-admin Reports page) and rejects a direct link to that
report. The whole-module Reports toggle still works; when off, the ten
sub-toggles disappear. State is per-academy (`tt_feature_state`, `club_id`).

# TalentTrack v4.59.0 — Backups move to a frontend view, incl. restore + data migration (#1937)

The Backups surface now lives on the frontend at **Configuration → Backups**
(`?tt_view=backups`) instead of bouncing to wp-admin. The full surface ported
across: schedule / retention / destination settings (with Cancel + Save),
the stored-backups list (download, restore, delete), Run now, the destructive
database **restore** behind a typed-confirm "RESTORE" gate, and the complete
`.ttmig` data-migration flow — export, then upload → preview → dry-run →
typed-confirm "IMPORT" commit.

Every mutating action runs through a capability-gated, nonce-protected REST
endpoint (`tt_manage_backups`) on the new `BackupRestController`; the
serialization, restore engine and migration engine stay in the Backup module
services, so the frontend and the wp-admin page give identical answers. The
two destructive writes (restore + import commit) preserve the typed
confirmation, refuse to run while impersonating another user, and are written
to the audit log (`backup.restored` / `migration.imported`). Backup downloads
are returned as a URL rather than a server-relative path, so the list keeps
working unchanged if storage moves off the local filesystem.

The wp-admin Backups tab stays as the power-user fallback and still owns the
Partial restore scope-picker; the frontend list links to it.

# TalentTrack v4.59.0 — First-run Setup moves to a frontend flow (#1938)

The first-run onboarding wizard now lives on the frontend at
**Configuration → Setup** (`?tt_view=setup`) instead of bouncing to
wp-admin. The full flow ported across: a stepper through academy basics →
first team → first admin → dashboard page → done, with skip on the optional
steps, Cancel on every step, and a "Run again" / "Start over" affordance
that re-enters the flow without deleting the teams, staff, or pages you
already created. Progress is saved automatically, so you can stop and resume
from the step you left off on.

New REST endpoints back every step — `POST /onboarding/advance`,
`/onboarding/academy`, `/onboarding/first-team`, `/onboarding/first-admin`,
`/onboarding/dashboard-page`, and `/onboarding/reset` — all gated on
`tt_edit_settings`. The controller is thin: every side effect (team / staff
creation, the Club Admin grant, dashboard-page creation, state advance)
reuses the same `OnboardingHandlers` / `OnboardingState` domain layer the
wp-admin wizard uses, so the two surfaces never drift. The wp-admin Setup
wizard stays as the power-user fallback.

# TalentTrack v4.59.0 — Player-notes access no longer gated by WP role name (#1956)

The player-notes thread adapter no longer denies access based on the
player or parent WP role name. Its decision now rests solely on the
player-notes capability plus the existing team-ownership scope check —
pure players and parents, who hold no player-notes capability, stay
denied exactly as before. (A follow-up, #1982, tracks how dual-role
staff-and-parent accounts resolve that capability.)

Also removed an unused duplicate role-lookup helper from the
authorization service — pure cleanup, no behaviour change; the canonical
role-lookup chokepoint is untouched.

# TalentTrack v4.59.0 — Coach dashboard: batch the per-team podium query (#1959)

The coach "My teams" roster tab now computes every team's top-3 podium in a
single batched pass instead of running three queries per team. For a coach
with N teams this collapses the podium workload from roughly 3N queries to a
constant 3 regardless of team count. Podium output is byte-identical — same
players, same order, same rolling values — as the ranking logic is now shared
between the single-team and batched code paths. Performance only; no
behaviour change.

# TalentTrack v4.59.0 — Player dashboard: the Evaluations tab now hydrates every evaluation's ratings in a single batched query instead of one detail query per row, collapsing a 1+N database pattern into a constant two queries. Pure performance — the rendered table is byte-identical.

Player dashboard: the Evaluations tab now hydrates every evaluation's ratings in a single batched query instead of one detail query per row, collapsing a 1+N database pattern into a constant two queries. Pure performance — the rendered table is byte-identical.

# TalentTrack v4.59.0 — Blueprint editor: faster load via batched roster query (#1962)

The team-blueprint editor's "+ Add → Other team" picker built its
cross-team roster with one player query per sibling team (an N+1). It now
fetches all sibling-team players in a single batched query and groups them
in PHP. The editor also read the formation-template table twice per page
(once for the toolbar dropdown, once for the JS payload); it now fetches
those rows once and reuses them. Output is unchanged — purely fewer
queries on load.

# TalentTrack v4.59.0 — Usage detail: paginate the login and user-timeline event lists (#1963)

The usage-statistics drill-downs for **Logins** and a user's **Timeline** no
longer pull up to 500 rows into memory on every page view. Each list now
fetches a bounded 50-row window with a `COUNT(*)` for the total, and a
prev / next pager (with a "Page X of Y" indicator) lets you walk through the
full history a page at a time. The total event count shown above the table is
still the real total, not just the rows on the current page. Performance only;
no change to which events are recorded or who can see them.

# TalentTrack v4.59.0 — Faster player evaluation and attendance reads (#1964)

Added two database indexes for the hottest player-scoped read paths.
Evaluation lookups now seek on a `(player_id, club_id)` composite instead of
filtering one column as a residual, and a player's attendance history — which
matches both roster rows and linked-guest appearances — can index-merge the
two lookups rather than scanning the attendance table. Pure performance: no
behaviour, query output, or data changes. Final slice of the performance
umbrella (#1649).

# TalentTrack v4.59.0 — Evaluations view: one batched query for the coach player filter (#1971)

The evaluations list page built its player-filter dropdown by running one
player query per coached team — an N+1 that scaled with a coach's team
count. It now loads every active player across the coach's teams in a single
batched query. The rendered options are identical; this is a pure
performance change with no behaviour or output difference. Closes the last
N+1 on the perf umbrella's suspect list (#1649).

# TalentTrack v4.59.0 — Player journey now records the actual evaluation rating (#1974)

The player-journey evaluation event (`evaluation_completed`) read a
non-existent `overall_rating` column from `tt_evaluations`, so the query
errored and every evaluation was recorded on the timeline with an overall
of `0.0`. It now reads the real `rating` column, both for live saves
(`JourneyEventSubscriber`) and for the historical backfill
(`JourneyBackfillService`). Existing zeroed events are corrected the next
time the journey is rebuilt; no schema change.

# TalentTrack v4.59.0 — PDP evidence packet now includes the player's evaluations (#1976)

The PDP evidence packet's evaluations query referenced two columns that
don't exist on `tt_evaluations` — `overall_rating` (the real column is
`rating`) and `status_finalized` (no such column anywhere) — so the query
always errored and `evaluations` came back empty for every player. The
query now reads the real `rating` column and treats any non-archived
evaluation in the window as evidence (`archived_at IS NULL`), matching how
the player journey selects evaluations. No schema change.

# TalentTrack v4.59.0 — Tournament auto-balance is now a per-academy toggle (#1979)

The greedy fair-share auto-planner for tournament matches is now a toggle
on the Modules management page (**Tournament auto-balance**), on by default
so nothing changes on upgrade. Switch it off and the Auto-balance button is
removed from every match card and the `auto-plan` REST route returns 403, so
the toggle can't be bypassed by a direct call; the per-match planner grid and
manual click-to-swap planning are untouched. Closes out the last actionable
item from the #1538 FeatureRegistry tracker.

# TalentTrack v4.58.0 — VCT exercise catalogue — full 80 (#1129)

The VCT exercise catalogue now ships its full 80-exercise spread.
Migration 0181 adds 68 exercises on top of the 0177 scaffold's 12,
reaching the target per-category counts: warmup 10, technical 20,
sided_game 20, conditioning 10, finishing 10, cool_down 10. Each
exercise carries three to four coaching points in canonical English
plus native Dutch, and every intensity band respects the per-age
workload ceilings so no exercise exceeds the envelope for the youngest
age it's offered to. The seed is idempotent and forward-only.

The fr_FR / de_DE / es_ES coaching-point translations, per-exercise
diagrams, and the HoD / pilot-coach methodology review of the picks,
intensity bands, and age ranges are a deliberate follow-up — #1129
stays open until they land.

# TalentTrack v4.58.0 — Spond integration moves to a frontend view (#1936)

The Spond integration now lives on the frontend at **Configuration → Spond
integration** (`?tt_view=spond`) instead of bouncing to wp-admin. The full
surface ported across: per-team sync status with a "Refresh now" button,
the next-automatic-sync time, encrypted account credentials (save / test /
disconnect), and the collapsible API base-URL override. The Spond password
stays encrypted at rest via `CredentialsManager` and is never shown back —
a connected account displays "Connected as <email>" with a blank password
field. New REST endpoints back every action: `POST/DELETE /spond/credentials`,
`POST /spond/test`, `POST /spond/base-url` (gated on `tt_edit_spond_credentials`)
plus the existing `POST /teams/{id}/spond/sync` (gated on `tt_edit_teams`).
The wp-admin page stays as the power-user fallback.

# TalentTrack v4.58.0 — Authorization: give the exercise library a matrix entity (#1944)

The club-global exercise / drill library now has its own `exercises`
authorization-matrix entity, distinct from the `activities` session calendar. The
previously unmapped `tt_manage_exercises` write capability is bridged through
`LegacyCapMapper`, so the library's REST write paths resolve access from the matrix
once it is active instead of from raw WordPress capabilities. The seed grants
read + create + delete to head coaches, assistant coaches, the Head of Development,
and the Academy Admin — exactly reproducing today's raw cap holders, so no persona
gains or loses access. In particular, assistant coaches keep their library write
access (the `tt_coach` role backs both coach personas). A backfill migration adds
the entity to existing installs.

# TalentTrack v4.58.0 — Authorization: give the in-product mailer a matrix entity (#1945)

The in-product email composer now has its own `email_compose` authorization-matrix
action-entity. Sending an email is an act rather than a record — like impersonation
— so the previously unmapped `tt_send_email` capability is bridged through
`LegacyCapMapper` to `email_compose:create_delete`, resolving access from the matrix
once it is active instead of from raw WordPress capabilities. The seed grants
read + create + delete (academy-wide scope) to head coaches, assistant coaches, the
Head of Development, and the Academy Admin — exactly reproducing today's raw cap
holders, so no persona gains or loses access. In particular, assistant coaches keep
the composer (the `tt_coach` role backs both coach personas). A backfill migration
adds the entity to existing installs.

# TalentTrack v4.58.0 — Authorization: bridge report generation to the matrix (#1946)

The report-generation capability `tt_generate_report` (distinct from
`tt_generate_scout_report`) is now resolved from the authorization matrix once it is
active. Generating a report is a create act, so the cap is bridged through
`LegacyCapMapper` to `reports:create_delete`. Because the `reports` matrix entity
previously granted coaches and the Head of Development only read access, a naive
bridge would have revoked generation from them — so access is preserved by adding
the `create_delete` grant instead: head coaches and assistant coaches at team scope,
the Head of Development globally (the Academy Admin already held it). Both coach
personas are seeded so assistant coaches keep generation (the `tt_coach` role backs
both). Team managers, scouts, players and parents keep read-only and gain nothing.
A backfill migration adds the new grants to existing installs.

# TalentTrack v4.57.0 — MFA QR encoder — independent round-trip verification + CI gate (#1393)

Closes out the MFA-enrollment-QR bug. The payload + render fixes shipped earlier
(smaller otpauth URI, no silent truncation, larger render); the remaining risk was
that the hand-rolled QR encoder's v6–v10 paths — the only ones a real otpauth URI
ever exercises — were unverified. A new standalone check
(`scripts/qr-roundtrip-verify.php`, run in CI) encodes a representative corpus with
the production encoder, decodes each result with an independent from-spec ISO/IEC
18004 decoder, and asserts the decoded string equals the input. All versions v6–v10
round-trip cleanly, proving the encoder is correct, and the gate prevents
regressions. No user-facing change.

# TalentTrack v4.57.0 — Translations config moved to the frontend (#1935)

The auto-translation engine configuration is now a frontend view at
`?tt_view=translations` instead of bouncing to wp-admin. The Configuration
"Translations" tile opens it directly. The view covers everything the old
wp-admin tab did — enable toggle, primary/fallback engine, DeepL key and
Google service-account JSON (both kept masked with a "(set)" indicator),
site default language, monthly character cap, notify threshold, the GDPR
sub-processor confirmation, the read-only usage table, and the Clear cache
action. Settings save through a new REST surface
(`POST /translations/settings`, `POST /translations/clear-cache`) gated on
`tt_view_translations` / `tt_edit_translations`; the validation,
keep-on-blank credential handling, and GDPR opt-out cache purge all run in
the domain layer, shared with the wp-admin tab. The wp-admin tab stays as a
power-user fallback.

# TalentTrack v4.57.0 — Authorization: route remaining blueprint + player-potential caps through the matrix (#1939)

The Team-blueprint creation wizard and the blueprint comment thread now
resolve access through the `team_chemistry` matrix entity (via
`TeamChemistryAccess`) instead of the raw `tt_*_team_chemistry`
capabilities, completing the #1922 consolidation so the whole blueprint
feature answers from one source. The PlayerStatus "set potential band"
act-cap (`tt_set_player_potential`) is now bridged to the
`player_potential:change` matrix entity, closing a frontend/REST
divergence where its data-cap sibling was already matrix-aware. All three
re-points are access-preserving — the personas who could act before still
can. The behaviour-rating act-cap (`tt_rate_player_behaviour`) was left on
native capability evaluation and flagged on the issue: bridging it would
have revoked assistant-coach access, an effective-access change that needs
a product decision rather than a mechanical bridge.

# TalentTrack v4.57.0 — Authorization: bridge six act-caps to the matrix + two approved access changes (#1941)

Six legacy `tt_*` act-capabilities now resolve through the authorization
matrix instead of native WordPress capabilities, so the frontend renders
and REST endpoints that gate on each cap can no longer answer differently:
`tt_manage_teams`, `tt_manage_staff_development`, `tt_manage_modules`,
`tt_view_scout_assignments`, `tt_manage_invitations`, and
`tt_rate_player_behaviour`. Four bridges are access-preserving. Two carry
an approved effective-access change: the Head of Development now sees the
all-teams exports picker (`tt_manage_teams` → `team:create_delete`, the
HoD oversees the whole academy), and assistant coaches can no longer author
behaviour ratings (`tt_rate_player_behaviour` → `player_behaviour_ratings:change`;
the matrix treats behaviour-rating as a development judgment, not an
operational one). The stale behaviour-rating grant on the assistant-coach
role is revoked on upgrade so installs whose matrix is still dormant
converge on the same answer. Invitation management stays admin-only
(`tt_manage_invitations` bridges to the admin-level `settings` entity, not
the broad `invitations` entity that coaches and parents hold to send invites).

# TalentTrack v4.57.0 — All-teams lens now resolves from the authorization matrix (#1942)

Replaced the phantom `tt_view_all_teams` / `tt_edit_settings` capability
idiom — which gated the academy-wide ("all teams") lens across reports,
analytics, attendance, the cohort board, the team planner, match-execution
surfaces and the matches-needing-review widget — with a single
`AllTeamsScope` helper that asks the authorization matrix for global-scope
read on each surface's own entity (reports surfaces check `reports`,
analytics / attendance check `activities`, the evaluations audit override
checks `evaluations`). Frontend renders and REST permission callbacks now
resolve the all-teams question from one place, so they can no longer drift.
Head of Development and Academy Admin keep the club-wide view; scouts gain
the club-wide reports and analytics lens where the matrix already grants
them global read.

# TalentTrack v4.57.0 — Authorization: give the Tournaments planner a matrix entity (#1943)

The admin-only Tournament planner now has a `tournaments` authorization-matrix
entity. The legacy `tt_view_tournaments` / `tt_edit_tournaments` capabilities are
bridged through `LegacyCapMapper`, so the planner's frontend, REST, and add-match
surfaces resolve access from the matrix once it is active instead of from raw
WordPress capabilities. The seed grants only the Academy Admin persona full access
(read + edit + create + delete), exactly reproducing today's admin-only v1 design —
no persona gains or loses access, and WP administrators keep their override. A
backfill migration adds the entity to existing installs.

# TalentTrack v4.56.0 — Six new per-academy feature toggles (#1538)

The Modules page gains six more sub-feature switches, so academies can turn off
heavy, cost- or privacy-sensitive behaviour without disabling a whole module. All
default on, so nothing changes until you toggle one:

- **SMS channel** (Comms) — offer SMS as a messaging channel.
- **Scheduled messaging** (Comms) — the daily reminder cron.
- **Medical events on timeline** (Journey) — show medical events to permitted staff; an academy-wide privacy brake when off.
- **PDP calendar integration** (PDP) — write scheduled conversations to the calendar feed.
- **Dashboard layout editor** (Persona Dashboard) — the drag-and-drop layout builder.
- **Match prep PDF export** (Match Prep) — the A4 print / export-to-PDF actions.

(The seventh candidate, the Team planner calendar toggle, already shipped separately.)

# TalentTrack v4.55.0 — Archive lifecycle for activities (#1555)

Activities now follow the same archive lifecycle as players, teams, evaluations
and goals. Deleting an activity soft-archives it instead of removing the row, so
its attendance and history are preserved. The activities list gains an
**Active · Archived · All** status control: the **Archived** view lists archived
activities with a **Restore** button and, for admins, a **Delete permanently**
button. Permanent deletion is gated behind the *edit settings* capability and is
blocked while the activity still has attached records, so nothing is erased by
accident. New REST routes back the flow: `POST /activities/{id}/restore` and
`DELETE /activities/{id}/permanent`.

# TalentTrack v4.54.2 — Team chemistry access now follows the authorization matrix (#1922)

Team chemistry and Team blueprint access is now decided by the
authorization matrix instead of hardcoded role capabilities, with a single
shared decision (`TeamChemistryAccess`) behind both the rendered screens
and the REST API so the two can no longer disagree.

As a result, two roles that previously had access no longer do:
**assistant coaches and read-only observers no longer have access to team
chemistry** (the chemistry board and the team blueprint screens). This
matches the academy roles the matrix already grants the feature to — head
coaches, team managers, scouts, head of development, and academy admins
keep their access unchanged. The stale read capability is removed from the
read-only-observer role automatically on upgrade.

# TalentTrack v4.54.1 — Audit log: Configuration tile now opens the frontend view (#1918)

The **Audit log** tile in Configuration → System no longer bounces into
wp-admin. It now opens the read-only frontend Audit log view
(`?tt_view=audit-log`) — a paginated, filterable browser over the academy's
`tt_audit_log` trail (who changed what, when), with an All-entries tab and a
Failed-logins aggregate. The tile is cap-gated to `tt_view_audit_log`, so it
only appears for holders who can read the log. The wp-admin tab
(`?page=tt-config&tab=audit`) stays as a power-user fallback.

# TalentTrack v4.54.1 — PDP visibility: unify frontend and REST behind one matrix-aware check (#1923)

PDP-file access is now decided in a single place (`PdpAccess`), so the
rendered files tab and every REST surface answer the same question. This
closes the frontend/REST divergence (#1758) where a Head of Development who
does not personally coach a player was denied the files tab even though the
API let them through. The PDP REST endpoints that previously authorised on
"is the user logged in?" now check capabilities via the authorization
matrix, and the verdict sign-off attribution no longer relies on a role-name
string compare. Effective access is unchanged for every persona — this
removes drift and a legacy auth smell without widening or narrowing anyone.

# TalentTrack v4.54.0 — Chemistry rework — admin settings (#1017)

Phase 5 of the chemistry rework (epic #1017): a **Chemistry settings** surface (Configuration → tile) where a head of development or academy admin tunes the reworked engine — the **enable toggle** (`chemistry_engine_v2`, off by default), the **five component weights** (normalised to total 100), and the **Position Relationship Matrix** (how strongly each pair of lines interacts, 0–1). All persist via the Phase-1 contract (`tt_config` + the matrix table). Matrix-gated on `team_chemistry` change at global scope; a Save-only settings sub-form (§6 exemption); mobile-first; nl_NL strings.

# TalentTrack v4.54.0 — Chemistry rework — Unit / Lineup / Team aggregators (#1017)

Phase 4 of the chemistry rework (epic #1017): rolls the reworked pair scores up into the spec's higher-order numbers. `LineupChemistryAggregator` scores every filled-slot pair (all-pairs), weights them by the configurable Position Relationship Matrix, and returns **Lineup chemistry** (matrix-weighted average) + **Unit chemistry** per gk/def/mid/att. `TeamChemistryAggregator` writes a lineup-chemistry snapshot per blueprint save and averages recent snapshots into **Team chemistry** over a window (last 5 / 10 / season). The reworked numbers surface on the blueprint response as `chemistry_v2` (lineup + unit + windowed team + per-pair breakdown) **behind the `chemistry_engine_v2` toggle (default off)** — the legacy `blueprint_chemistry` stays the live signal until an academy opts in once attributes are populated, and any computation error degrades silently to the old behaviour.

# TalentTrack v4.54.0 — Chemistry attributes — player data entry (#1017)

Phase 7 of the chemistry rework (epic #1017, child #1913) — the load-bearing data dependency. Adds a **Chemistry attributes** editor reachable from a player's profile (⋯ menu): the attribute catalogue grouped (physical / technical / tactical / mental / behaviour / development), one 0–100 input per attribute pre-filled with the current value, saved in one nonce-protected POST. Staff who can record evaluations can edit them, matrix-scoped via `canEvaluatePlayer`. With this the reworked engine has real data to score against; un-rated attributes simply don't count (rather than scoring zero). Mobile-first, Save + Cancel, EN + nl docs.

# TalentTrack v4.54.0 — Chemistry rework — explainability panel (#1017)

Phase 6 of the chemistry rework (epic #1017) — and the last phase. Adds a **Chemistry insight** panel to the team-chemistry board (behind the `chemistry_engine_v2` toggle): the reworked Lineup + per-unit (gk/def/mid/att) + windowed Team scores, the **strongest** and **weakest partnerships** in the lineup (colour-coded by category), and plain-language **recommendations** — telling a coach which pairing to strengthen and on which component, or which players still need their attributes rated. `ChemistryExplainer` derives the strongest/weakest/recommendations from the lineup aggregate (each pair now carries its weakest component). Degrades silently if the engine throws or there isn't enough data yet. This completes the rework: define attributes → engine scores → explained on the board.

# TalentTrack v4.54.0 — Chemistry rework — pair engine orchestrator (#1017)

Phase 3 of the chemistry rework (epic #1017): the `PairChemistryEngine` that combines the five Phase-2 sub-engines into a single 0–100 pair-chemistry score using the configurable component weights, plus the `ChemistryProfileLoader` that feeds them real data — each player's attributes + age + footedness, and the pair's shared-history context (shared completed activities/games + team-tenure overlap), pre-loaded once per id set. A `PairResult` carries the score, its spec category (exceptional → poor), the per-component breakdown, and the human reasons. Exposed read-only at `GET /chemistry/pair/{a}/{b}` (gated on viewing both players) so the new engine can be tested on real pairs. It does **not** displace `BlueprintChemistryEngine` yet — the live team surface switches over only once Phase 7 has populated attributes, in Phase 4.

# TalentTrack v4.54.0 — VCT exercise catalogue — starter seed scaffold (#1129)

Ships the idempotent seed-migration scaffold for the VCT exercise catalogue
plus a small representative draft set — 12 exercises, two per category across
warmup, technical, sided_game, conditioning, finishing and cool_down — each
with three coaching points authored in all five shipped locales (canonical
English, Dutch, French, German and Spanish). Intensity bands and age ranges
respect the seeded VCT age profiles. The migration existence-checks
`(club_id, code)` before every insert, so re-running on an already-seeded club
is a no-op, and a later catalogue correction can raise `seed_revision` without
trampling operator edits. This is a clearly-marked draft subset, not the full
80-exercise catalogue: the complete catalogue, per-exercise diagrams and the
pilot-coach methodology review remain pending and are tracked on #1129.

# TalentTrack v4.54.0 — Evaluation-window coverage report for Heads of Development (#1380)

A new HoD analytics surface answers "which players have NOT been
evaluated this window, and which coach owns the gap?". Define the
season's evaluation windows (name + start/end dates) in a settings-style
editor, then read a coverage matrix: players grouped by team across each
window, every cell marked evaluated (with the evaluating coach on hover)
or a clear gap. A header strip tallies gaps per coach, per-coach chips
open the evaluations list filtered to that coach, and an
attendance-recording compliance strip shows, per team, the share of
completed activities in each window that have any attendance recorded —
so a coach who never records attendance looks different from a team with
no activity. Windows are stored in tt_config (no new entity, no
reminders) and the whole report is reachable through the REST API at
`/talenttrack/v1/eval-coverage`.

# TalentTrack v4.54.0 — Season rollover — bulk cohort promotion (#1381)

A new end-of-season tool moves whole squads up an age group in one pass and
writes a dated journey event for every affected player. The flow has three
steps — map each source team to a target team, choose which players move (and
whether each is promoted, released or graduated), then review the exact
changes before confirming.

Safety is built in: a full backup runs automatically before any record is
touched, and if the backup fails the rollover is aborted with nothing
changed. The confirm step posts through admin-post.php and redirects back
(post/redirect/get), so refreshing the result page cannot re-run the move.

Released players are deliberately **left active** — they get a dated
`released` journey event but are not archived, so the data-retention clock
never starts here. There is no season-entity creation or assignment in this
version; the rollover is purely a team move plus a journey event.

This is a bulk operation on existing records, so per the wizard-first rule it
takes wizard **exemption (b)** (bulk operations) and ships as a dedicated
multi-step view rather than a record-creation wizard. The same logic is
reachable over REST at `POST /talenttrack/v1/season-rollover/plan` (dry-run)
and `POST /talenttrack/v1/season-rollover/execute`.

# TalentTrack v4.54.0 — Cohort decision board (read-only) (#1383)

A new **Cohort decision board** under Analytics gives the Head of Development
one read-only screen for end-of-season decisions. Pick a team or age group and
see one row per active player with their status, rolling rating and trend arrow,
season attendance %, conducted-PDP-talk count, and current PDP verdict (or
"Pending"), each linking straight into the player's PDP file. Columns are
sortable (server-side, works without JavaScript) and the board exports to CSV.
Verdicts stay set in the PDP file — this board never edits them. Cap-gated on
the analytics capability; coaches see only their own teams. Backed by a new
`GET /cohort-board` REST endpoint sharing the same domain service.

# TalentTrack v4.54.0 — Configuration: Feature toggles no longer bounce into wp-admin (#1533)

The Configuration page's **Feature toggles** tile no longer sends you into wp-admin — per-module enable/disable already lives on the frontend **Modules** view (`?tt_view=modules`), which is contributed into the Configuration grid. The redundant wp-admin tile is retired, so toggling modules stays on the modern frontend surface. First port of the "wp-admin Configuration surfaces → frontend" tracker (#1533); Translations, Backups, Audit log, Setup wizard and Spond are filed as follow-up children.

# TalentTrack v4.54.0 — Team planner is now a toggleable feature (#1538)

The week-by-week **Team planner** calendar is now a `FeatureRegistry` feature an academy admin can switch off from the Modules page — for academies that work activity-by-activity and don't want the forward-looking planner. It ships **on by default**, so nothing changes on upgrade; turning it off hides the Team planner tile and gates its `?tt_view=team-planner` route (the Activities log, the backward-looking surface, stays available). First catalogued entry from the FeatureRegistry candidate tracker (#1538), wired with the standard pattern: a `FeatureRegistry::catalog()` entry plus the tile's `feature` key (route gating is automatic via the feature's `view_slugs`).

# TalentTrack v4.54.0 — Evaluation rating: find players faster on a big roster (#1642)

The **Rate players** step of the new-evaluation wizard gains a **search box** (filter the roster by name as you type) and an **Only not-yet-rated** toggle (hide everyone already rated or skipped, so you see who's left at a glance). The toggle reads the same live per-player status as the existing *"N of M players rated"* progress line, so a player drops out of the not-yet-rated view the moment you rate them. Both are instant on-device filters and never change what gets submitted — directly addressing the "players are hard to find / which still need rating" pain in #1642. (The rating control itself was already rebuilt as a 5-star input in #1641, and behaviour is already an optional collapsed step, so this slice focuses on findability; collapsing the activity-picker + attendance steps stays a separate, riskier change since attendance writes real rows.)

# TalentTrack v4.54.0 — Trial pages overhaul — redesigned case page, warmer Dutch letters, friendlier configuration (#1646)

The trial case page has been rebuilt to match the player and team profiles: a paper hero anchored by the player's photo and name, status / decision / track pills, a key-facts strip, and the content laid out in cards under tab navigation (Overview · Execution · Staff inputs, plus Decision · Letter · Parent meeting for the head of development). The old anchor-strip layout and its inline styling are gone; all styling now lives in the enqueued, mobile-first stylesheet. The post-decision summary now shows the decision's readable label instead of the raw internal code.

The shipped Dutch parent letters (admittance, decline-final, decline-with-encouragement) have been rewritten in a warm, informal "je/jullie" club voice, and a set of broken pronoun placeholders that previously printed literally in both the English and Dutch letters has been removed.

The trial tracks and letter-template configuration screens now open with plain-language guidance, label each letter by what it's for instead of an internal key, and carry per-field hints. Missing Dutch translations across the trial surfaces have been filled in so the pages read fully in Dutch.

# TalentTrack v4.54.0 — Match-day live surface: vertical positional pitch + chronological event log (#1713)

The live match-execution screen now opens with a vertical pitch showing
the first-half starting eleven laid out by position, sourced from the
match-prep line-up and the bound formation shape. Below it a new "Live
progress" feed merges the goals and substitutions already logged during
the match into one time-ordered list — each row carries the half +
minute, a type chip (icon and text, not colour alone), and a running
score chip on goals. Both surfaces are also exposed as read endpoints
(`GET /match-execution/{activity_id}/event-feed` and `/pitch-lineup`)
behind the existing `tt_edit_activities` capability.

Scope notes: the Teamchemie badge from the mockup is deferred — no
chemistry metric exists yet and the algorithm is under review (#1017).
Red and yellow cards are not modelled, so the feed is goals +
substitutions only; no schema change was added.

# TalentTrack v4.54.0 — Direct entry of per-player match minutes on match completion (#1726)

You can now log per-player match minutes without running the live match
surface. When a match-type activity is marked Completed, the attendance table
gains Starter and Minutes columns, and a Match length field appears above it
(prefilled from the match prep's two halves, or 70 minutes, and editable). The
form derives a "Subs: N on · N off" summary from the starter flags and minutes.
The minutes are written to the same place the live flow uses, so the minutes
report and the match-execution view pick them up — including for past matches
that were never live-tracked.

# TalentTrack v4.54.0 — Central per-age-category default match minutes (#1727)

You can now set a default match length per age category — minutes per half (N),
with the full match shown as 2 x N — under Configuration -> Match minutes. One
row per age group, blank inherits a global fallback of 35 minutes per half.
That central setting is now the single source of truth for match length:
new match prep and the match-completion minutes entry both prefill from the
team's age category instead of the old hardcoded 35-per-half / 70 default
(still editable per match). Accurate minutes feed each player's load and
development picture.

# TalentTrack v4.54.0 — Bulk-invite a team's players (#1770)

The **Player accounts** view gains a **Bulk invite a team** action: pick a team and generate a player invitation for every player on it who doesn't already have an account or a pending invite, in one click. The result is summarised (new invites vs. already-pending), and the daily invite limit is handled gracefully — if a large team hits the cap, the summary reports how many went out so the rest can be invited the next day. This is the deferred bulk-provisioning piece of the player↔account mapping epic; single link/unlink and per-player invites are unchanged.

# TalentTrack v4.54.0 — Dashboard tile badges for pending actions (#1846)

Dashboard navigation tiles can now carry a small **count badge** (top-right bubble) for pending actions, via a generic `badge_callback` on the tile. The **My tasks** tile uses it to show your open-task count at a glance — replacing the old `My tasks (3)` label suffix with a proper badge, so the tile label stays clean and the count reads instantly. Phase 6 of the player + parent development hub epic.

# TalentTrack v4.54.0 — Admin can create a new parent/player account directly (#1847)

The **Parent accounts** view gains a *Create a new parent account* panel: an academy admin provisions a brand-new WP account (name + email), links it to the chosen player, and the person receives a standard **"set your password"** email — the admin never sees or sets a password. For the rare no-usable-email case, a *No usable email* toggle sets a temporary password instead (share it securely). Every direct-create is audit-logged. The same `directCreate` path exists on both `ParentAccountService` and `PlayerAccountService` and is reachable over REST (`POST /players/{id}/parents` / `…/account` with `create:true`), so a future front end gets the same behaviour (§4). Inviting remains the low-friction default; direct-create is the admin-convenience path. Follow-up to the Accounts & access epic (#1815, #1770). The player-accounts-view create UI is a fast-follow — its service + REST ship here.

# TalentTrack v4.54.0 — Parents can open their child's own development views (#1849)

A parent can now open their child's **own** development surfaces — development plan, goals, card, evaluations, activities, journey — by tapping the child in the parent dashboard's child-switcher. These are the **rich player views** (the same `FrontendMy*` surfaces the player sees, e.g. the full PDP conversation cycle), not the thinner staff-profile tabs parents were previously bounced to. Access is scoped (a parent only reaches their own children, via the same per-player gate as #1725), and the development-plan view greets a parent with the child's name ("<Child>'s development plan"). Foundation for the unified development hub (epic #1846).

# TalentTrack v4.54.0 — Player + parent development home: one anchor for the My-X views (#1850)

Players (and parents, scoped to their child) get a new **My development** home — a single, scannable, mobile-first page that composes the existing rich My-X surfaces into one overview-led anchor. It opens with the player hero, then a **Today** band driven by the PDP cycle state (prepare for an upcoming talk, review a just-held talk, or the next-talk date — degrading gracefully when there's no PDP data), followed by **Your focus** (top goals), **How you're doing** (rating + momentum), **Coming up** (next activities) and **Your journey** (latest milestone). Each block links through to its deep view, carrying a back hint so the deep view shows a "← Back to …" pill. A prominent **My development** tile leads the Me group; the seven existing deep-view tiles stay as shortcuts. Parents open "&lt;Child&gt;'s development", read-only. Phase 2 of the development-hub epic (#1846).

# TalentTrack v4.54.0 — State-aware My PDP: lead with goals, flip to self-review in the window (#1851)

*My PDP* now opens with a short lead block that orients the player on **what to do now**, derived from where they are in the development-talk cycle. In a **working period** it leads with the player's focus goals and the next-talk date; in the **review window** it surfaces "prepare for your talk" and promotes the upcoming conversation so the self-reflection editor and agenda are front-and-centre; **after a talk** it points at the notes, agreed actions and acknowledgement to complete. The self-review stays optional and is never a gate — every conversation card, the reflection editor and the ack flow are unchanged, only re-ordered and highlighted by state. Parents see the same state surface for their child, read-only. State is derived by a small reusable `PdpCycleState` service from the already-seeded conversations and planning windows (migration 0043); no schedule or window data changes. Phase 3 of the development-hub epic (#1846).

# TalentTrack v4.54.0 — Self-review nudge when a PDP talk's window opens (#1852)

When a development talk's planning window opens, the player now gets a **"Prepare for your development talk"** task in *My tasks / Today's work*, due on the talk date, that opens *My PDP* at the self-reflection. It's a nudge, not a gate: saving the reflection completes it, conducting the talk auto-resolves it with no penalty even if it was skipped, and nothing is ever blocked if it's ignored. The sweep that creates these runs on the workflow engine's own scheduler (no ad-hoc cron) and is idempotent — exactly one task per conversation. On the coach side, the PDP conversation list gains a **Self-review: Done / Not yet** column per upcoming talk — visibility only, never a gate on conducting or signing off. Phase 4 of the development-hub epic (#1846).

# TalentTrack v4.54.0 — Link goals to a PDP conversation — the "combine" (#1853)

Goals and the PDP cycle are now genuinely linked, not just co-located. On the development-talk form, a coach ticks **Goals discussed in this talk** from the player's active goals; on *My PDP*, each conversation card shows a **Goals discussed** list so the player's self-review reflects on the goals that were actually covered. Built on the existing `tt_goal_links` table (a new `pdp_conversation` link type — no schema migration; the methodology-link sync is scoped so it can't clobber the conversation links), with repository methods + REST handling on the conversation PATCH (coach-only, and the goal set is validated to belong to the player). Phase 5 of the development-hub epic (#1846); supersedes the POP linkage in #1717. Turning an agreed action into a brand-new goal is a planned follow-up — this slice is the read/link connective tissue.

# TalentTrack v4.54.0 — Measurements & Testing — staff result entry (#1856)

Adds the staff-facing **Record measurements** surface for the Measurements module (epic #1854). A coach picks a team, a test, and a date, then enters one value per player and saves the whole roster in one shot — saving creates a completed testing session and one result per filled-in player against it (blank rows are skipped). The input adapts to the test's value type (numeric/scale → a numeric keypad with the unit shown; pass/fail → a dropdown). Matrix-gated on `measurements` change (a coach only reaches their own teams; head-of-development / admin see all); bulk entry is a wizard exemption under §3(b). Mobile-first, Save + Cancel, server-rendered (nonce-protected POST, no extra client JS). The "+ New test" wizard for creating the tests themselves follows.

# TalentTrack v4.54.0 — Measurements & Testing — foundation (#1856)

Stands up the data foundation for the new **Measurements & Testing** module (epic #1854): an academy can model tests (e.g. height, sprint, endurance) in editable categories with proper units of measure, a recurrence, and per-age-group target bands; schedule team testing sessions; and record one value per player. This slice ships the schema (migration 0175 — four tables, each with the `club_id` + `uuid` tenancy scaffold and an archive lifecycle), the admin-editable `measurement_category` and `measurement_unit` lookups (with Dutch labels), the repositories, and the authorization + referential-integrity-delete wiring. Visibility is matrix-scoped: a player sees only their own results, a parent only their child's, staff their team's, and head-of-development / academy admin everything. The setup wizard, result-entry screens, and the per-player trend view land in the following slices.

# TalentTrack v4.54.0 — Measurements & Testing — REST contract (#1856)

Adds the SaaS-ready REST contract for the Measurements module (epic #1854) at `talenttrack/v1`: a player's measurement profile (`GET /players/{id}/measurements` — categories → tests → latest value + green/amber/red flag + trend), result recording + editing + soft-archive, one test's trend series, the test catalogue (`/measurements/definitions`), and team testing sessions. Every endpoint is matrix-gated — player reads resolve through `canViewPlayer` (a player sees only their own, a parent only their child's, staff their team's, HoD/admin everything), writes through `canEvaluatePlayer`, and the catalogue/sessions through the `measurement_definitions` / `measurement_sessions` matrix entities — never a role-string compare. The grouping + flag logic lives in a shared `PlayerMeasurementProfile` service so the upcoming frontend renders exactly what the API returns. The frontend Metingen view, the result-entry screen, and the "+ New test" wizard follow in the next slice.

# TalentTrack v4.54.0 — Measurements & Testing — player Metingen view (#1856)

Adds the player-facing **Metingen** surface for the Measurements module (epic #1854). A player (and a parent of that player) gets a "My measurements" tile that opens a view of their tests grouped by category — each test showing its latest value, a green/amber/red flag against the age-group target, a sparkline of the trend, and the recurrence. The view is server-rendered straight from the shared `PlayerMeasurementProfile` service, so it shows exactly what the REST API returns; the sparkline is inline SVG (no extra client JS). Visibility is matrix-scoped: a player sees only their own, a parent only their child's; staff reach a player's measurements from the player profile, so the self-dashboard tile is hidden for them. Mobile-first, two nav affordances. The result-entry screen and the "+ New test" wizard follow in the next slice.

# TalentTrack v4.54.0 — Measurements & Testing — "+ New test" wizard (#1856)

Closes the Measurements epic (#1854) with the wizard-first create flow for a test definition (CLAUDE.md §3). A head of development or academy admin runs **+ New test**: pick a category and name and value type, choose a unit (from the unit list or a custom one) plus the direction and recurrence, and optionally set per-age-group green/amber target bands — then finish to create the test and its targets in one go. Registered in `WizardRegistry` (slug `measurement`, reachable from the **Record measurements** screen's "+ New test" button and `?tt_view=wizard&tt_wizard=measurement`); the standard wizard chrome supplies the Previous/Next/Cancel + progress rail. With this, the full loop is in the UI: define a test → record results for a team → players and parents see their trend.

# TalentTrack v4.54.0 — Data Browser — read-only frontend table browser (#1859)

A new **Data Browser** tile (under Administration, for administrators and Club Admins only) lets you browse the raw data behind TalentTrack, read-only. Each `tt_*` table is listed with a friendly label, description and row count; opening one shows semantic column headers with explanations, the actual stored rows (paginated and searchable), the tables it connects to, and clickable foreign keys that jump to the referenced row. Core player-centric tables get hand-written labels; the rest fall back to humanised names. Tables holding sensitive data about minors (medical, safeguarding, family) are badged, and opening one is recorded in the audit log. The same data is exposed read-only over the REST API at `/talenttrack/v1/data-browser`.

# TalentTrack v4.54.0 — Goal/season intake print no longer leaks archived evaluations (#1860)

The goal/season intake printout pulled a player's evaluation data — the
average rating and the strong/weak category breakdown — without excluding
archived evaluations, so the print could show ratings the player's own
evaluation page hides. All three intake-print evaluation reads now apply the
same `archived_at IS NULL` filter the evaluation page uses, so the printout
matches what's on screen.

# TalentTrack v4.54.0 — Match "type of match" now shows translated labels on the activity form (#1861)

The game-subtype dropdown (Friendly / League / Cup) on the frontend activity
manage form rendered the stored English labels even on a Dutch install,
because it read the lookup names without their translations. It now pulls the
full lookup rows and renders the translated label — matching the admin form
and the activity wizard. The stored value is unchanged.

# TalentTrack v4.54.0 — Cancelled activities hidden from the list by default (#1862)

Cancelled activities no longer clutter the activities list — they're hidden by
default so the schedule reads as what's actually happening. A new "Show
cancelled" filter brings them back when you need the audit trail; shown that
way they're dimmed and struck through with a Cancelled pill, in whichever date
bucket they fall. The default-hide is applied in the query (it carries through
the URL), so a shared link reflects the same view.

# TalentTrack v4.54.0 — Match end time defaults to kick-off + 105 minutes (#1863)

When you set the kick-off time on a match activity, the end time is now
prefilled to 105 minutes later (90' play + 15' half-time). It only applies to
match activities, fills in just once, never overwrites an end time you typed
yourself, and stays fully editable. Works on both the activity wizard and the
flat activity form.

# TalentTrack v4.54.0 — Match execution shows each player's logged minutes (#1864)

The match-execution screen now shows a per-player minutes chip once a match
has been ended, reading the same persisted minutes the minutes report uses, so
the two always agree. Before the match is ended there are no minutes yet and no
chip is shown. Tracked players and bench players who came on both display their
logged minutes.

# TalentTrack v4.54.0 — PDP planning is now team-scoped for coaches (#1865)

The PDP planning matrix used to show every team in the academy to anyone with
the PDP edit capability, so a team-scoped coach saw the same all-teams grid as
a head of development. It's now matrix-scoped: a HoD or administrator still sees
every team, while a coach sees only the teams they're assigned to — in the
matrix and when drilling into a block. Opening another team's block via a
hand-edited URL is refused.

# TalentTrack v4.54.0 — Branded password reset flow (#1866)

Resetting a forgotten password now stays on the academy's own branded screens
instead of dropping you onto the plain WordPress reset pages. "Lost your
password?" opens a branded request form; the emailed link lands on a branded
"Choose a new password" screen; and you're returned to the sign-in card with a
confirmation. The request step always shows the same "if that account exists,
we've sent a link" message so it can't be used to discover which emails have
accounts, and the link generation, expiry, and password storage stay on
WordPress core's secure mechanics.

# TalentTrack v4.54.0 — Players can choose which sections their parent sees (#1867)

A player (child) can now control **which sections of their record a linked parent can see** — per section, default visible. In **My settings**, a player with a linked parent gets a "What your parent can see" card with toggles for **Evaluations**, **Goals**, **Journey**, **Measurements** and **Development plan**; everything is shared by default, and turning a section off hides it from the parent across both the rendered views and the REST reads. The parent sees a calm "kept private" note rather than an error or a broken view, and the development-home previews respect the same choice. The player always sees their own record, coaches and the academy are unaffected, and safeguarding/medical stays cap-gated and outside player control. Enforced in the authorization layer (`AuthorizationService::parentCanViewSection`), not in views (§4); new `tt_player_parent_visibility` table carries `club_id`. Part of the development-hub epic (#1846) and the player/parent dignity work (CLAUDE.md §1).

# TalentTrack v4.54.0 — Match-prep print/PDF now mirrors the on-screen view (#1873)

The match-prep printout and PDF export now include everything below the
toolbar on the match-prep screen, not a reduced subset: the two formation
pitches, the **Selection · minutes** table (per-half minutes + totals), the
benches, the match goals, **Doen per speler**, and **Roles & set pieces**. The
minutes table and the roles panel were the two pieces previously missing, so a
coach printing for the dugout gets the document they laid out. The summary
tiles and the toolbar itself stay out of the printout.

# TalentTrack v4.54.0 — Team season-intake print: clean one-page-per-sheet pagination (#1875)

Printing the season-intake for a whole team produced sheets that cascaded and
overlapped — each player's pages drifted onto trailing blank pages instead of
breaking cleanly. The print stylesheet pinned each sheet to a `min-height` of a
full A4, which rounds past the printable height on some renderers and bleeds
every sheet onto the next page. Each sheet now uses an exact A4 box with
clipped overflow and an explicit page break, so a batch of N players prints
exactly 3N clean pages.

# TalentTrack v4.54.0 — Measurements insights: testing coverage — who's due / overdue (#1882)

Staff get a new **Testing coverage** screen (Performance group): pick a team and see, for every test that has a recurrence, how many of the squad are up to date versus the gap — with the players who are **overdue**, **due soon**, or have **never** been tested named so a coach can plan a session. Player-centric: it starts from the roster and surfaces exactly who still needs testing this cycle; *ad hoc* tests don't count toward coverage. Built on the #1856 foundation — a pure `MeasurementScheduleService` (frequency → due/overdue) + a `MeasurementCoverageService` composing the existing definitions/results repositories, exposed over REST (`GET /teams/{id}/measurement-coverage`, team/global matrix-scoped) so logic stays out of the view (§4). Coach sees their own teams; HoD/admin see every team. First slice of the Measurements insights work (#1854); per-definition distribution + growth/maturation curves and overdue reminders are the next increment.

# TalentTrack v4.54.0 — Measurements on the player profile (#1892)

A player's measurements now appear in context on their profile: opening a player (`?tt_view=players&id=N`) shows a **Measurements** tab beside Evaluations — the same tests-by-category view with latest value, green/amber/red flag and trend sparkline, with a badge counting how many tests the player has results for. The tab reuses the shared `PlayerMeasurementProfile` service so it renders identically to the standalone Metingen view, and is matrix-scoped (hidden for personas without `measurements` read).

# TalentTrack v4.54.0 — Evaluation wizard: one-tap "Everyone was here" on the attendance step (#1899)

The attendance step of the new-evaluation wizard gains a prominent **"Everyone was here - continue"** button at the top: for the common case where the whole squad was present, it marks the roster present and advances straight to rating in a single tap, instead of the coach scanning the roster and hitting Next. Mark any absences on the cards first if needed, then use it (or the normal Next). Attendance is still written exactly as before (real `tt_attendance` rows, present-by-default), and the standalone mark-attendance entry point is unchanged — this only adds a faster path through the existing screen. Follow-up to the evaluation-capture UX work (#1642); the deeper picker/attendance step-merge was deliberately scoped to this low-risk shortcut.

# TalentTrack v4.54.0 — My activities: 2026 chrome restyle (#1901)

The player/parent **My activities** surface now matches the 2026 look of the other Tier-2 surfaces. The **activity detail** gets the white-card chrome (card wrapper, branded meta chips + status badges, tokenised spacing) and the list's **mobile cards** are elevated to the same white-card style — scoped to this view via a `.tt-myact-list` wrapper, so the shared list component is untouched everywhere else. Presentation only; no data or behaviour change. Completes the Tier-2 visual-parity track of the go-live-readiness epic (#1723 / #1695) — all six player/parent surfaces are now on the 2026 chrome.

# TalentTrack v4.54.0 — Invitations are now emailed automatically (#1902)

When an admin creates a parent/player invitation **with an email address**, the accept link is now **emailed to the invitee automatically** — previously invitations were link-only (copy / WhatsApp share), so an admin had to hand-carry every link. The email goes out through the existing Comms module (audit-logged, in the invitee's locale, with a "set your password" call to action and the link's expiry). It's transactional — it bypasses opt-out / quiet-hours / rate-limits so an invitee is never withheld their invite — and silently no-ops when the invite has no usable email (the copy-link / WhatsApp share path still stands). New `InvitationEmailTemplate` (registered in `CommsModule`) + an `InvitationEmailNotifier` that listens on `tt_invitation_created` and dispatches via `tt_comms_dispatch`. Closes the biggest self-serve onboarding gap for the player/parent go-live (epic #1723).

# TalentTrack v4.54.0 — First-login welcome card on the development home (#1903)

A new player (or parent) opening the **development home** for the first time now sees a short, friendly **welcome card** at the top — persona-aware ("this is your development home" for a player, "this is &lt;Child&gt;'s development home — you choose what they share with you" for a parent). It's informational only; tap **Got it** to dismiss it and it won't come back (stored per viewer in user meta — no schema change). Closes the "new player/parent lands on a cold dashboard" gap from the go-live-readiness epic (#1723).

# TalentTrack v4.54.0 — Invitation accept-form polish: recovery-email hint + silent-link relationship (#1904)

Two onboarding-correctness tweaks on the invitation accept flow. The **recovery email** field now carries a short note that it's pre-filled from the invitation and only used for password recovery (and can be changed), so an invitee doesn't enter a wrong or shared address by mistake. And the **silent-link** path (a logged-in parent whose email matches) now asks for the **relationship** (parent / mother / father / guardian) just like the full form — previously it linked silently with an assumed role, so a grandparent or carer could be recorded incorrectly. The relationship is threaded through `silentLink()` into the existing linking step. Part of the go-live-readiness epic (#1723).

# TalentTrack v4.54.0 — Chemistry rework — schema foundation (#1912)

Phase 1 of the chemistry-engine rework (epic #1017): the data layer the pilot-locked spec needs, **with no engine change** — `BlueprintChemistryEngine` keeps working while later phases build on top. Adds a normalised player-attribute model — a seedable, extensible catalogue (`tt_player_attribute_defs`, 23 attributes across physical/technical/tactical/mental/behaviour/development, with Dutch labels) plus per-player values (`tt_player_attribute_values`) — the configurable Position Relationship Matrix (`tt_chemistry_position_matrix`, seeded with sensible defaults), and a lineup-chemistry time-series table (`tt_team_chemistry_snapshots`). The five component weights live in `tt_config`. Repositories and a matrix-gated REST contract (`/players/{id}/attributes`, `/chemistry/position-matrix`, `/chemistry/config`) ship so Phase 2 (sub-engines) and Phase 7 (data entry) can build against it. Every new table carries the `club_id` + `uuid` tenancy scaffold; the attribute catalogue is archive/cascade-wired.

# TalentTrack v4.54.0 — Chemistry rework — five component sub-engines (#1017)

Phase 2 of the chemistry rework (epic #1017): the five weighted component scorers the new pair-chemistry formula is built from, as standalone, independently-reviewable classes — **Compatibility** (core attribute groups + footedness), **Familiarity** (shared training + tenure), **Development** (age + potential alignment), **Behaviour** (behaviour group, team-orientation weighted), **Performance** (shared games). Each takes two player profiles + their shared-history context and returns a 0–100 score with human reasons for the explainability panel, falling back to a neutral 50 (flagged `has_data: false`) when its inputs aren't recorded yet — so an un-populated player never drags a lineup to zero. The locked spec fixes which attribute groups feed each component and the top-level weights; the internal formulas here are a documented v1, tunable per scorer. No engine integration yet (Phase 3 orchestrates them); `BlueprintChemistryEngine` is untouched.

# TalentTrack v4.53.0 — Tidy the trials list and trial-case detail page (#1646)

The trials list now uses the standard 2026 table header (dropped the legacy
sortable widget that showed broken sort glyphs). On the trial-case detail
page the in-card Assign / Extend buttons are styled as primary buttons, the
header action row wraps instead of clipping its last button off the edge, and
the duplicate in-body Archive button is gone — archiving now happens from the
single top-right action. The case execution tab's activity/evaluation/goal
queries are bounded to avoid a slow-query timeout.

# TalentTrack v4.53.0 — POP goals: per-goal progress % + evaluation evidence (#1717)

Fills in the two POP-card slots the restyle reserved but never rendered.

- **Per-goal progress %** — `tt_goals` gains a `progress_pct` (0–100) field a
  coach sets on the goal form; the POP card now shows the progress bar.
- **Evidence (Bewijslast)** — a new `tt_goal_evidence` table links specific
  evaluations to a goal. The goal form gets an evidence picker (tick the
  player's evaluations); each linked evaluation renders on the POP card as a
  scored chip — *Assessment 12 Mar · 6.5* — from its date + overall
  (average-rating) score. Stored separately from the methodology links.

Migration 0173 (additive). With #1754's collapsible cards + per-goal
conversation, the POP page now matches the deck mockup.

# TalentTrack v4.53.0 — The Accounts & access tile now shows on the admin dashboard (#1815)

Fixes the Accounts & access hub being unreachable from the dashboard: the
tile is now registered so it renders for the Academy Admin (and Head of
Development) dashboards, alongside Configuration and Invitations. The hub
groups Player accounts, Parent accounts, and Invitations.

# TalentTrack v4.52.0 — POP page: collapsible goals with a conversation per goal (#1754)

The player's POP page now renders its learning goals as **collapsible
cards** (native `<details>`, keyboard-accessible). Each card header shows the
goal title, status, due window, and a 💬 count of that goal's messages.

Expanding a goal reveals two columns: the goal's detail (description, linked
methodology, evidence) on the left and **that goal's own conversation thread
on the right** — every goal has a separate thread, so discussions don't mix.
In-progress goals open by default. Reuses the existing per-goal threads
(`thread_type='goal'`), and makes `FrontendThreadView` multi-instance-safe so
several conversations can live on one page.

Per-goal **progress %** and scored **evidence (Bewijslast)** shown in the deck
mockup are a follow-up — they need the evaluation-evidence schema in #1717.

# TalentTrack v4.52.0 — Accounts & access hub (#1815)

A new "Accounts & access" tile on the dashboard opens a hub that groups the
account-management surfaces in one place: Player accounts, Parent accounts,
and Invitations. Each card is permission-gated and links straight to its
screen. The standalone Player accounts tile is folded into the hub.

# TalentTrack v4.52.0 — Fix Unknown-column errors on the trials list and reports (#1840)

Adds a forward migration that restores the `opened_by` and `overall_rating`
columns on `tt_trial_cases`. Installs that ran the original trial-module
migration before these columns existed were missing them, causing
"Unknown column" database errors on the trials list and the trial reports
(and a blank, unstyled trials page when the failed query halted rendering).
The migration is idempotent and backfills `opened_by` from `created_by`.

# TalentTrack v4.51.1 — Parent accounts admin surface (#1815)

A new Parent accounts screen (Dashboard → Parent accounts) lets academy
admins manage guardian logins: link an existing WordPress account to a
player as a parent, see one row per parent with the players they guard, and
unlink a parent from a player in one click. Gated by the dedicated
parent-account-management permission. Inviting a parent stays available from
a player's Family tab.

# TalentTrack v4.51.0 — Restyle 14 remaining frontend surfaces to the 2026 look (#1695)

Brings the last batch of frontend view bodies onto the 2026 design system:
teammate, my-evaluations (coach view), VCT session, team chemistry,
match-executions list, team blueprints, minutes report, the data explorer,
cohort transitions, the report wizard, and the admin roles / seasons /
migrations / VCT library screens. Inline styles moved into enqueued
mobile-first stylesheets, legacy `widefat` tables replaced with the card +
`.tt-table` pattern, and raw colours swapped for design tokens. No behaviour,
data, or permission changes.

# TalentTrack v4.51.0 — Foundation for parent-account management (#1815)

Groundwork for the upcoming Parent accounts admin surface: a dedicated
`tt_manage_parent_accounts` capability (granted to administrators, Club
Admins and Heads of Development, tunable per-persona via the authorization
matrix), a `ParentAccountService` for listing parents and linking/unlinking
a parent WordPress account on a player, and REST endpoints
(`POST`/`DELETE /players/{id}/parents`). No user-facing screen yet — that
arrives with the Parent accounts view.

# TalentTrack v4.51.0 — Player/parent dashboard no longer shows the "Features" tile or a Setup section (#1836)

Follow-up to #1821. The read-only "Features" (NL "Functies") tile — which lists which parts of TalentTrack are switched on — was registered visible to every persona with no capability or matrix entity, so it appeared for players and parents as the lone tile in a "Setup & administration" section. It's now hidden from the player and parent personas, so that section no longer appears on their dashboard. (The functional-roles tile's gating from #1821 is reverted, as the active authorization matrix already gates it on its entity.)

# TalentTrack v4.51.0 — Reachable "Delete permanently" on detail/editor pages (#1784 follow-up)

The referential-integrity permanent delete now has a UI control on the
bespoke (non-list) management surfaces, not just the list views. Adds a
**Delete permanently** button to the trial-case detail page, the trial-track
editor, and each archived row in the VCT exercise library. All three reuse
the shared archive-button handler, so a blocked delete shows the same
"still referenced by …" reason on screen. Admin-gated (`tt_edit_settings`;
VCT: `tt_vct_admin_library`); built-in trial tracks stay non-deletable.

Surfaces without a management page of their own — test trainings
(create-only), custom widgets (no front-end view) and injuries (read-only
on the player timeline) — keep their delete at the REST/admin layer; a
dedicated UI for those is out of scope here.

# TalentTrack v4.50.2 — Scouting pipeline: every card opens the prospect, even with no next action (#1763)

In the onboarding pipeline, a prospect card with no pending task (and not yet promoted) used to render as a dead, unclickable tile. Now every card is clickable: when there's no "next action" it focuses the prospect on the board — `?tt_view=onboarding-pipeline&prospect_id=N` opens a panel showing who they are, their stage, and a link to their next action when one exists. This also fixes the previously no-op `prospect_id` links from the dashboards and scouting-visit detail, which now land on a real focus.

# TalentTrack v4.50.1 — Blueprint editor: a bad assignment ref no longer breaks formation + slot picking (#1619)

On an editable (draft) blueprint, the formation dropdown and slot player-picker could both be dead even though the user had the cap and the blueprint wasn't locked. Cause: an exception during the editor's setup (e.g. a malformed assignment ref) aborted the script before its wiring ran, leaving the server-rendered pitch visible but inert. The editor now runs each setup/wiring step in isolation, so one bad ref can't cascade and disable the rest — and any offender is logged to the console for diagnosis. (Defensive hardening; if a specific payload still triggers it, the console now points at the exact step.)

# TalentTrack v4.50.1 — Player dashboard: own work as tiles, no setup/functions tile (#1821)

The Speler (player) dashboard now renders the player's work (My journey, My card, My team, My evaluations, My activities, My goals, My POP) as tiles under "Today's work" instead of a separate right-hand rail. The "Functional roles" setup tile is also gated correctly: it now requires the manage capability (`tt_manage_functional_roles`), so it no longer leaks into a player's "Setup" section via the loose view-people fallback. Other personas are unchanged, and the persona switcher is respected.

# TalentTrack v4.50.0 — Finalize the safe-delete rollout — archive columns, holiday lifecycle UI + scheduled reports (#1784, #1808)

Completes the referential-integrity delete epic (#1782).

- **Migration 0172** gives every archivable entity the uniform
  `archived_at` + `archived_by` columns: adds the missing `archived_by` to
  trial tracks, test trainings, holidays, player injuries, custom widgets
  and VCT exercises, and adds both columns to scheduled reports (backfilling
  `archived_at` from the legacy `status='archived'`).
- **Scheduled reports** join the framework: an Active/Paused schedule can be
  archived, and an archived one can now be **permanently deleted** from the
  management screen (fail-closed, `tt_edit_settings`).
- **Holidays** gain the full archive lifecycle in their list — an
  Active / Archived tab with Restore and Delete-permanently actions on
  archived rows (matching the tournaments list).

With this, every record type that has an archive lifecycle has a
fail-closed, referential-integrity-checked permanent delete. Team and
activity remain block-only by design (their full player-touching cascades
wait on the PHPUnit floor, #1388).

# TalentTrack v4.50.0 — My Journey event labels no longer leak English (#1818)

The player journey timeline now shows event-type labels (Position changed,
Trial ended, Injury started, …) and the filter chips in the active
language. On Dutch installs they render in Dutch instead of English: the
view resolves each label through the lookup translator, and a migration
seeds the Dutch journey labels into the translation store.

# TalentTrack v4.49.1 — Players complete their profile when accepting an invite (#1819)

The player invitation-acceptance page now collects first name, last name,
date of birth, and preferred foot (alongside the existing jersey number),
written straight to the player record on accept. First and last name are
pre-filled from the invite so the player just confirms or corrects them.

# TalentTrack v4.49.1 — Players can't change their account display name (#1820)

Following the title-case "First Last" default, the display-name field on a
player's My settings page is now read-only — a player's name is owned by
the academy and set from their player record, so it can't be edited there
(enforced server-side as well).

# TalentTrack v4.49.1 — Player accounts: click a linked player to see which WP account it's linked to (#1823)

On the Player accounts page, a linked player's green chip is now a click-to-reveal disclosure: tapping it shows the actual WordPress account behind the link — email, username, and WP user id — so you can tell two accounts apart even when they share a display name. Read-only, inline, no wp-admin needed.

# TalentTrack v4.49.1 — Player accounts: compact rows for not-yet-connected players (#1824)

Rows for players without an account were much taller than connected rows because the link controls wrapped onto several lines. On tablet/desktop the account dropdown + Link + Invite buttons now sit on a single line, so an unconnected row is no taller than a connected one. Also fixes the "WordPress user to link" screen-reader label leaking visible under canvas mode (it relied on the theme's screen-reader-text class, which canvas isolation strips) by giving the plugin its own SR-only utility.

# TalentTrack v4.49.0 — Safe permanent delete for VCT exercises, custom widgets + injuries (#1784)

Extends the referential-integrity delete framework (#1783) to the last of
the rollout entities, plus a framework enhancement: cascade plans can now
**table-qualify** a reference column, so an ambiguous column name (e.g.
`exercise_id`, which keys both `tt_exercises` and the VCT tables) is scanned
on the right tables only.

- **VCT exercise** — cascades its coaching points; clears the exercise link
  on any session block. New `/vct/exercises/{id}/permanent` route.
- **Custom widget** — standalone; removed directly. New
  `/custom-widgets/{id}/permanent` route (uuid- or id-keyed).
- **Injury** — removes the injury and its journey-timeline events (a minor's
  medical record), so a right-to-erasure delete actually erases. New
  `/player-injuries/{id}/permanent` route.

All fail-closed, gated by `tt_edit_settings` (VCT: `can_admin`). No
migration. The `archived_by`-column migration + list-view delete
affordances for the full archive-lifecycle UI remain on #1784.

# TalentTrack v4.49.0 — Configurable dashboard tile colour scheme (#1809)

A new academy-wide **Tile colour scheme** setting recolours the dashboard tiles without changing their size or layout. Six schemes are available — Default, Brand border, Gold-topped (the new default), Soft green fill, Solid green and Left accent — and they draw entirely from the academy's brand colours, so they track your Primary/Secondary colour choices automatically. The setting sits alongside Tile size and Tile layout on the Appearance configuration surface and is stored under the `tile_style` configuration key.

# TalentTrack v4.49.0 — Team planner export buttons are now compact icon buttons (#1812)

The team planner's Export PDF / Export XLSX / Weekly PDF actions render as
icon buttons matching the height of the "Schedule activity" button, instead
of taller text buttons. On phones they collapse to icon-only circles like
the other page-header actions; each keeps an accessible label.

# TalentTrack v4.49.0 — My Journey: position changes read as a list, not raw JSON (#1818)

A "position changed" entry on a player's journey now reads e.g.
"Positie: geen → CB, LB" instead of showing the raw stored array
("[\"CB\",\"LB\"]"). New position-change events store the formatted value.

# TalentTrack v4.49.0 — Player accounts get a proper "First Last" display name (#1820)

When a player accepts their invitation, their account's display name now
defaults to their first and last name in title case (e.g. "Luuk
Nieuwenhuizen") taken from the player record, rather than an inconsistent
or lower-cased value.

# TalentTrack v4.48.2 — Security: parents can no longer open another family's child profile (#1725)

The player detail view only checked the coarse `tt_view_players` capability, never that the viewer was actually linked to *that* player — so a parent could open any child's profile by id and the "Parents · Guardians" card would expose every co-guardian's name, email, and phone (a safeguarding leak for minors). The view now enforces the canonical per-player scope (`AuthorizationService::canViewPlayer`: own record / global / player's team / parent-of-this-player), and the guardians card renders for staff only (admin/HoD or the team's coach) — never for a parent viewing their own child. Also fixes an adjacent bug where the activities REST endpoint queried `tt_player_parents` with a non-existent `wp_user_id` column (correct: `parent_user_id`), which had wrongly blocked parents from their own child's activities.

# TalentTrack v4.48.2 — PDP (and team-scoped surfaces) now visible to a player's head coach (#1758)

A head coach assigned to a team the legacy way could not see their own players' PDP files — the files tab was empty even though the coverage tab counted the PDP, while HoD/admin saw it fine. Cause: the legacy `head_coach_id` backfill (migration 0006) created the `tt_team_people` link but never the `tt_user_role_scopes` team grant that `get_teams_for_coach()` reads, so `coach_owns_player()` returned false. A new idempotent backfill (migration 0171) creates the missing team-scope grant for every team-people link, so legacy and modern assignments converge on the single matrix source of truth. Head coaches now see their team's PDPs (and every other team-scoped surface); HoD/admin visibility is unchanged.

# TalentTrack v4.48.2 — Safe permanent delete for holidays, test trainings + trial tracks (#1784)

Extends the referential-integrity delete framework (#1783) to three more
record types via new `/permanent` REST routes (gated by `tt_edit_settings`,
fail-closed). **Holidays** are removed directly; **test trainings** clear
any workflow-task link first; **custom trial tracks** block while a trial
case still uses them and built-in (seeded) tracks are refused. No migration.

The remaining archivable entities (custom widget, injury, VCT exercise) and
the list-view affordances stay tracked on #1784.

# TalentTrack v4.48.1 — CI gate: contain new inline styles (#1389)

A new **Inline-style containment** CI gate fails any pull request that
*adds* an inline `style="…"` attribute or a `<style>` block inside
`src/**/*.php`. The repo's large existing backlog is grandfathered — the
gate is diff-only, so it never trips on untouched code — but new inline
styling must now move into an enqueued stylesheet (reading the design
tokens, never raw hex), which is what keeps the spacing/colour drift from
reappearing (CLAUDE.md §2). For a genuinely dynamic value that can't live
in CSS (e.g. a computed progress-bar width), a trailing
`/* tt-inline-ok */` on the same line grandfathers it. The rule is now
documented in CLAUDE.md §2. No runtime change.

# TalentTrack v4.48.1 — Trial case page 2026 card layout + Save/Cancel on trial config forms (#1646)

The trial-case detail page now wraps each section in a token-styled 2026
card with cleaner headings, matching the teams and activity-detail surfaces;
the regenerate-letter form's inline margin moved into the enqueued sheet. The
trial-tracks editor and letter-template editor both gained a proper Cancel
button alongside Save (via the shared `FormSaveButton` helper, honouring any
`tt_back` hint), and the letter editor's monospace HTML textarea moved into a
CSS class. Visual and markup only — no data, query, or permission changes.

# TalentTrack v4.48.1 — Standardize report interfaces to the 2026 card/table/KPI pattern (#1760)

The standard-reports, report-detail and scheduled-reports surfaces now share
the same 2026 look as the attendance report: a KPI strip, card-wrapped tables
(`.tt-report-card` + `.tt-table`), and a consistent page head. The shared
primitives moved into the app-chrome sheet so every report surface inherits
one definition. No data or permission behaviour changed.

# TalentTrack v4.48.1 — Safe permanent delete for tournaments + trial cases (#1784)

Extends the referential-integrity delete framework (#1783) to two more
record types. Permanently deleting a **tournament** now cascades its
matches, squad and per-match assignments and clears a linked activity's
tournament reference; permanently deleting a **trial case** cascades its
staff assignments, staff inputs and extension history and clears any
workflow-task / prospect link. Both are fail-closed — they refuse and name
the dependents if anything undeclared still references them.

Adds the `/tournaments/{id}/permanent` (+ `/restore`) and
`/trial-cases/{id}/permanent` REST routes, and the Restore + Delete-
permanently row actions on the tournaments list. Gated by `tt_edit_settings`.
The remaining archivable entities (which need an `archived_by` column
migration) stay tracked on #1784.

# TalentTrack v4.48.1 — List filter bar: roomier controls + a search icon (#1803)

The search and filter controls on list views now have a comfortable left
text inset instead of hugging the border, and the search box shows a
magnifier icon. Both live in the shared list component, so every list
inherits them.

# TalentTrack v4.48.1 — Team planner: actions moved to the page header (#1804)

The team planner's "Schedule activity" button and the PDF / XLSX / weekly
export actions now sit in the page header, alongside the title, like the
players list — instead of crowding the filter bar. The filter toolbar now
holds only the team picker, the period selector, and the week navigation,
and the period dropdown is sized to match the team dropdown.

# TalentTrack v4.48.1 — Evaluation detail page uses the full width on desktop (#1806)

The evaluation detail page now spans the full content width on desktop,
matching the other pages, instead of rendering as a narrow centred card.
Mobile is unchanged.

# TalentTrack v4.48.0 — Referential-integrity-checked permanent delete (#1783)

Permanent delete is now fail-closed across the archive lifecycle. A new
declarative cascade framework (`CascadeRegistry` + `GenericCascadeDeleter`)
checks, before removing a record, what still references it — then cascades
the record's own children, clears references on rows that outlive it, or
refuses the delete with a message naming what still points at it. A
permanent delete can no longer silently orphan child rows.

Deleting an **evaluation** now also removes its category ratings and
evidence links; deleting a **goal** removes its links and conversation
thread and clears any spawned-goal task link. **Team** and **activity**
permanent-delete now **block** while anything still references them
(previously they deleted the row and stranded its children) — full cascades
for those two are tracked as a follow-up (#1784). Player / person / PDP
deletes are unchanged.

# TalentTrack v4.48.0 — Players list toolbar now matches the standard register card (#1791)

The players list filter/search bar now renders as the standard 2026
"register" card — white surface, soft shadow, comfortable padding, and
rounded, bordered controls — instead of the earlier soft-grey strip with
square-cornered inputs. The toolbar and the table read as two matching
cards, the same chrome every other list uses. The rounded-control fix is
in the shared list-table component, so any list that didn't already style
its own controls now gets rounded search/filter inputs too. Restyle only;
filtering, search, and sort behaviour are unchanged.

# TalentTrack v4.48.0 — Record-name links look the same regardless of the active theme (#1792)

Links to a record (player name, team name, and similar) no longer pick up
the surrounding theme's underline or link colour. The shared record-link
styling is now pinned so an aggressive theme `a` rule can't override it,
so the same install renders these links identically whatever theme is
active. Visual only — link targets and behaviour are unchanged.

# TalentTrack v4.48.0 — Activities list adopts the standard toolbar and full desktop width (#1793)

The activities list Team/Type filter bar now renders as the standard 2026
"register" card, matching the players list, and the list spans the full
content width on desktop instead of a narrow centred column. The period
quick-filter chips (All / This week / Next week / …) are unchanged and
still sit below the filter bar. Restyle only; filtering and the activity
buckets behave exactly as before.

# TalentTrack v4.48.0 — Permanently deleting an archived player no longer fails on PDP calendar links (#1794)

Permanently deleting an archived player who had a PDP with a scheduled
conversation failed with a server error and deleted nothing — the deletion
cascade tried to match PDP calendar links on a column that doesn't exist.
Calendar links are keyed by conversation, so the cascade now reaches them
through the conversation and PDP file, and the delete completes cleanly,
removing those links with the rest of the player's data. The cascade
remains all-or-nothing, so no partial deletes occur. Right-to-erasure of a
player with a full PDP history works again.

# TalentTrack v4.48.0 — Dashboard tile grid adopts the 2026 green/gold look (#1695)

The frontend dashboard renders through `FrontendTileGrid` (the tile
landing shown when no persona template takes over), which carried its own
flat, grey tile styling — it was missed by the earlier persona-landing
(#1769) and `TileGridStandard` (#1790) restyles. Its tiles now match the
2026 mockup: a green left-accent and 12px radius on each tile card, a gold
left-accent on the "Mijn werk" rail rows, green-deep section labels, and
ink/line/paper/muted design tokens throughout (with a green-tinted hover
shadow and brand-green focus rings). Everything reads from the shared
tokens, so the club-colour editor re-themes the dashboard too. Visual
only — no markup, query, or navigation change.

# TalentTrack v4.47.1 — Spond import no longer overwrites notes after the first import (#1774)

A Spond-imported activity's notes are now seeded from the event's description
on the first import only, then owned by TalentTrack — the same "set once, then
TalentTrack wins" model already used for the activity type. Previously every
hourly re-sync rewrote the notes from Spond's description, wiping any notes a
coach had added or edited in TalentTrack. Title, date, location, and the time
fields still follow Spond on every sync. Trade-off: a later edit to the
description in Spond no longer flows into an already-imported activity.

# TalentTrack v4.47.0 — Evaluations list now matches the player-file count when filtered to a player (#1755)

Opening the evaluations list filtered to a single player previously applied
coach team/author scoping, so a coach could see a non-zero "N evaluations"
badge on a player's file yet an empty or short list — evaluations authored by
another coach for a player on a team they don't coach were hidden. When the
list is filtered to one player and the viewer can open that player's file, it
now returns all of that player's non-archived evaluations (club-scoped),
matching the player-file badge count and the player-file Evaluations tab. The
unfiltered evaluations list keeps its coach team/author scoping; access is
gated on the same can-view-player check used to reach the file, so no players
become visible that weren't already.

# TalentTrack v4.47.0 — Team planner "Principles trained" bar: rebalanced label/bar/count (#1756)

The "Principles trained — last 8 weeks" coverage rows under the team planner
laid out poorly: cramped principle labels, an over-wide bar, and no room to
read the count. The row grid is rebalanced — the label column flexes wider
(and long labels wrap instead of truncating), the bar track is narrower at a
fixed width, and the activity count sits clearly to the right of the bar with
breathing space. CSS-only; selectors and markup unchanged.

# TalentTrack v4.47.0 — PDP planning grid follows the configured block count (#1759)

The PDP planning matrix used to derive its number of block columns from the
highest block sequence found across stored conversations, so a legacy or
seed conversation carrying block 4 made the grid show 4 columns even when the
season was configured for 2. The grid now follows the academy's configured
PDP block count for the season (`tt_pdp_blocks`); blocks beyond the configured
count are no longer drawn. When a season has no blocks configured, it falls
back to the previous data-derived behaviour so legacy even-divide installs are
unchanged.

# TalentTrack v4.47.0 — Academy admins can switch individual export tiles off (#1762)

Academy admins can now disable individual export tiles — for example to hide
the Audit log, the Full club-data backup, or Federation registration — from
the Modules management page, under the Export module. There's one toggle per
bulk export tile, all enabled by default, so nothing changes until one is
turned off. Disabling a tile both hides it from the Exports page (for everyone
in the academy, admins included) and rejects that export at the endpoint, so it
can't be run via a direct link either. Toggles are per-academy (club-scoped)
via FeatureRegistry and audit-logged; they only ever narrow access — a user
still needs the underlying capability to see an enabled tile.

# TalentTrack v4.47.0 — Archive a scouting visit from the UI (#1764)

The scouting-visit detail view now has an **Archive visit** action. The
archive (soft-delete) capability already existed in the REST API
(`DELETE /scouting-visits/{id}`) but nothing surfaced it, so a visit could
never be cleared from the list. The button is shown to the visit owner (or
a scope admin), confirms before firing, calls the existing endpoint with a
nonce, and returns the user to the scouting-visits list with a "Scouting
visit archived." notice. No new business logic — the REST route already
enforced the capability and row-ownership check; this only wires it into
the UI.

# TalentTrack v4.47.0 — Player accounts view — link/unlink a WP account to a player (#1771)

A new **Player accounts** view (`?tt_view=player-accounts`, academy/club
admin) lists every player with their account status — No account / Invited
/ Linked — and lets an admin directly **link** an existing WordPress user
to a player or **unlink** one, the primary account-mapping workflow.
Invitations stay the secondary self-service path (the Invite button reuses
the existing flow).

- Link is offered only for accounts not already bound to another player or
  a staff/parent record (no double-binding), and grants the player role.
- Unlink keeps the player record and removes the player role only when the
  account isn't linked elsewhere, so a coach-who-once-played keeps their
  access.
- Resource-oriented REST: `POST /players/{id}/account` (link) and
  `DELETE /players/{id}/account` (unlink), gated by `tt_manage_players`;
  the view and REST share one `PlayerAccountService` so a future
  non-WordPress front end gets the same answers.

Builds on the one-account-one-player DB guarantee from #1772, and supplies
that issue's app-layer "already linked" guard.

# TalentTrack v4.47.0 — Enforce one WP account per player (#1772)

`tt_players.wp_user_id` had no uniqueness guard and no cleanup when a WP
user was deleted, so two players could share an account and the
derived-player scope resolver could surface the wrong child's record — a
safeguarding risk for minors.

- New migration `0170` deduplicates any players sharing a `(club_id,
  wp_user_id)` (keeping the active, data-richest, newest row and
  **unlinking** — never deleting — the rest, with an audit-log entry per
  unlink), normalises "no account" from `0` to `NULL`, and adds a
  `UNIQUE (club_id, wp_user_id)` index.
- New `delete_user` cleanup nulls `tt_players` / `tt_people` account links
  and removes `tt_player_parents` rows for the deleted user, so a
  re-issued WP user id can't inherit someone else's record.
- The player/parent scope resolvers now order deterministically, and every
  write path stores `NULL` (not `0`) for an unlinked player.

No behaviour change for correctly-linked accounts; the link UI and an
app-layer "already linked" guard land with the Player accounts view (#1771).

# TalentTrack v4.46.5 — List toolbar restyled to the 2026 "register" look (#1753)

The filter/search toolbar above every list view adopts the 2026 register style (Option D): a white filter card with a soft shadow and rounded corners, and an uppercase micro-label above each control (Search, Team, Status, Sort…) matching the table header treatment. It's mobile-first — controls stack full-width at phone size and collapse to one inline register row at ≥768px, with 16px inputs (no iOS zoom) and 48px touch targets. Implemented once in the shared `FrontendListTable` + `frontend-admin.css`, so every list inherits it; the filter set stays per-list (each list still declares its own controls). Functionality is unchanged — search, filters, sort, no-JS apply, and the status line all behave as before. Stable `.tt-list-table-*` selectors preserved.

# TalentTrack v4.46.4 — Usage stats: see active users by name, not just role buckets (#1765)

The Application KPIs view gains an **Active users** panel listing the actual people active in the window — each with their role and last-seen time — below the existing role-bucket summary. Each name links through to that user's activity timeline. A new `UsageTracker::activeUsers()` method provides the data (role classification mirrors `activeByRole()`), keeping the query out of the view. The panel stays behind the same admin capability as the rest of the usage dashboard, so names never leak beyond admins. One new string ("Active users (%d days)"), Dutch added; the role-bucket table also now shows translated role labels instead of raw keys.

# TalentTrack v4.46.3 — Report cards regain the contextual back pill (#1761)

Opening a report from the Reports launcher now shows the contextual "← Back to Reports" pill, so you can return to where you came from in one tap. The destination report views already auto-render the pill from a `tt_back` URL hint, but the launcher tiles linked without one — so the pill never appeared. The launcher now stamps each tile link with the launcher page as its back-target via `BackLink::appendTo()`. Breadcrumb chain is unchanged (still ends at Dashboard); no third affordance is added (CLAUDE.md §5).

# TalentTrack v4.46.2 — App-chrome user chip: wider name box + roomier avatar circle (#1751, #1752)

Two small fixes to the signed-in user chip in the top-right app chrome. The display name no longer clips — its box widens from a 14-character cap to 20, with a touch more padding on the chip (#1751). And two-letter initials (e.g. "CN") now sit fully inside the avatar circle: it grows from 32px to 36px with a slightly smaller, properly centred glyph (#1752). CSS-only in `frontend-app-chrome.css`; selectors and the 48px touch target are unchanged.

# TalentTrack v4.46.1 — New-evaluation player picker: team-scoped dropdown instead of blank search (#1731)

The player-first new-evaluation wizard's Player step no longer hides every
player behind a type-to-search box. It now shows a team-scoped native
dropdown: pick a team, then choose the player from the list. A coach who
manages exactly one team lands with that team pre-selected and its players
already listed, so no typing is needed. The team filter repopulates the
player list on change, and Head of Development / Academy Admin keep an
"All teams" option for cross-team reach. The change is opt-in via a new
`style => 'dropdown'` arg on `PlayerSearchPickerComponent`; the ~6 other
surfaces that use the picker keep the existing search behaviour unchanged.

# TalentTrack v4.46.1 — Deep-rate step: collapsible category accordion with aligned stars (#1732)

The player-first new-evaluation Rating step is no longer a flat table of
stars with a Basic/Detailed toggle. Each main category is now a collapsible
block (collapsed by default) whose summary shows the category name, a
read-only star mirror, and the average word — so a coach can scan what's
rated without expanding anything. Expanding reveals the editable
category-level stars and the sub-skill rows; rating sub-skills still sets the
category to the rounded average of the non-zero subs, and the summary
reflects it live. The #1643 training default still surfaces the Mental
category first and opens it. All inline styles moved to a stylesheet; the
star column lines up across categories and sub-rows. Ratings submit and
restore exactly as before — no data-shape change.

# TalentTrack v4.46.1 — Dutch eval-category labels no longer leak English (#1733)

The New-evaluation rating screen (and anywhere eval categories render) leaked
English labels — "Tactical", "Physical", "Short pass", "Dribbling", "Offensive
positioning" — alongside the few that already showed Dutch. The category
vocabulary is seeded in `tt_eval_categories` and resolved through
`tt_translations`, but only a handful of Dutch rows existed, so the rest fell
back to the raw English label on nl_NL installs.

A new idempotent migration seeds the authoritative Dutch label for every
default eval-category and sub-skill straight into `tt_translations`, keyed by
the stable `category_key`. It only seeds a category whose label is still the
seeded English default, so an academy that renamed a category keeps its own
wording; re-running is a no-op. No `.po` or code change — `displayLabel()`
already prefers `tt_translations`.

# TalentTrack v4.45.25 — Spond import maps start → kickoff time and meet-up → presence time, and stops dropping the time of day (#1741)

Activities imported from Spond now keep their **time of day**. Previously the sync stored only the date and discarded the start time, so every imported activity came in time-less. The import now reads Spond's start/end timestamps — converting them from UTC to the site timezone (which also fixes a possible off-by-one calendar day for late-evening events) — and stores them as the activity's start/end time. For **match** types (game, tournament), the Spond start becomes the **kickoff time** and Spond's meet-up time (its "meet X minutes before start" setting, read from `meetupTimestamp` or `meetupPrior`) becomes the **presence time** ("Aanwezig", added in #1729) — both then print on the weekly planner PDF. Times are treated as schedule fields, so a re-sync overwrites them from Spond (consistent with title/date/location); a coach-changed activity type is still preserved. No schema change, no new strings.

# TalentTrack v4.45.24 — Weekly planner PDF: ISO week number in the badge instead of academy initials (#1730)

When no academy logo is configured, the Team Planner weekly PDF's top-left badge previously fell back to the academy/team initials (e.g. "J") — a meaningless orphan, since the week number already sits in the title. The badge now shows the ISO week number (digits only, e.g. "26") instead. A configured academy logo still wins and is shown unchanged. PDF-only cosmetic change; the now-unused `initials()` helper was removed.

# TalentTrack v4.45.23 — Match presence time + fix match start-time not printing in the weekly planner (#1729)

Match-type activities (game, tournament, and any operator-added match/friendly types) can now capture a **presence time** — the arrival/"be present by" time families act on — via a new optional field on the activity form, shown only for match types. It round-trips through the REST activities endpoint and prints in the Team Planner weekly plan PDF as `Present HH:MM` ahead of kickoff. This ship also fixes a latent bug: a match never printed any time in the weekly PDF. The activity form only ever writes `start_time` (kickoff_time stays null), but the weekly-PDF match branch read kickoff_time alone, so a match with a start time showed nothing — it now falls back to start_time and prints `Kickoff HH:MM`. New nullable `time_of_presence` column on `tt_activities` (migration 0168); the wp-admin activities form is unchanged (it captures no time fields, so a lone presence field there would be orphaned). New strings "Present %s" and "Presence time (optional)", Dutch added.

# TalentTrack v4.45.21 — Team planner restyled to the 2026 look (#1683)

The team-planner view body adopts the 2026 chrome: day cells become white cards with rounded corners and a soft shadow, the current day is marked by a gold "today" ring instead of the old blue outline, and activity cards pick up the brand green for their titles with a subtle lift on hover/focus. The "principles trained — last 8 weeks" coverage list is reworked from wrapped chips into a vertical list of proportional gold bars, each scaled against the most-trained principle in the window (the bar is hidden below 520px, leaving the chip + count). This is CSS plus a small markup tweak only — no data, query, or REST changes.

# TalentTrack v4.45.21 — Shared frontend app chrome: top bar + persona chip + KPI tile (#1690)

The global dashboard header — rendered once for every `?tt_view=` route by `DashboardShortcode::renderHeader()` — adopts the 2026 design: a dark-green top bar with a gold brand mark (the academy's initials when no logo is configured) and a **persona chip** showing the signed-in user's initials avatar, name, and resolved persona label (Head of Development, Coach, Speler, Ouder, …). The chip *is* the existing user-menu trigger, so no new navigation affordance is introduced (CLAUDE.md §5) and the dropdown, persona switcher, and docs drawer are untouched — the change is additive (nothing moved). A new `FrontendAppChrome` component (`src/Shared/Frontend/Components/FrontendAppChrome.php`) carries the chip, a brand-initials helper, and a reusable `kpiTile()` for views to call; styling is a new mobile-first `assets/css/frontend-app-chrome.css` reading the existing `--tt-primary` / `--tt-secondary` tokens (no new palette). Persona labels resolve through the SaaS-portable `PersonaResolver`, not role-string checks. Below 560px the chip collapses to the avatar alone. This is the foundation for the per-view visual-parity work (#1680); one new string ("Observer"), Dutch added.

# TalentTrack v4.28.0 — Pixel-faithful image-capture PDF for match-prep + team-sheet print (#1475)

The match-prep print sheet and the match-day team sheet now produce a PDF that visually matches the live page instead of a separately-styled DomPDF rebuild that drifted from what the coach laid out. Both surfaces open a clean, chrome-free print page in a new tab; an **Export as PDF (A4 landscape)** action there captures the visible page with html2canvas and assembles an A4-landscape PDF (jsPDF), scaled to width and split across multiple pages when the content overflows. The capture libraries are vendored locally under `assets/js/vendor/` and lazy-loaded only when the user clicks Export, so nothing extra weighs on the always-loaded front end. The browser's own **Print → Save as PDF** stays on the same page as a text-based fallback, and the server-side DomPDF team-sheet exporter remains registered as a fallback path. The print routes stay cookie-authenticated and capability-gated (`tt_edit_activities` for match prep, `tt_view_activities` for the team sheet). Trade-off accepted for fidelity: captured text in the image PDF is not selectable.

# TalentTrack v4.21.36 — Fix activities table typo that broke saving on fresh installs (#1511)

The plugin activator created the activities table as `tt_activitys` (a misspelling from the #0035 sessions→activities rename) while the entire codebase reads `tt_activities`. Installs that upgraded from `tt_sessions` were fine, but any install created fresh after #0035 got the wrong-named, half-built table and could not save activities ("De activiteit kon niet worden opgeslagen") — and the activities feature was broken throughout. The activator typo is fixed for new installs, and a new idempotent repair migration (0159) adopts an orphaned `tt_activitys` under the correct name and backfills the missing columns. It's a no-op on correctly-built installs.

# TalentTrack v4.21.33 — Group the Reports launcher by purpose (#1503)

The Reports launcher was a flat grid of a dozen tiles under a single "Pick a report." line. It now reads under five purpose-based sections — **Development & performance**, **Playing time**, **Recruitment**, **Staff & quality**, and **Season overview** — so the right report is easy to find. The existing scope filter is unchanged: academy-admin-only reports still hide for regular coaches, and a section with no visible tiles (e.g. Recruitment, Season overview for a coach) renders no header. No new reports, no data or query changes — purely how the existing tiles are laid out.

# TalentTrack v4.21.32 — Fix wizard 404 on subdirectory installs (#1491)

On a subdirectory WordPress install (e.g. `http://host/wordpress`), starting a wizard 404'd after the first step, with the subdirectory doubled in the URL (`/wordpress/wordpress/?tt_view=wizard&…`). `FrontendWizardView::wizardStepUrl()` built the wizard's step/return URL by passing the full `REQUEST_URI` path — which already includes the subdirectory — into `home_url()`, which prepends the subdirectory a second time. The same latent bug sat in `RecordLink::dashboardUrl()`'s last-resort fallback. Both now combine the canonical scheme+host with the request path (no re-prepended home path), mirroring the `currentDashboardUrl()` fix from #1455. Root and subdomain installs were unaffected and stay unchanged.

# TalentTrack v4.21.18 — Surface planned attendance on the activity page + match prep (#1453)

Planned attendance was already captured at activity creation (the roster step writes `record_type='expected'` rows) but never shown back. Two surfaces now read it:

- **Activity detail page** gains an **Expected attendance** panel listing the planned players (guests tagged) with the count, so a coach knows who to expect before the session. It shows nothing when the activity was saved with "Set attendance later".
- **Match prep — Availability step** now seeds its defaults from the planned roster instead of marking everyone Present: planned players default to Present, and team players the coach left out of the plan are pre-marked **Absent** with the reason "not in planned roster". Activities without a planned roster keep the all-Present default.

No new table — this reads the existing `tt_attendance` expected rows. A shared `ActivitiesRepository::plannedRosterForActivity()` backs both surfaces and a new read endpoint, `GET /wp-json/talenttrack/v1/activities/{id}/planned-attendance`, so a non-WordPress front end gets the same data.

# TalentTrack v4.21.17 — wp-admin menu: grouped headings for the modern menu (#1449)

When the legacy entity menus are off, the wp-admin TalentTrack submenu was a flat jumble of operator/utility pages. It now reads under separator headings: **Configuration** (Dashboard layouts, Custom widgets), **Data & demo** (Demo data, Demo data review, Seed review), **Help** (Help & Docs), **Advanced** (Impersonate user), and **Developer** (Module completeness, WP_DEBUG only). Dashboard and Account stay at the top. Each heading auto-hides when its group has no visible row (so module-disabled or cap-gated groups don't leave an orphan heading).

Two pages that registered their own raw `add_submenu_page` — Impersonate user and Module completeness — now register through `AdminMenuRegistry` like every other page, so they group, order, and gate consistently. Ordering is driven by a new `sort` weight on the registry, applied only in the modern menu; the legacy menu's layout is unchanged. (The earlier #1449 ship, v4.21.12, removed the stray Eval Type Categories item and translated "Demo data review".)

# TalentTrack v4.21.16 — PHPStan baseline loads via `includes`, CI actually analyses (#1437)

`phpstan.neon` declared the baseline under `parameters.baseline`, which PHPStan 1.12 rejects (`Unexpected item 'parameters › baseline'`). Because the release workflow runs PHPStan with `|| true`, the config error was swallowed and the job went green without analysing anything — static analysis had been a silent no-op gate. The baseline is now loaded the supported way, via a top-level `includes:` entry, so `vendor/bin/phpstan analyse` parses its config and runs. The grandfathered baseline (`phpstan-baseline.neon`) is still honoured. Making the job actually gate (dropping `|| true`) is a separate follow-up.

# TalentTrack v4.21.15 — Frontend Modules toggle (#1451)

Module enable/disable is now reachable from the frontend admin surface at `?tt_view=modules` (and a Modules tile under Configuration), not only `wp-admin/admin.php?page=tt-modules`. It's gated by a new `tt_manage_modules` capability (administrator + academy admin by default) and exposed over REST (`GET`/`POST /wp-json/talenttrack/v1/modules`) so a non-WordPress front end can read/toggle modules — per the SaaS-readiness principle. Disabling a module prompts a confirm + reload reminder. The wp-admin page stays as the power-user fallback.

# TalentTrack v4.21.14 — Data migration: export for moving data between installs (#1464, phase 1)

First phase of install-to-install migration. The Backups page gains a **Data migration** section: pick which data sets to include (players, teams, staff & roles, evaluations, activities & attendance, goals, lookups & configuration) and download a portable `.ttmig` archive (gzipped JSON, same envelope as a backup, stamped `kind: migration`). Export is read-only and data-only — WordPress users and media aren't included.

The import side (upload + entity/record selection + interactive conflict resolution + user mapping + ID remapping) lands in follow-up phases of #1464.

# TalentTrack v4.21.13 — Dashboard links self-heal off a stale/trashed page (#1462)

Internal dashboard links could point at a trashed page when `dashboard_page_id` config pointed at a page that was later trashed/deleted (e.g. a duplicate dashboard page). Both link resolvers (`RecordLink::dashboardUrl()` + `FrontendAccessControl::dashboardUrl()`) now only trust the configured page when it's published; otherwise they fall through — RecordLink rediscovers the live dashboard page and re-caches its id, FrontendAccessControl falls back to the front page. The setup wizard also now pins `dashboard_page_id` when it creates the dashboard page, so the link-builder and homepage can't drift.

# TalentTrack v4.21.12 — Admin menu cleanup: Dutch labels, no stray Eval Type Categories (#1449)

The wp-admin TalentTrack menu is tidier: **Eval Type Categories** is removed from the menu (it's a low-level evaluation setting — the page stays reachable by URL via a null parent), and the last English-leaking label, **"Demo data review"**, is now translated ("Demogegevens beoordelen"). The remaining items already had Dutch labels, so the menu now reads consistently in the site language.

# TalentTrack v4.21.11 — Dashboard page renders full-width on block themes (#1457)

The dashboard looked narrow because block themes constrain post content (e.g. theme.json `contentSize` ~645px) and the dashboard page held a bare `[talenttrack_dashboard]` shortcode. The setup wizard now creates the dashboard page with the shortcode wrapped in an `alignfull` group block, so it breaks out of the content constraint; the plugin CSS then caps it at 1600px on desktop (#1457's cap). Existing dashboard pages can be updated the same way (wrap the shortcode in a full-width group, or set the page to a full-width template).

# TalentTrack v4.21.10 — Wizards no longer 404 on subdirectory installs (#1455)

Pressing Next in any wizard (activity, team-blueprint, …) 404'd when WordPress is installed in a subdirectory: `WizardEntryPoint::currentDashboardUrl()` rebuilt the URL with `home_url($path)` where `$path` (from REQUEST_URI) already contained the subdir, doubling it (`/wordpress/wordpress/…`). It now combines the site's scheme+host with the request path, so the subdir appears once. Domain-root installs are unaffected.

# TalentTrack v4.21.9 — Dashboard uses desktop width (#1457)

The dashboard was capped at 1100px on every screen. It now widens from the 1024px breakpoint up (to `min(94vw, 1600px)`), so desktops use far more of the viewport while phone/tablet keep the comfortable reading width. If a block theme constrains page-content width below this, a full-width page template is the follow-up.

# TalentTrack v4.21.8 — Version moved into the dashboard header row (#1452)

The operator version indicator now sits in the dashboard header actions row, next to the help button, instead of a footer at the bottom of the page. Still operator-only.

# TalentTrack v4.21.7 — Running version shown on the dashboard (#1452)

Operators now see the running plugin version (`v<x.y.z>`) as a subtle footer at the bottom of the frontend dashboard, so they can confirm what's deployed without opening wp-admin. Gated to operators (`tt_edit_settings`) so player and parent dashboards stay clean.

# TalentTrack v4.21.6 — Installed-version stamp advances after auto-migration (#1448)

After a plugin update via PUC (which doesn't re-fire the activation hook), the kernel ran the migration runner on every request because `tt_installed_version` was only ever set on activation. The kernel now stamps the version once migrations apply cleanly (zero failures), so the runner stops re-firing post-update. A failed migration intentionally leaves the stamp behind so the SchemaStatus retry path still engages.

# TalentTrack v4.21.5 — Plugin boots on init, ending the textdomain notice (#1438)

The kernel now boots on the `init` hook (early priority) instead of `plugins_loaded`. Several modules translate strings (`__()`) during `boot()`; doing that before `init` tripped WP 6.7's `_load_textdomain_just_in_time` "called incorrectly" notice on every request. Booting on `init` means translations resolve cleanly. Module-registered `init` callbacks (default priority) still fire, REST routes, admin menus, and the frontend shortcode are unaffected — verified on a live install (0 notices, 174 REST routes, dashboard renders).

# TalentTrack v4.21.4 — Setup wizard creates the dashboard page and sets it as the homepage (#1441)

The setup wizard gains a dedicated **Dashboard page** step (now six steps). It creates a WordPress page holding the `[talenttrack_dashboard]` shortcode — reusing an existing one if present, never duplicating — and sets it as the site homepage (`show_on_front` / `page_on_front`), so signing in lands straight on the dashboard. The final **Go to dashboard** button now opens that frontend page rather than the wp-admin dashboard. The step can be skipped, and the homepage is changeable later under Settings → Reading.

# TalentTrack v4.21.3 — Lookup values ship translated in all 5 languages (#1442)

Seed lookup vocabularies now carry curated nl_NL / fr_FR / de_DE / es_ES display labels, so dropdowns and status badges render in the site language out of the box instead of falling back to English. A new `LookupTranslationSeeds` map covers the player/coach/parent-facing types — foot, age group (Senior), eval categories + types, activity types + statuses, competition types, game subtypes, goal statuses + priorities + approval decisions, attendance statuses, journey events, player values, behaviour ratings, potential bands, audience types, tournament formats, VCT theme statuses, and the generic certificate types. Migration 0151 seeds them into `tt_translations` with `INSERT IGNORE`, so existing operator edits and earlier backfills are preserved. Locale-invariant codes (age-group U-codes, position codes, UEFA grades) are intentionally left untranslated.

# TalentTrack v4.21.2 — All 17 canonical age groups seeded (#1439)

Installs seeded before the canonical age-group list grew only had 7 options (U8, U10, U12, U14, U16, U19, Senior). The odd-numbered groups (U7, U9, U11, U13, U15, U17, U18, U20, U21, U23) are now present. Migration 0150 tops up existing installs (idempotent, per club) and normalises the display order to age order; the Activator seeds the full set on fresh installs. Custom age groups are preserved.

# TalentTrack v4.21.1 — Setup wizard age-group dropdown shows the site language (#1440)

The setup wizard's "First team" age-group dropdown rendered the raw canonical English value (e.g. `Senior`) regardless of site language. It now uses `QueryHelpers::get_lookup_label_pairs()`, so the visible label honours the site language (e.g. `Senioren` on `nl_NL`) while the submitted value stays the canonical English name — no change to what's persisted for existing teams.

# TalentTrack v4.21.0 — Player motivational layer (#1385)

The player dashboard now feels *for* the player instead of just *about* them. All seven player/parent KPIs — previously permanent "—" stubs — are wired to real data and surfaced on the player landing as progress cards:

- **My rating trend** (rolling average + since-last-month delta), **My activities attended %** (rolling 4-week), **My evaluations received**, **My goals completed**, **My PDP conversations done**, and **My next milestone** (nearest-due goal).
- **My team podium position** is wired too but, per #1384, only appears when the academy has enabled the player-visible rank toggle — so the default landing never shows a permanent dash for it.

The **"A note from your coach"** card is now live: it surfaces the most recent of the player-facing evaluation feedback (#1386) or a comment on one of the player's goals, and hides itself when there's nothing new (no more permanent "No new notes" stub). A **My check-ins** tile anchors the weekly self-evaluation — the one place the academy asks something *of* the player.

KPI business logic lives in the repository layer (`EvaluationsRepository`, `GoalsRepository`, `PdpFilesRepository`, `TeamStatsService`, `ThreadMessagesRepository`); the per-player rating trend is additionally exposed at `GET /players/{id}/rating-trend`. No schema change.

Completes the player-login launch gate (#1384/#1385/#1386).

# TalentTrack v4.20.131 — Player rank is now opt-in, with a growth trend (#1384)

The player-visible "#N of M" team rank on **My team** is now **opt-in per academy** and **off by default**. By default a player sees a growth-framed **personal trend chip** instead: how their rolling rating moved since last month (up / down / level) and the skill category they're improving most. Academies that want the numeric standing can enable it under **Configuration → Rating scale → "Show each player their team rank"**, and it then shows alongside the trend. No other teammate's rank is ever exposed; staff surfaces are unchanged.

The trend is computed in `EvaluationsRepository::personalTrendForPlayer` (two adjacent rating windows + top-improving main category) and is also reachable at `GET /wp-json/talenttrack/v1/players/{id}/rating-trend`, gated per-player by `AuthorizationService::canViewPlayer`. No schema change.

Second slice of the player-login launch gate (#1384/#1385/#1386).

# TalentTrack v4.20.130 — Player-visible evaluation feedback (#1386)

Coaches can now add an optional **Feedback for the player** field when recording an evaluation — a growth-framed message shown to the player (and their parents) on their My evaluations screen, alongside the scores. It is deliberately separate from the existing **Notes** field, which stays staff-only and is never surfaced to player or parent personas. The field is available on both the evaluation wizard (per-player, with interruption-buffer support) and the flat evaluation form, and rides the existing player/parent read surface so no new capability grant is required. **Schema**: one forward-only migration (0156) — additive `player_feedback` column on `tt_evaluations`, no operator action required.

First slice of the player-login launch gate (#1384/#1385/#1386).

# TalentTrack v4.20.95 — Demo→production conversion, PDP archive/delete, pilot-feedback drains, auto-release pipeline

Cumulative release covering every ship since v4.20.51 (2026-06-04). Forty-four patches: two feature epics shipped in slices (demo→production conversion, PDP archive + hard delete), two pilot-feedback drains (2026-06-10 + 2026-06-11), the i18n stabilisation arc, and the release-pipeline automation that makes PUC auto-update on pilot sites work without manual tagging. **Schema changes**: 8 forward-only migrations (0144–0152) — additive columns + backfills, no operator action required on upgrade.

## Demo→production conversion (#1272, v4.20.60–.62 + .75)

Operators who seeded demo data and then started entering real records can now convert in place instead of reinstalling. Admin **Demo Review** page ships a read-only inventory of every demo-tagged row (v4.20.60), a per-batch convert form driven by `DemoConversionService` — promote (strip demo tags) or delete per entity batch (v4.20.61), a terminal lock-out state + audit-log entry once conversion runs (v4.20.62), and per-record overrides on top of the per-batch toggle for the rare row that turned real mid-demo (v4.20.75).

## PDP archive + hard delete (#1274, #1293, #1294, v4.20.63–.65 + .73–.74)

PDP files gain a full lifecycle end: soft archive (schema + repo + REST + cap, v4.20.63), player-archive cascade (v4.20.64), hard delete with a five-table cascade behind the new `tt_delete_pdp` cap (v4.20.65), inline Archive/Restore buttons + show-archived toggle on the PDP list (v4.20.73), and a typed-name destructive-confirm surface with pre-delete CSV export to `wp-content/uploads/tt-pdp-deletes/` (v4.20.74).

## Pilot-feedback drain 2026-06-10 (v4.20.79–.84)

- Player profile Activities tab sorts chronologically with the recent-25 window preserved (#1316).
- Attendance Status select no longer collapses to `Aa▾` on Dutch installs (#1311).
- Goal-intake print gains a 7-block picker — snapshot / doelen 1-3 / afsluiting / handtekeningen / reminder — with a Print-alles escape hatch; team batch shares one selection (#1313).
- **Head-coach persona bug**: coaches assigned via the Staff section landed on the assistant_coach dashboard because no write path ever set `tt_team_people.is_head_coach`. Fixed at both canonical insert sites + backfill migration 0149 (#1314), then the dead `tt_teams.head_coach_id` column was retired outright — all four read sites moved to the modern path, column dropped in migration 0150 (#1315).
- Activities cap checks route through `AuthorizationService::userCanOrMatrix` so Functional-Role-only operators see the same UI the REST API already allowed (#1319).

## Pilot-feedback drain 2026-06-11 (v4.20.85–.92)

- Blueprint assignment refs repair migration for the silently-failed 0129 dbDelta (#1331); save-as-blueprint loud-fail + redirect to editor (#1328); open-saved-blueprint into the chemistry board (#1325); Delete affordance on blueprint list + editor (#1329).
- Goal detail page gains a Print doelenintake action (#1332).
- **Match-day team-sheet PDF now mirrors match-prep** — the exporter reads `tt_match_prep_lineup` + availability instead of never-populated `tt_attendance` columns, match-prep saves write through to `tt_attendance.lineup_role`/`position_played` as a projection, and the match-prep toolbar gains a Print-team-sheet button (#1194).
- **Activities ↔ Tournaments link**: tournament-typed activities carry a `tournament_id` FK (migration 0152), detail view shows the linked tournament with a cap-gated planner deep-link, edit form gains a team-scoped picker; create-new CTA stays admin-only (#1324).

## i18n stabilisation arc (v4.20.72 + .77–.78 + .93 + gates)

The audit-4 translator bundle landed 672 Dutch msgstrs across 11 surface batches (#1279 + 10 siblings, v4.20.77), with a msgctxt hotfix for the demo/PDP `Promote` collision (v4.20.78). The weekly drift report + PR-time drift gate shipped as v4.20.72 (#1223). When `i18n-sync.yml` kept failing post-merge on duplicate-msgid landmines, the PR gate learned to surface msgmerge fatal errors + run msguniq (#1338), and the landmines were cleared — 7 Dutch-literal msgids converted to English, 29 Dutch→Dutch obsolete pairs purged (#1339, v4.20.93). v4.20.95 itself repairs two regressions from that arc (a stderr line interleaved into the .po by the #1339 sweep, and a duplicate `Tournament` msgid that raced the gate).

## Release pipeline — PUC auto-update fixed (#1376, #1318)

PUC on pilot sites checks the latest GitHub **release**, but releases required manual tag pushes and lagged main by dozens of versions — auto-update was structurally broken. New `auto-release.yml` publishes a release (tag created via the release API) on every version bump that lands on main; idempotent against existing releases; the manual tag path stays. Supporting fixes: the legacy-sessions CI gate stopped tripping on every rename-away migration (blanket migrations-dir exclude, #1318), and audit-1's phantom-entity/cap-without-entity CI harness shipped as v4.20.71 (#1191).

## Activities repository extraction (#1320, v4.20.91 + .94)

Option-B per-surface extraction under way: `listForPlayer` (player profile Activities tab) and `listRecentCompletedForPlayer` (hero popovers + status capture) moved into `ActivitiesRepository`; remaining slices tracked on the issue.

## Other

New-activity wizard gains an AttendanceRosterStep with guest disclosure (#1297, v4.20.76). Team planner redirect snaps to the saved activity's week (#1271). Player profile date helpers guard the zero-date sentinel (#1281). `preferred_foot` lookup-type slug consolidation across six callsites (#1278). Audit-11 player-picker pattern coverage doc (#1296).

---

# TalentTrack v4.20.51 — Architectural audit drain, REST security hardening, scope-filter consistency

Cumulative release covering every ship since v4.20.21 (2026-06-03). Thirty patches across the **architectural-audit drain** (10 audits filed, ~47 follow-up issues, 28 fixes shipped) plus four follow-ups to the v4.20.21 pilot-feedback batch. No new feature epics — this release is consolidation: cross-cutting bug families surfaced by the audits and the REST-security class flagged by audit 2. **No operator-breaking changes** — no schema migrations, no capability matrix mutations, no API contract changes.

## Architectural audit infrastructure (#1175 - #1184)

Ten audits filed against the v4.20.21 codebase, each producing a `docs/audits/2026-06-audit-N-<slug>.md` findings doc and a slate of `ready-for-dev` follow-up issues. Audit numbering:

1. Authorization matrix entity catalogue completeness (#1175)
2. REST controller cross-club rewrite class (#1176) — flagged 5 critical CVEs
3. Standard reports scope-filter parity (#1177)
4. i18n hardcoded English literals (#1178)
5. Wizard reactivity (#1179)
6. Persona-dashboard KPI deep-link parity (#1180)
7. Entity scope-filter consistency across reads (#1181) — 7 follow-ups
8. Cross-entity picker privacy (#1182)
9. Form save/cancel + redirect-shape polish (#1183)
10. Documentation surface drift (#1184)

The findings docs ship in `docs/audits/` for future reference. The audit drain ran autonomously overnight with a cron-triggered queue executor.

## Audit 2 — REST security: cross-club rewrite class closed

Five REST controllers accepted attacker-controllable `player_id` / `team_id` / `tournament_id` without scope checks. Single-tenant pilot blunts impact today (`CurrentClub::id()` resolves to 1), but the SaaS-readiness contract (CLAUDE.md §4) requires these closed pre-emptively:

- **#1197 / v4.20.37** — `EvaluationsRestController::update_eval` + `delete_eval` skipped `club_id` in WHERE; `update_eval` never re-ran the `coach_owns_player` gate that `create_eval` enforces. A coach in club A who knew an eval id from club B could rewrite or soft-archive it.
- **#1198 / v4.20.38** — `GoalsRestController::create_goal` accepted any `player_id`; no club lookup, no coach roster gate for non-admins.
- **#1199 / v4.20.39** — `TournamentsRestController::update_assignments` inserted `tt_tournament_assignments` rows with `player_id` straight from the payload; off-squad player_ids now silently drop.
- **#1200 / v4.20.40** — `TeamsRestController::add_player_to_team` accepted any `team_id` from the URL path; cross-club reassign now 404s with `team_not_found`.
- **#1201 / v4.20.41** — `FrontendTrialsManageView::handlePost` accepted `player_id` from POST without club validation; trial cascade now starts from a verified-in-club player_id.

Each fix adds `QueryHelpers::get_*` lookup (which is club-scoped) before mutating; non-admin writers get the existing `coach_owns_player` gate. Error responses (403 `forbidden_player`, 404 `team_not_found`) stay backwards-compatible with create-side shapes so the JS handler doesn't need updates.

## Audit 7 — Entity scope-filter consistency across reads (8 fixes)

Eight reads across coach, admin, and parent surfaces silently mixed archived rows, guest call-ups, and (post #788 ship 2) planned-vs-actual attendance into operational queries. The canonical reference is `TeamRosterTableWidget.php:229-243` — every other read now mirrors that scope:

- **#1222 / v4.20.44** — 4 `tt_activities` reads (KPI snapshot exporter, season-summary KPI strip, per-team match_count column, PDP activities-timeline) add `archived_at IS NULL`.
- **#1224 / v4.20.45** — `CommsScheduledCron` attendance-flag + goal-nudge detection get `att.is_guest = 0`, `att.record_type = 'actual'`, `a.archived_at IS NULL`, plus cross-tenant `pl.club_id = ...` join condition.
- **#1225 / v4.20.46** — `PlayerDashboardView` tabs (evals, goals, attendance) add `archived_at IS NULL` filters; attendance adds `record_type = 'actual'`.
- **#1226 / v4.20.47** — `PeopleRepository::list()` default-hides archived rows; mirrors `PeopleRestController::list_people`. Fixes the parent-link picker and functional-roles surface offering archived parents.
- **#1227 / v4.20.48** — 3 `tt_attendance` reads (player profile KPI tile, activity edit form's per-player attendance map, admin Activities page roster) add `record_type = 'actual'` for stability through #788 ship 2.
- **#1228 / v4.20.49** — `FrontendPdpManageView::renderActivitiesTimeline` adds `is_guest = 0` + `record_type = 'actual'`.
- **#1230 / v4.20.50** — `Wizards\TeamBlueprint\SetupStep` team picker adds `archived_at IS NULL`.
- **#1232 / v4.20.51** — `ReportsPage::runLegacy` "Top 10 players" fallback adds `pl.archived_at IS NULL`; `FrontendComparisonView` misleading pre-#0038 comment rewritten.

Per-helper `club_id` WHERE clauses were deliberately NOT added across this slice. Per #1188 below, tenancy is enforced at the request layer in SaaS, not by individual repository helpers.

## Audit 6 — Persona-dashboard KPI deep-link parity (6 fixes)

#1207 surfaced the foundation bug: `KpiCardWidget` never honoured `linkUrl()` overrides — every per-KPI deep-link fix landed since v3.50.x silently no-op'd in the dominant placement. Fix routes through a new `AbstractWidget::kpiHrefFor()` helper that prefers `KpiDataSource::linkUrl()` over `linkView()`. The five downstream fixes (#1209-#1213) re-enable filter parity between dashboard tiles and their destination views:

- **#1207 / v4.20.22** — `KpiCardWidget::kpiHrefFor()` helper introduced; 11 KPIs migrated to use it.
- **#1209 / v4.20.23** — `ActivePlayersTotal` carries `filter[status]=active`.
- **#1210 / v4.20.24** — 5 academy KPIs (EvaluationsThisMonth, NewEvaluationsThisWeek, AttendancePctRolling, RecentAcademyEvents, GoalsByPrincipleKpi) ship `linkUrl()` overrides with date-window deep-links matching #771's pattern.
- **#1211 / v4.20.25** — `OpenTrialCases` carries `status=open,extended`.
- **#1212 / v4.20.26** — `MyTeamAttendancePct` + `MyTeamAvgRating` pass `filter[team_id]` to destination.
- **#1213 / v4.20.27** — `MyEvaluationsThisWeek` aligns 7d window with destination.

## Audit 3 — Standard reports AC scope leak

Two of the analytics module's reports leaked academy-wide data to the assistant-coach persona (same family as #1147 closed in v4.20.4):

- **#1187 / v4.20.29** — `FrontendStandardReportsView` 6 slug handlers + launcher gain a `scope()` helper that narrows via `get_teams_for_coach` for non-admins. AC-only team/player pickers replace the academy-wide pickers.
- **#1193 / v4.20.34** — `FrontendMinutesTeamReportView` `listTeams()` + URL-tamper guard close the same shape on the minutes-team report (shipped slightly later via #1034, missed by v4.20.4's pass).

## #1188 / v4.20.30 — SaaS-readiness direction-setter

`QueryHelpers::get_player()` historically required a strict `club_id = CurrentClub::id()` match, drifting from the on-screen player loader which doesn't. The drift surfaced as #1149 (Print doelenintake "Player not found" despite player profile rendering) and a family of follow-up scope-mismatch bugs. **Fix** drops the strict club_id clause from `get_player`. **Implication beyond the fix**: this set the direction for every subsequent audit-7 follow-up — per-helper `club_id` filtering is being phased out in favour of request-layer enforcement, which is the right tenancy model for SaaS (CLAUDE.md §4). Inline `What this is NOT` notes throughout the audit drain cite #1188 so subsequent edits don't reflex-revert.

## Audit 1 — Authorization matrix entity catalogue

The matrix admin UI's "no tile uses this entity" warning fired on 17 false-orphan entries because their consumer pages are wp-admin surfaces using either a WordPress cap (`administrator`, `manage_options`, `read`) or a `tt_*` cap that maps via `LegacyCapMapper` to a different entity (e.g. Spond admin uses `tt_edit_teams`).

- **#1189 / v4.20.31** — `CoreSurfaceRegistration` exports tile entity aligned to `reports`. Closes the non-admin-denial half of the bug class.
- **#1192 / v4.20.33** — `MatrixEntityCatalog::ADMIN_ONLY_ENTITIES` widened with 17 entries (`roles`, `authorization_matrix`, `matrix_preview_apply`, `backup`, `demo_data`, `custom_css`, `impersonation_action`, `usage_stats_details`, `documentation`, `persona_templates`, `rating_scale`, `translations`, `translations_config`, `custom_widgets`, `football_actions`, `spond_integration`, `thread_messages`), each with an inline comment naming the consumer surface.

## Audit 9 — Form save/cancel + redirect-shape polish

- **#1195 / v4.20.35** — `FrontendTestTrainingsView` post-save redirect `dashboard` → `list` (same bug class as #795). The `dashboard` shape was unparsed by `public.js` so saves succeeded but the operator saw a blank form.
- **#1196 / v4.20.36** — 3 Cancel buttons (tournament create/edit, VCT defaults card, PHV flag panel) now honour `tt_back` per CLAUDE.md §6 point 5.

## Audit 8 — Cross-entity picker privacy

- **#1202 / v4.20.42** — `FrontendTeamBlueprintsView` "Other team" picker narrows to coach scope. Head-coach editing their own blueprint could browse the entire academy roster across every other team — a privacy leak under CLAUDE.md §1 (minors).

## Audit 4 — i18n

- **#1220 / v4.20.43** — 38 `wp_die()` English literals across Development + Invitations handlers (`IdeaPromoteHandler`, `IdeaRefineHandler`, `IdeaRejectHandler`, `IdeaSubmitHandler`, `TrackDeleteHandler`, `TrackSaveHandler`, `InvitationAcceptHandler`, `InvitationCreateHandler`, `InvitationRevokeHandler`, `MessageSaveHandler`) wrapped in `__()` + 2 misc (`BaseController` field-required sprintf, `BackupSettingsPage` unknown-error fallback). 5 new msgids ship with Dutch translations.

## Audit 5 — Wizard reactivity

- **#1186 / v4.20.28** — `tournament-wizard.js` `rebuildChipHidden` dispatches a change event on the hidden CSV input so autosave fires.

## Architecture — ActivitiesRepository extraction

- **#1190 / v4.20.32** — New `Activities\Repositories\ActivitiesRepository` (`findById`, `listRosterAttendance`, `attendanceMapByPlayer`) shared between `FrontendActivitiesManageView` and `ActivityBriefPdfExporter`. Closes the data-source divergence that produced subtle differences between the edit form and the brief PDF.

## What's not in this release

- **i18n batches #1204-#1219** — Translator-quality work for 10 follow-up batches the audit-4 drain queued. Skip-flagged: needs human review for Dutch nuance, not autonomous patching.
- **#1191 / #1223** — Workflow file edits flagged by audits 1 + 7. Blocked by the release.yml self-modification guardrail.
- **#1194** — Multi-day UI build flagged by audit 9. Out-of-scope for a patch-level audit drain.
- **#1017 / #1129** — Chemistry algorithm (design call needed) + VCT-8 catalogue seed (content-heavy, pilot-coach review gated).
- **#1221** — Direction ambiguous post-#1188's loosened `get_player()`; skip-flagged with three possible directions documented on the issue.

## Upgrade notes

No schema migrations. No matrix seed changes. No new caps. No new tiles. Drop the new zip in place; PUC handles the rest.

---

# TalentTrack v4.19.9 — VCT Phase 2, standard reports, pilot polish

Cumulative release covering every ship since v4.17.2 (2026-05-31). Twenty-two patches across three feature epics — the **VCT module Phase 2 UI**, the **standard-reports module** (12 reports across 2 PRs), and the **2026-06-03 pilot-feedback batch** — plus three rounds of authorization-scope refinement and the foundation rewrites that unblocked them (touch-friendly rating input, lookup-translation completeness, match-prep print rebuild).

The plugin version on disk advanced one minor (4.18.x = VCT Phase 2 UI) and a second minor (4.19.x = standard reports) since v4.17.2; this release rolls both up to a single tag. There are **no operator-breaking changes** — three new schema migrations (0140 PHV extension, 0141 + 0142 lookup-translation backfill, 0143 AC seed trim, 0144 activity time fields) all ship as additive + idempotent.

## VCT module — Phase 2 UI complete (#905)

The Voetbal Conditionele Training module's safety-critical core (schema, rules engine, REST, workflow task) shipped in Phase 1 before v4.17.2. This release closes the Phase 2 UI epic across **eight child PRs**:

- **VCT-12 — Configuration tiles** (#1087, v4.18.1). Two new HoD-gated tiles on the Configuration grid linking into the existing `?tt_view=vct-config` sub-tabs (macro-blocks + age-profiles), with live counts and a NEW pill matching the `.local-mockups/vct-config-tiles/` design-of-record.
- **VCT-13 — Team-defaults panel** (#1088, v4.18.2). Inline panel on team detail with weekday chips + default start time + duration, driving the new-VCT-session wizard's basis-step prefill. Cap-gated on `tt_vct_admin_library`.
- **VCT-14 — PHV per-player panel + hero pill** (#1089, v4.18.3). Schema migration 0140 adds `reason_key` + `intensity_ceiling` columns; Profile-tab panel with reason picker + ceiling dropdown + notes; orange `PHV` pill on the hero when active. Privacy gating per CLAUDE.md §1 (other parents see nothing, AC-also-parent sees own kid via parent persona only).
- **VCT-11 — Exercise library inline edit + search + intensity edge** (#1086, v4.18.4). Each library row gets an inline edit form, a client-side search input, and a 4px intensity-band coloured edge keyed to the mockup's intensity ramp.
- **VCT-9 — New-VCT-session wizard step 1 start time** (#1084 first slice, v4.18.5). Step 1 picks up an optional start-time field; prefills from the team's VCT defaults (#1088). Persists through `VctTrainingComposer` to `tt_vct_sessions.start_time`.
- **VCT-10 — Sideline PHV exclusion banner** (#1085 first slice, v4.18.6). Coach-view banner lists actively-flagged players on the team roster so the sideline reads the same data `WorkloadCapRule` enforces.
- **VCT epic closeout — docs + spec move** (#905, v4.19.3). New `docs/vct.md` (en + nl) with the per-surface URL map, capability matrix, shipped-feature index, parked follow-ups, and the inter-surface data-flow narrative; spec moved to `specs/shipped/0095-feat-vct-module.md`.
- **VCT-8 catalogue seed spun out** as #1129 (content-heavy, gated on pilot-coach review). The engine functions correctly with operator-added exercises today; the catalogue seed is an accelerator, not a blocker.

## Standard reports module — 12 reports across 2 PRs

The standard-reports mockup batch (#1063) shipped its implementation half:

- **6 explorer-bound presets** (#1119, v4.19.0) covering `evaluations_received`, `goal_progress`, `activity_volume`, `evaluation_coverage`, `attendance_vs_squad`, `prospects_logged_per_scout`. Each preset registers a new KPI keyed against the mockup vocabulary and adds an "Explorer →" button on the relevant entity surface (player Goals/Evaluations tabs, team detail, activity detail, Reports launcher). Central URL builder `\TT\Modules\Analytics\Domain\ExplorerUrl::build()` keeps every preset call site to two lines.
- **6 curated per-persona reports** (#1120, v4.19.1) — Player Minutes played, Team Minutes distribution, Team Squad evaluation summary, Season summary, Season Trial funnel, Scout report card. Slug-dispatched on `?tt_view=standard-report&slug=<key>` with shared chrome (KPI strip, empty state, entity pickers when player_id/team_id is absent). Every curated view's "Explorer →" action lands on the matching preset KPI from v4.19.0 with the same entity filter pre-applied.

Each report inherits the host surface's cap gate; the explorer re-checks `tt_view_reports` + the KPI's `context` (COACH / ACADEMY / PLAYER_PARENT). No new permission surfaces.

## 2026-06-03 pilot-feedback batch — 7 issues, 4 PRs

Pilot triage on 2026-06-03 raised seven issues around the activity surfaces, teamplanner, and methodology principles; all closed across four PRs:

- **Planner bugs** (#1133, v4.19.6) closes #1121 (`LabelTranslator::activityType()` routed through `LookupTranslator::byTypeAndName` so operator-added activity-type rows render their Dutch label), #1124 (planner team list scope-filters via `QueryHelpers::get_teams_for_coach()` for non-admins; admins unchanged — was leaking sibling teams to AC users after #1060), and #1127 (planner activity query gains `archived_at IS NULL` so archived activities stop rendering as cards).
- **Principles render polish** (#1134, v4.19.7) closes #1123 (activity detail's linked principles move from an inline `<dt>/<dd>` to a dedicated "Gekoppelde spelprincipes" section with linked-pill palette keyed off the code's first letter) and #1125 (planner card chips show the bare code + bucket colour, up to 4 per card with `+N` overflow).
- **Two-level principle picker** (#1135, v4.19.8) closes #1122. Both `PrinciplesStep` (new-activity wizard) and `FrontendActivitiesManageView::renderForm` (edit form) replace the hold-Ctrl flat multiselect over 18 principles with a stack of `<details>` sections — one per team function — with a small Dutch-cap label per team-task sub-bucket and one checkbox per principle inside. 44px minimum row height so each principle is a real tap target on phones.
- **Activity start/end time fields** (#1136, v4.19.9) closes #1126. Migration 0144 adds `start_time` + `end_time` (both nullable TIME columns) after `session_date`. Wizard step + edit form gain optional time inputs; activity detail, team detail Aankomende activiteiten, planner card, and the REST payload all render the time window when set. Empty fields render nothing — no placeholder.

## Authorization-scope refinements

Three rounds of AC scope-creep audit followed up on #1060's foundational tightening:

- **#1105 / v4.17.3** removed `podium_panel` from the AC default seed — the Podium tile linked to an evaluations-derived leaderboard AC could no longer read.
- **#1106 / v4.19.5** completed the per-entity audit confirming `rate_cards` + `compare` REMOVE (both aggregate development-judgment data) while `reports` / `people` / `vct` KEEP (operational, gated at the next layer or shared with HC by spec). Migration 0143 mirrors #1105's idempotent + `is_default = 1`-only DELETE pattern.
- **#1107 / v4.17.5** locked down the player-detail view's Evaluations / PDP / Trials tabs + avg-rating KPI so they cap-check at render time, not just at the tab-set generator. Defense in depth.
- **#1104 / v4.17.4** added `ORDER BY id DESC` to `AuthorizationService::getPersonIdByUserId` (deterministic resolution when a WP user has multiple active `tt_people` rows) + migration 0139 dedupes existing rows. Closes the AC-dashboard-empty silent disagreement between resolver and admin Persoon edit page that took three hours to diagnose during pilot.
- **#1102 / v4.17.6** added a green-check / amber-warning hint to the persona dashboard editor when a widget's cap is invisible to the persona's default WP role. Editor-time signal so admins don't ship layouts AC users can't see.

## Lookup-translation completeness (#902)

`tt_translations` had three distinct gaps the operator-facing Lookups admin exposed:

- **Gap 1 — positions.** Migrations 0086/0106/0109 called `__('GK')`, but `.po` files only have msgids for the long forms ("Goalkeeper"); the gettext-equal-source guard skipped every INSERT. Migration 0141 drives the long form through gettext via `LabelTranslator::positionLongForm()`.
- **Gap 2 — player values.** The 8 values seeded by migration 0031 (`Commitment`, `Coachability`, etc.) were never wrapped in `__()`. Migration 0142 ships hardcoded translations across nl_NL / fr_FR / de_DE / es_ES; new `LabelTranslator::playerValueLabel()` anchors them for future extractor coverage.
- **Gap 3 — fr/de/es position translator content.** 10 of 11 positions had empty `msgstr` in fr/de/es `.po` files. Filled with standard football vocabulary (Défenseur central / Innenverteidiger / Defensa central, etc.).

## Match prep print rebuild (#1059)

PR #1041's "align browser print to the legacy `MatchPrepPdfExporter` template" decision had both outputs consistent but consistently wrong vs. the on-screen view. New `MatchPrepPrintableRenderer` (v4.19.4) is the single source of truth — formation pitches per half (reusing `FrontendMatchPrepView::defaultSlotLayouts()`), Dutch labels (Algemeen / Aanvallen / Verdedigen / Spelhervattingen ×2), one row per available player on the "Doen per speler" column. `MatchPrepPdfExporter` delegates to the same renderer so print + PDF stay in lockstep going forward.

## Touch-friendly rating input (#1067 / v4.18.0)

Replaces typed-number rating inputs with a chip-grid + inline-slider component wherever a coach captures a rating. New `\TT\Shared\Frontend\Components\RatingInputComponent` ships two render methods: `renderSingle()` emits an 11-chip grid for a single overall rating (no keyboard, one-tap commits a final value); `renderListRow()` emits a label + range slider + tabular value-readout row that fits a 360px viewport with all four canonical category names. Slider rows track an empty state (`data-tt-rating-empty="1"`) so unrated values don't post. Dropped into `PostGameEvaluationForm`, `PlayerSelfEvaluationForm`, `RateActorsStep`, and `HybridDeepRateStep`. Server-side validators upgraded to floats + snap-to-0.5; `EvaluationInserter` mirrors the float+snap before writing.

## Other ships within this release

- **v4.17.0** — printable season-start goal-setting intake + selectable methodology reference card (#1064). Per-player A4 portrait (snapshot + 3 goals + reflection) and team-batch concatenation.
- **v4.17.1** — per-eval-type category allowlist (#819). New `tt_eval_type_categories` join table + admin matrix; wizard filters the category list per eval type.
- **v4.17.2** — `LookupTranslator` into Evaluations repository (#806 first slice). `EvaluationsRepository::recentForCoach()` now pulls + localises lookup-backed fields at the repository boundary so view code that does `echo $row->type_name_localised` gets the localised string by construction. (Architectural worked example; four follow-up tickets file the same pattern in Goals / Activities / Players / PDP repos.)

## What's not in this release

- **VCT-8 — 80-exercise per-club catalogue seed.** Content-heavy, gated on pilot-coach methodology review. Tracked as #1129.
- **Phase 2 mockup-fidelity polish** items the VCT child PRs documented as deferred — wizard MD-context chip-bar visualization, bottom-sheet exercise picker, current-block teal highlight, live timer on the coach view, A4/A6 print polish. Each ships when pilot reports the friction.
- **Per-report cap audit** inside the Reports launcher. Some legacy reports may not check the per-entity cap at the next layer; tracked as a follow-up if pilot finds a leak.

## Upgrade notes

Four schema migrations land in this release. All additive + idempotent; no operator action required:

- **0140** — `tt_player_phv_flags.reason_key VARCHAR(64)` + `intensity_ceiling TINYINT` (VCT-14).
- **0141 + 0142** — backfill `tt_translations` for positions + player values across nl_NL / fr_FR / de_DE / es_ES.
- **0143** — DELETE `(persona='assistant_coach', entity IN ('rate_cards','compare'), is_default=1)` rows from `tt_authorization_matrix`. Operator overrides (`is_default=0`) survive.
- **0144** — `tt_activities.start_time TIME` + `end_time TIME`, both NULL.

`MatrixRepository::clearCache()` fires at the end of 0143 so in-flight AC sessions pick up the change on their next request.

---

# TalentTrack v4.16.0 — Assistant Coach scope tightened to operational-only (closes #1060)

Default authorization matrix defaults change. **AC is operational, HC is development.**

The assistant coach persona inherited too much of the head coach's read access — evaluations, PDP files, behaviour ratings, team chemistry sandbox. The pilot raised this in the context of an AC who is also a parent of a player on the same / sibling team: the kid's evaluations are HC professional-judgment data + safeguarding territory, and shouldn't be visible to the AC even if (or especially when) they're a parent. The fix is broader than that single case — AC's job is operational (run trainings, manage attendance, prep matches, take VCT sessions), HC's job is development (rate, plan PDP, set per-player goals).

## What ships

**Matrix seed change** (`config/authorization_seed.php`) — the AC persona block loses these entities and tile-visibility panels:

- `evaluations` — HC's per-player ratings.
- `pdp_file`, `pdp_verdict`, `pdp_conversations` — Personal development planning + verdicts (safeguarding territory).
- `team_chemistry` — chemistry sandbox + blueprint reads.
- `dev_ideas` — development authoring (AC was previously able to create ideas).
- `player_behaviour_ratings` — behaviour data is dev signal.
- `evaluations_panel`, `team_chemistry_panel`, `pdp_panel` — tile-visibility entities that would render empty tiles without the data caps above.

AC keeps every operational entity (team, players-identity, people, activities, goals, attendance, methodology, reports, rate_cards, compare, documentation, workflow, my-evaluations self-scoped, player_status, trial_inputs, player_timeline, invitations, player_notes, vct, every staff-development entity) plus `pdp_calendar_export` at `self` scope (AC exports own calendar slots).

**Backfill migration** (`database/migrations/0136_assistant_coach_scope_tightening.php`) — DELETEs the 10 removed AC rows from `tt_authorization_matrix` on existing installs, **scoped to `is_default = 1`** so any row an operator explicitly customised via the Authorization admin stays. Flushes the matrix read cache via `MatrixRepository::clearCache()` so AC sessions pick up the change on the next request. Idempotent; forward-only (reverting would re-grant AC access to development data, which is the safeguarding regression this closes).

**No per-surface code changes** — every gated view already routes through `current_user_can()` or `MatrixGate::can()`. Removing matrix rows automatically blocks:

- The Evaluations tab on the player profile (gated on `tt_view_evaluations`).
- The PDP tab + PDP file uploads (gated on the `pdp_file` matrix entity).
- Behaviour ratings card / rate-actor wizard (gated on `tt_rate_player_behaviour` + the `evaluations` matrix entity respectively).
- Team chemistry sandbox + per-blueprint editor (gated on `tt_manage_team_chemistry`).
- The `dev_ideas` authoring surface.

Match-prep and match-execution surfaces are unchanged — those gate on `match_prep` / `match_execution` entities (kept for AC), so HC's per-player notes still flow through to AC inside those operational windows. The AC-with-kid case is handled by the existing parent role: as parent of their own kid the AC sees that kid's evaluations/PDP via the `'parent'` persona block, independent of the AC matrix.

## Verification

Use `wp-admin/admin.php?page=tt-auth-chain-debug`, pick an AC user from the dropdown, confirm these caps return false post-migration:

- `tt_view_evaluations`
- `tt_edit_evaluations`
- `tt_view_player_behaviour`
- `tt_view_pdp`
- `tt_edit_pdp`
- `tt_manage_team_chemistry`

And confirm these stay true (operational entities):

- `tt_view_activities`, `tt_edit_activities`, `tt_mark_attendance`
- `tt_edit_match_prep`, `tt_edit_match_execution`
- VCT caps
- `tt_view_player_notes`

## Out of scope (follow-up issues if pilot raises)

- **Per-tab visibility on the player profile** when AC reaches a player's page. The Goals tab still renders since `goals` matrix entity stays operational (match-prep flow needs it). If pilot reports the tab feeling out of place, file a follow-up that introduces per-tab gating distinct from data-entity gating.
- **`tt_drill_analytics` cap** for the explorer view per §3 of #1060. Current behaviour: explorer view gates on the `analytics` matrix entity (kept HC-only). The optional belt-and-braces cap is a follow-up.
- **Aggressive migration**: today's migration preserves operator customisations. An aggressive variant that flips every AC row regardless of `is_default` is available if a future install needs the harder reset (e.g. SaaS multi-tenant onboarding).

## Pilot impact

AC users see the same operational surfaces they always did (activities, attendance, match prep/execution, methodology library, VCT, their own calendar + staff development). They no longer see other players' evaluations, PDP files, behaviour ratings, or the team chemistry sandbox. The AC-also-parent case sees their own kid's development data through the parent persona, unchanged from before.

---

# TalentTrack v4.13.0 — Team chemistry page rework, single-tier blueprint port (closes #1002, supersedes #1007)

Full surface rework of `?tt_view=team-chemistry`. Ports the design-of-record mockup at `.local-mockups/team-chemistry/index.html` onto the live surface: three-column shell with a roster sidebar on the left, the pitch in the centre, and a stacked KPI scoreboard plus coach-marked pairings panel on the right. The chemistry surface is single-tier — the chemistry engine scores primary cells only, so the secondary / tertiary tier stack the blueprint editor exposes is irrelevant here. Each pitch position renders one slot card.

## What ships

**PHP — view rebuild**

- `src/Modules/TeamDevelopment/Frontend/FrontendTeamChemistryView.php` (rewrite) — replaces the v1 single-column inline-styled layout with a mockup-driven three-column grid. New methods: `renderToolbar()` (formation picker + style summary + Suggested / Try-a-lineup segmented toggle + Save-as-blueprint), `renderRosterSidebar()` (sorted by best team-fit score, searchable), `renderPitchCard()` (hands off to `PitchSvg` with the legend chrome), `renderRightColumn()` + `renderScoreboard()` (the headline link-chemistry card plus composite / formation / style / depth / coverage sub-cards from the mockup) + `renderPairingsCard()` (inline coach-pairings list with a collapsible add form). The depth-chart table is dropped — the data still flows to the picker via the localised payload, but the standalone three-column "1st / 2nd / 3rd choice" table is gone in favour of the per-slot picker.
- Asset enqueue moved out of the cap-gated sandbox path. The chemistry CSS now enqueues on every entry to the view (team picker + board + error states) so styling is consistent everywhere; the JS still cap-gates on `tt_manage_team_chemistry`.

**JS — selector retarget + new wiring**

- `assets/js/frontend-team-chemistry.js` (rewrite) — selectors retargeted from `.tt-chem-sandbox*` to `.tt-tc-sandbox*`. New `wireSegmentedToggle()` replaces the v1 single-button `wireToggle()` and binds both segments (Suggested / Try a lineup) instead of toggling one. New `wireRosterFilter()` does live substring filtering on the sidebar (case-insensitive, name + position). New `wireFormationAutosubmit()` replaces the v1 inline `onchange="this.form.submit()"` so CSP-strict installs work. Sandbox + bottom-sheet picker + save-as-blueprint modal behaviour is unchanged from v3.110.174 / v3.110.184; only the surface they bind to has moved.

**CSS — mockup port**

- `assets/css/frontend-team-chemistry.css` (rewrite) — full token system from the mockup (`--tt-tc-bg`, `--tt-tc-panel`, `--tt-tc-line`, `--tt-tc-accent`, `--tt-tc-accent-2`, `--tt-tc-strong`, `--tt-tc-weak`, etc.). Mobile-first base CSS for ~360px; tablet at 768px (two-col with right column going full-width below); desktop at 1180px (three columns, right column sticky). Touch targets ≥ 44px on every interactive surface (toolbar select / segmented buttons / pairing form inputs / pairing-x remove). 16px input font-size for iOS no-zoom on focus. `prefers-reduced-motion` honoured on the picker + sandbox-on slot animations. The bottom-sheet picker + save-as-blueprint modal styles are preserved from v3.110.174 / v3.110.184 with the new token names.

## Bugs caught + fixed from #1007 (supersedes)

The `?tt_view=team-chemistry` v1 surface had four investigation-grade defects the rework folds in alongside the layout port:

1. **Inline `onchange="this.form.submit()"` on the formation dropdown.** Breaks under CSP `script-src 'self'` and produces a console warning on stricter dashboard themes. v4.13.0 replaces it with a `data-tt-tc-autosubmit` attribute handled by the chemistry JS.
2. **Team picker had no stylesheet enqueued.** The CSS file was only loaded inside `enqueueChemistrySandboxAssets()`, which was cap-gated on `tt_manage_team_chemistry`. Read-only viewers landing on the team picker saw unstyled `<a>` cards. v4.13.0 enqueues `frontend-team-chemistry.css` from the top of `render()` so every code path picks it up.
3. **No empty-state for installs without `tt_formation_templates` rows.** v1 emitted a one-line `tt-notice` and returned, with no styling. v4.13.0 renders a `.tt-tc-emptystate` card with a clear "Configure one in Settings → Team development" pointer.
4. **Help link button "How does this work?" stacked above the board.** Pushed the toolbar + pitch down 60px on phones for no benefit. v4.13.0 moves the help row below the board so the chemistry score is the first thing on screen.

## Out of scope

- Chemistry algorithm: unchanged (`BlueprintChemistryEngine::computeForLineup()` / `computeForSuggested()`, `ChemistryAggregator::teamChemistry()`).
- REST contracts: unchanged. `GET /teams/{id}/chemistry`, `POST /teams/{id}/chemistry/preview`, pairings CRUD, blueprint create + assignments PUT — all hit unchanged.
- Schema: no migration.
- Caps: same — `tt_view_team_chemistry` for read (dispatcher-gated), `tt_manage_team_chemistry` for sandbox + pairings CRUD.
- Multi-team chemistry comparison: separate ship if asked.
- Per-player chemistry detail drilldown: separate surface.
- The reasoning panel in the mockup ("Why?" with Default / Slot / Link states) is shape-only and deferred to a follow-up — the mockup's `body[data-sel]` switch is a JS state machine that needs server-side explanation strings the engine doesn't currently emit. Tracked separately.

## Why minor bump

Meaningful surface rework + restored functionality on a previously-broken page (#1007). Patch bump would understate the visual + interaction change.

# TalentTrack v4.12.15 — Match prep print polish + short player names (closes #1023)

Two scopes ship in one PR because they share files (the match-prep view + CSS) and the on-screen short-name change is what the print CSS inherits.

## A. Print polish (six items)

1. **Hide the dashboard brand banner / DEMO strip / breadcrumbs on print.** The `@media print` block now adds `display: none !important;` to `.tt-dash-header`, `.tt-dash-brand`, `.tt-dash-actions`, `.tt-dash-demo-pill`, `.tt-dash-help`, `.tt-user-menu`, `.tt-back-pill` on top of the existing `.tt-breadcrumbs` / `.tt-back-link-wrap` / `.tt-mp-toolbar` rules. The shared dashboard chrome (rendered by `DashboardShortcode::render()`) was leaking the JG4IT brand row + tagline + DEMO pill onto every printed page.
2. **Page title is now the first line on paper.** Source string changed from `Match prep — %1$s · %2$s` to `Match preparation — %1$s · %2$s` so the Dutch translation `Wedstrijdvoorbereiding — …` lands as the first visible printed line at 12pt bold (≈16px), no top margin. CSS rule `.tt-match-prep-title { font-size: 12pt !important; margin: 0 0 3mm !important; }` inside the print block.
3. **Player-name labels visible on both pitches.** The on-screen `.tt-mp-slot .tt-mp-slot-name` uses translucent backgrounds and inherited colours; the print block now forces `color: var(--tt-mp-ink) !important; background: #fff !important;` plus `-webkit-print-color-adjust: exact` so the slot number circle AND the player-name label both render on paper.
4. **Restore `!` (red) and camera (green) icon colours on print.** `.tt-mp-dps tbody td.tt-mp-col-spec.tt-mp-on` forces `color: var(--tt-mp-danger)` and `.tt-mp-dps tbody td.tt-mp-col-cam.tt-mp-on` forces `color: var(--tt-mp-success)`, both with `print-color-adjust: exact;` so they survive the "Background graphics off" default in print dialogs.
5. **Compact Wedstrijddoelen so it fits one landscape-A4 page.** Goal-box font 9pt → 7.5–8pt; row padding halved (`0.25mm 1mm`); section heading padding halved (`0.5mm 2mm`); `.tt-mp-goals-row` forced to two columns so attacking + defending sit side by side; grid columns tightened from `50mm / 1fr / 70mm` to `48mm / 1fr / 64mm`; body font 10pt → 9pt; line-height 1.25 → 1.2. The whole spreadsheet now fits on one landscape-A4 sheet at 100% print scale on Chrome / Edge / Firefox.
6. **Empty goal lines print blank, no placeholder dots.** Placeholder text (`…`, `Goal 1…` etc.) is now `color: transparent !important; opacity: 0 !important;` on every print-time goal-line input via `::placeholder` / `::-webkit-input-placeholder` / `::-moz-placeholder`. The horizontal underline rule remains visible — coaches see a clean line to write into.

## B. Short player names (whole match-prep surface)

New helper `TT\Shared\Util\PlayerShortName` resolves a list of players into a `[ player_id => short_name ]` map:

- Default: first name only (`Daan`, `Senna`, `Javi`).
- Disambiguation: when two players in the input set share a first name, both render as `<firstName> <lastInitial>` (`Daan P`, `Daan A`). The disambiguation scope is the input set, not the whole club.
- Graceful fallback for players with missing first or last names (returns the available part, or `—`).
- v1 assumes Western "first last" order — East-Asian "last first" conventions deferred.

`FrontendMatchPrepView::render()` computes the short-name map once from the team roster and threads it into every render site:

- Roster column (Selectie · Minuten).
- Doen per speler column (Player focus).
- Rollen & standaardsituaties column (Roles & set pieces).
- Pitch slot labels — the bootstrap payload's `players[].name` is the short form, so the JS `renderPitches()` / `renderRoster()` / `renderDps()` / `renderRoles()` paths pick it up without code changes.
- Availability drawer (`renderDrawer()`) — same `state.players[].name` source, same short form, same vocabulary across every sub-surface.

The full name is still passed on the bootstrap as `players[].full` so a future view variant can show the long form if needed; current renderers only consume `name`.

## Files

- `src/Shared/Util/PlayerShortName.php` (new) — the short-name resolver.
- `src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php` — title string change, short-name map, threaded into roster / Doen / Rollen / bootstrap.
- `assets/css/frontend-match-prep.css` — print-block rewrite for items 1-6.
- `.local-mockups/match-preparation/index.html` — mockup parallel-tracked so the design-of-record stays current.
- `talenttrack.php`, `readme.txt` — version 4.12.12, changelog stanza.
- `CHANGES.md` — this stanza.

## Out of scope

- Other surfaces (Player profile, Activities list, Team detail) still use full names. The short-name helper is `Shared\Util` so future call sites can adopt it, but this PR is match-prep only per the spec.
- Per-locale name ordering (East-Asian "last first") — v1 assumes Western order.
- Retrofit of any other view's print-CSS — same six items might apply to VCT-print / match-execution-print, deferred.

## DoD

- [x] Print one A4-landscape page fits everything (CSS-spec'd via 8pt body / halved padding / 48mm-1fr-64mm columns / two-up goal grid).
- [x] No dashboard brand chrome on print (`.tt-dash-header`, `.tt-user-menu`, breadcrumbs, back-pill all hidden).
- [x] Page title is first visible line at 12pt bold (Wedstrijdvoorbereiding — … via Dutch translation of the new source string).
- [x] Player names appear on both pitches in print (forced `color` + opaque `background` + `print-color-adjust: exact`).
- [x] `!` red, camera green on print (`tt-mp-on` rules in print block).
- [x] Empty goal lines print as clean rules with no placeholder text.
- [x] On-screen + print: every player label uses the short form (resolver threaded into PHP renders + JS bootstrap).
- [x] `.local-mockups/match-preparation/index.html` mirrors the changes.
- [x] Patch bump v4.12.12.

(closes #1023)

---

# TalentTrack v4.12.10 — PHPStan rule enforcing vocabulary constants (PR-set 8 of 8 — closes #988 umbrella)

Final PR-set in the #988 umbrella migration. Lands the custom PHPStan rule that flags raw string-literal comparisons against any value already enumerated under `TT\Domain\Vocabularies\Lookups\*` or `TT\Domain\Vocabularies\Enums\*` — the regression gate that prevents PR-sets 1-7's work from silently un-doing itself as new code lands.

## What ships

**PHP - PHPStan rule**

- `tests/PhpStanRules/VocabularyConstantsRule.php` (new) — implements `PHPStan\Rules\Rule`. On first node visit, scans `src/Domain/Vocabularies/{Lookups,Enums}/*.php` via reflection and builds a flat index of `string value -> [Class::CONST, ...]` suggestions. Walks four AST node families on every analyse run:
    - `BinaryOp\Identical` (`===`) and `BinaryOp\NotIdentical` (`!==`).
    - `BinaryOp\Equal` (`==`) and `BinaryOp\NotEqual` (`!=`).
    - `FuncCall` to `in_array($needle, [ 'literal_1', 'literal_2' ], $strict)` — the most common allowlist shape in the codebase.
  For each `String_` operand whose value matches a known vocabulary value, emits one error per literal: `String literal 'present' matches a TalentTrack vocabulary value. Use the typed constant AttendanceStatus::PRESENT instead (umbrella issue #988).` Identifier `talenttrack.vocabularyConstants`. Tip text directs the reader to `src/Domain/Vocabularies/{Lookups,Enums}/` and acknowledges the deliberately-out-of-scope contexts (SQL string literals, array keys, migration seeds — those may be locally suppressed when the rule lands a false-positive).

  Out of scope by design:
    - `switch ( $value ) { case 'present': ... }` arms — walking `Stmt\Case_` nodes is straightforward but reserved for a v2 iteration once the rule has burned in.
    - SQL string literals inside `$wpdb->prepare()` arguments — DB is the canonical source of truth; the literal there IS the canonical value.
    - Array keys like `[ 'present' => __( 'Aanwezig', 'talenttrack' ) ]` — the key IS the canonical value; rewriting it to `AttendanceStatus::PRESENT => ...` is correct but is a separate sweep.
    - Default-parameter literals (`function ( string $status = 'manual' )`). Reachable later via a `Param` walk; out of scope for v1.

- `tests/PhpStanRules/vocabulary-constants-rule.neon` (new) — opt-in PHPStan overlay. Registers the rule via `services:` with the `phpstan.rules.rule` tag. NOT included from `phpstan.neon` by default — operators wire it on by including this overlay from their own local config (`includes:` array in `phpstan.local.neon`). The header comment in the .neon file documents the wire-up.

**PHP - autoload wiring**

- `composer.json` — gains an `autoload-dev` PSR-4 mapping for `TT\Tests\PhpStanRules\` -> `tests/PhpStanRules/` so PHPStan can resolve the rule class via the composer autoloader. The mapping is in `autoload-dev`, not `autoload`, so the runtime plugin classmap stays unchanged. `composer dump-autoload` is required locally to pick up the new map; CI's `composer install` step covers this automatically.

**Default-disabled rationale**

Per #988's locked decisions (2026-05-28), PR-set 8 ships as infrastructure but with the rule **disabled by default**. The backwards-compat allowlist documented in `docs/rest-api.md` keeps raw string-literal comparisons legal until the one-release deprecation window closes — flipping the rule into the default `phpstan analyse` run today would flood the build with errors on the same call sites the allowlist deliberately tolerates (REST endpoints accept BOTH the raw literal AND the typed constant for one release). The wire-up is one `includes:` line away when the allowlist sunsets in the next minor.

**Rule severity**

The rule emits PHPStan-native `error`-level diagnostics — there is no `info` / `warning` tier in PHPStan core. "Disabled by default" is the equivalent of `info` for this rule until enabled.

## Why patch

PR-set 8 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration, no REST change, no UI change. The 29 existing vocabulary classes under `src/Domain/Vocabularies/` are unchanged. The plugin runtime is byte-equivalent — only `composer.json` autoload-dev + two new files under `tests/` (which are not included in the plugin's runtime classmap).

## Test plan

- `composer install --dev` resolves the `autoload-dev` map; `vendor/composer/autoload_psr4.php` lists the `TT\Tests\PhpStanRules\` namespace.
- `vendor/bin/phpstan analyse -c phpstan.neon` runs unchanged — the rule overlay is NOT included; the analyse output is byte-equivalent to v4.12.9.
- Create a local `phpstan.local.neon` with the documented two-line `includes:` overlay. `vendor/bin/phpstan analyse -c phpstan.local.neon` emits at least one error of identifier `talenttrack.vocabularyConstants` on each existing `=== 'present'` / `=== 'completed'` / etc. site in `src/`.
- The rule does NOT flag SQL-prepare string literals (e.g. `'WHERE status = %s'` is a single literal, no equality operator near a vocabulary value).
- The rule does NOT flag literals inside `src/Domain/Vocabularies/` itself (the constants there ARE the canonical values; the equality check `'present' === self::PRESENT` would otherwise self-report).
- The rule's index is populated at first node visit, not per-node; analyse run time is negligibly affected (one-time `scandir` + 29 `ReflectionClass` constructions).

## Closes

The #988 umbrella issue. Each of PR-sets 1-7 closed its corresponding `partial #988` slice; this PR-set is `closes #988` since it is the final infrastructure piece (the PHPStan rule the umbrella's checklist named explicitly as PR-set 8). The rule itself is disabled by default per the locked decisions; flipping it on is a separate, single-line config edit in a future minor when the backwards-compat allowlist sunsets.

---

# TalentTrack v4.12.9 — Vocabulary constants for auth + ideas + invitations + behaviour (PR-set 7 of #988)

Seventh of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) in v4.12.3; PR-set 5 (reports + journey + scouting) in v4.12.5; PR-set 6 (tournament + match) in v4.12.6; PR-set 3 (PDP + trial) in v4.12.7; PR-set 4 (player + team) in v4.12.8; this ship — landing as v4.12.9 — covers the auth + ideas + invitations + behaviour vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/IdeaStatus.php` (new) — nine constants for the values stored on `tt_dev_ideas.status`: `SUBMITTED`, `REFINING`, `READY_FOR_APPROVAL`, `REJECTED`, `PROMOTING`, `PROMOTED`, `PROMOTION_FAILED`, `IN_PROGRESS`, `DONE`. Mirrors the PR-set 1 / 2 / 3 / 4 / 5 / 6 file shape (`const ALL` + static `isValid()`). The nine values are the canonical lifecycle set per the `IdeaRepository::transition()` chokepoint, the `GitHubPromoter` start / failure paths, the kanban board's `boardColumns()` filter, and the `AuthorNotifier` notification arms.
- `src/Domain/Vocabularies/Lookups/IdeaType.php` (new) — four constants for `tt_dev_ideas.type`: `FEAT`, `BUG`, `EPIC`, `NEEDS_TRIAGE`. Maps directly to the type marker that goes into the promoted GitHub file (`<!-- type: feat -->` etc.) and the `<type>` segment of the assigned filename.
- `src/Domain/Vocabularies/Lookups/InvitationStatus.php` (new) — four constants for `tt_invitations.status`: `PENDING`, `ACCEPTED`, `EXPIRED`, `REVOKED`. Backs the `invitation_status` lookup seeded by migration 0108 with display labels for en_US / nl_NL / fr_FR / de_DE / es_ES.
- `src/Domain/Vocabularies/Lookups/InvitationKind.php` (new) — three constants for `tt_invitations.kind`: `PLAYER`, `PARENT`, `STAFF`. Drives the role resolver that maps a `kind` to a WP role (`tt_player` / `tt_parent` / staff functional role) on acceptance.
- `src/Domain/Vocabularies/Lookups/BehaviourRating.php` (new) — five constants for the 1..5 scale captured on `tt_player_behaviour_ratings.rating`: `CONCERNING` ('1'), `BELOW_EXPECTATIONS` ('2'), `ACCEPTABLE` ('3'), `STRONG` ('4'), `EXEMPLARY` ('5'). The column is DECIMAL so non-integer values (e.g. 3.5) are accepted when a coach captures a between-tier judgement; the five constants below are the canonical anchor points each `behaviour_rating_label` row maps to. Documentation-only addition this PR-set — no PHP-side `'1'..'5'` comparison literals surfaced; the class documents the seeded anchor set for future PHPStan rule consumption (PR-set 8).
- `src/Domain/Vocabularies/Lookups/PotentialBand.php` (new) — five constants for `tt_player_potential.potential_band`: `FIRST_TEAM`, `PROFESSIONAL_ELSEWHERE`, `SEMI_PRO`, `TOP_AMATEUR`, `RECREATIONAL`. Backs the `potential_band` lookup seeded by migration 0042 with display labels in en_US / nl_NL; consumed by `PlayerStatusCalculator::POTENTIAL_BAND_SCORES` (100 / 80 / 60 / 40 / 20 weights) and the trainer-facing potential-capture surface.
- `src/Domain/Vocabularies/Enums/ImpersonationEndReason.php` (new) — two constants for `tt_impersonation_log.end_reason`: `MANUAL`, `EXPIRED`. Code-only enum (not operator-editable), lives under `Vocabularies\Enums\*` per #988's locked sub-namespace split. `MANUAL` is the actor's "Switch back" click + the `ImpersonationService::end()` default-parameter case; `EXPIRED` is the daily orphan-cleanup cron closing a session older than 24h whose `ended_at` was still NULL.

**PHP - legacy classes converted to deprecated aliases**

- `src/Modules/Development/IdeaStatus.php` — the nine `public const *` declarations now delegate to `TT\Domain\Vocabularies\Lookups\IdeaStatus::*` via `use … as CanonicalIdeaStatus`. Each constant carries a `@deprecated since v4.12.9 — removed in next minor` docblock. The module-local `label()` / `authorFacingLabel()` / `boardColumns()` / `all()` helpers stay in place — they encode rendering rules that aren't part of the vocabulary contract.
- `src/Modules/Development/IdeaType.php` — same pattern: four `public const *` declarations delegate to the canonical `Vocabularies\Lookups\IdeaType::*` values; `label()` / `isValid()` / `all()` helpers stay.
- `src/Modules/Invitations/InvitationStatus.php` — same pattern: four `public const *` declarations delegate to `Vocabularies\Lookups\InvitationStatus::*`; `label()` helper stays.
- `src/Modules/Invitations/InvitationKind.php` — same pattern: three `public const *` declarations delegate to `Vocabularies\Lookups\InvitationKind::*`; `label()` / `isValid()` / `all()` helpers stay.

**PHP - literal -> constant replacements**

- `src/Infrastructure/PlayerStatus/PlayerStatusCalculator.php` — the `POTENTIAL_BAND_SCORES` map's five string keys (`'first_team'` ... `'recreational'`) swap to `PotentialBand::FIRST_TEAM` ... `RECREATIONAL` constants. Use statement added.
- `src/Infrastructure/REST/PlayerStatusRestController.php` — `setPotential()`'s allowlist literal-array `[ 'first_team', 'professional_elsewhere', ... ]` → `PotentialBand::ALL`. Use statement added.
- `src/Shared/Frontend/FrontendPlayerStatusCaptureView.php` — the form-handler's allowlist literal-array → `PotentialBand::ALL`; the `<select>` option-label map's five string keys → `PotentialBand::*` constants. Use statement added.
- `src/Shared/Frontend/FrontendPlayerDetailView.php` — the potential-popover `$bands` map's five `key` literals → `PotentialBand::*` constants. Use statement added (alongside the existing `PlayerStatus` import from PR-set 4).
- `src/Modules/Authorization/Impersonation/ImpersonationService.php` — `end()` method's `string $end_reason = 'manual'` default-parameter literal → `ImpersonationEndReason::MANUAL`. Use statement added.
- `src/Modules/Authorization/Impersonation/ImpersonationAdminPost.php` — `end()` handler's `ImpersonationService::end( 'manual' )` call-site literal → `ImpersonationEndReason::MANUAL`. Use statement added.
- `src/Modules/PersonaDashboard/Widgets/SystemHealthStripWidget.php` — `countPendingInvitations()`'s defensive `class_exists()` fallback literal `'pending'` → `InvitationStatus::PENDING` (canonical). Use statement swap: `TT\Modules\Invitations\InvitationStatus` → `TT\Domain\Vocabularies\Lookups\InvitationStatus`.

**Out of scope for this PR-set**

- `CertificationType` — empirical grep on the codebase surfaced zero PHP-side string-literal comparisons against the six `cert_type` lookup keys (`uefa_a`, `uefa_b`, `uefa_c`, `first_aid`, `gdpr`, `child_safeguarding`) seeded by migration 0048; the values live in `tt_lookups` and are read-only on the operator-facing surface (the cert-type lookup-id is the FK in `tt_staff_certifications.cert_type_lookup_id`, not a string-key comparison). A constants class would document them without making any literal-to-constant swap. Deferred to a future PR-set if call sites surface — same shape as PR-set 4's `PlayerValue` / `AgeGroup` / `Position` deferral.
- `BehaviourRating` is **declared-only** in this PR-set — the column is DECIMAL so the canonical 1..5 anchor values are stored numerically; PHP-side comparison literals against the five anchor keys don't surface in the call sites. The class documents the seeded anchor set for future PHPStan rule consumption (PR-set 8).
- Other auth-related state machines — MFA enrollment-state (timestamps + counters, no discrete vocabulary), audit log payloads (free-form), comms log status (separate cleanup task) — out of scope; the auth surface in PR-set 7's title refers specifically to the impersonation `end_reason` code-only enum.
- SQL string literals (`SET end_reason = 'expired'` in `ImpersonationService::cleanupOrphans()`'s UPDATE statement), `tt_lookups` seed values in `LookupCanonicalSeeds.php`, migrations 0024 / 0025 / 0042 / 0048 / 0108 / 0115 default values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 7 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Admin submits a new dev idea via the `?tt_view=ideas-submit` surface: stored with `type=needs-triage`, `status=submitted`. Idea board renders the new card in the Submitted column.
- Admin refines the idea (Type → `feat`, Status → `ready-for-approval`): stored. `refined_at` / `refined_by` populated by `IdeaRepository::transition()`. Idea moves into the Ready-for-approval column on the board.
- Admin promotes the idea: `GitHubPromoter::promote()` flips status to `promoting`, then `promoted` on success or `promotion-failed` on API failure. Author notification fires on each transition arm.
- Admin invites a player via the `?tt_view=configuration&config_sub=invitations` surface: row inserted with `kind=player`, `status=pending`.
- Invitee opens the acceptance URL: `AcceptanceView` renders the player-details step; accept POST flips status to `accepted`.
- Admin revokes a pending invitation: row's status flips to `revoked`.
- A pending invitation past `expires_at` is opened: `InvitationService` lazy-flips it to `expired` before rendering the "this link has expired" page.
- System health strip widget on the admin dashboard reports the count of `pending` invitations.
- Coach records a behaviour rating of 3 via the player status capture: row inserted with `rating=3.0` against the seeded `behaviour_rating_label` 1..5 vocabulary.
- Coach sets a player's potential to `semi_pro`: row inserted in `tt_player_potential` with `potential_band=semi_pro`. `PlayerStatusCalculator` scores the band at 60 (vs 100 for `first_team`, 20 for `recreational`).
- Frontend player detail view's potential-popover renders the five bands with the canonical English labels (First-team / Professional elsewhere / Semi-pro / Top amateur / Recreational).
- REST `POST /players/{id}/potential` with `potential_band=first_team`: 200. With `potential_band=top_pro` (typo): 400 `bad_input` with `allowed` array listing the five canonical bands.
- Admin starts an impersonation session, then clicks "Switch back": `tt_impersonation_log.end_reason` carries `manual`.
- The daily `ImpersonationCron` runs against an orphan session > 24h old: `end_reason` carries `expired`. Both are equality-comparable against `ImpersonationEndReason::MANUAL` / `EXPIRED`.

---

# TalentTrack v4.12.8 — Vocabulary constants for player + team (PR-set 4 of #988)

Fourth of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) in v4.12.3; PR-set 5 (reports + journey + scouting) in v4.12.5; PR-set 6 (tournament + match) in v4.12.6; PR-set 3 (PDP + trial) in v4.12.7; this ship — landing as v4.12.8 — covers the player-side roster vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/PlayerStatus.php` (new) — five constants for the lifecycle values stored in `tt_players.status`: `ACTIVE`, `TRIAL`, `INACTIVE`, `RELEASED`, `GRADUATED`. Mirrors the PR-set 1 / 2 / 5 file shape (`const ALL` + static `isValid()`). The five values are the canonical set per `JourneyEventSubscriber::emitStatusTransition()`, `LabelTranslator::playerStatus()`, the `PlayersPage` status dropdown, and the trials / workflow forms that write the column. Lifecycle vs archive: the `archived_at` column from migration 0010 is the soft-delete / bulk-archive marker (NULL vs timestamp); `status` is the orthogonal lifecycle marker, so archived players still carry one of the five values. Migration 0061 already back-filled legacy `status='deleted'` rows from v3.89.1-and-earlier delete paths back to `'active'` (with `archived_at` populated), so the five-value vocabulary is the only stored set on every install. `GRADUATED` is intentionally part of `ALL` even though `PlayersPage`'s status dropdown currently exposes only four of the five values — the `JourneyEventSubscriber` already emits a `graduated` journey event when the column flips to that value, so the vocabulary documents the canonical five-state set; surfacing the fifth dropdown option is a separate UX task.
- `src/Domain/Vocabularies/Lookups/PreferredFoot.php` (new) — three lowercase constants for `tt_players.preferred_foot`: `LEFT`, `RIGHT`, `BOTH`. Backs the `foot_option` lookup (operator-editable, seeded by migration 0001 with TitleCase display labels), but the stored player-record value is the lowercase key per `RosterDetailsStep::validate()`'s `sanitize_key()` + allowlist. The empty-string sentinel ("not specified") is intentionally not part of `ALL` — it represents the absence of one of the three options. Chemistry / compatibility engines that compare against `'left'` / `'right'` slot sides are NOT consumers of this vocabulary — those are `position_side_preference` / `slot_side` comparisons (a different left / right / center vocabulary) and stay out of scope for this PR-set.

**PHP - literal -> constant replacements**

- `src/Modules/Players/Admin/PlayersPage.php` — replaces the four literals in the `$status_options` map (`'active'` / `'inactive'` / `'trial'` / `'released'`), the `selected( $player->status ?? 'active', ... )` default, the `handle_save` `$_POST` fallback, and the `stub` row creation with `PlayerStatus::ACTIVE / INACTIVE / TRIAL / RELEASED` constants. SQL string literal `WHERE pl.status='active'` in `render_list()` is kept as a literal per the spec (DB is the source of truth).
- `src/Modules/Players/PlayerCsvImporter.php` — `status` default on row sanitisation: `'active'` → `PlayerStatus::ACTIVE`.
- `src/Shared/Frontend/FrontendPlayerDetailView.php` — trial-player gate on the trials tab empty state: `(string) $player->status === 'trial'` → `=== PlayerStatus::TRIAL`.
- `src/Shared/Frontend/FrontendTrialsManageView.php` — inline player-create on the trial-case create form + the status flip on the existing player: both `'trial'` literals → `PlayerStatus::TRIAL`.
- `src/Infrastructure/Journey/JourneyEventSubscriber.php` — the three-arm `emitStatusTransition()` match — status comparisons swap to `PlayerStatus::*` constants. Pairs cleanly with PR-set 5's `JourneyEventType::*` swap on the `EventEmitter::emit()` emit-arg side: this PR-set replaces the `$new === 'released'` LHS comparisons; PR-set 5 already replaced the `'released'` second-positional emit arg with `JourneyEventType::RELEASED`. Result is a fully-typed branch with no raw literals on either side of the assignment.
- `src/Infrastructure/Query/LabelTranslator.php` — `playerStatus()` switch cases swap to `PlayerStatus::*` constants. Adds a `case PlayerStatus::GRADUATED` arm for symmetry (missing previously). The legacy `case 'deleted'` arm is preserved as a literal — it's a historical-display safety net for migration-0061-pre installs that may still surface a value not in the canonical five-state set.
- `src/Modules/Tournaments/Wizard/SquadStep.php` — trial-badge gate on the squad picker: `$pl->status === 'trial'` → `=== PlayerStatus::TRIAL`.
- `src/Modules/Wizards/Player/ReviewStep.php` — status assignment on wizard submit: `$path === 'trial' ? 'trial' : 'active'` → `? PlayerStatus::TRIAL : PlayerStatus::ACTIVE`.
- `src/Modules/Wizards/Player/RosterDetailsStep.php` — preferred-foot allowlist in `validate()`: `[ '', 'left', 'right', 'both' ]` → `[ '', PreferredFoot::LEFT, PreferredFoot::RIGHT, PreferredFoot::BOTH ]`.
- `src/Modules/Workflow/Forms/RecordTestTrainingOutcomeForm.php` — the new-player insert on prospect-admission: `'status' => 'trial'` → `PlayerStatus::TRIAL`.
- `src/Modules/Workflow/Forms/AwaitTeamOfferDecisionForm.php` — the accepted-offer update: `[ 'status' => 'active' ]` → `[ 'status' => PlayerStatus::ACTIVE ]`.
- `src/Modules/DemoData/Generators/PlayerGenerator.php` — the seeded player insert + the `tt_player_created` hook payload: both `'status' => 'active'` → `PlayerStatus::ACTIVE`.

**Out of scope for this PR-set**

- `PlayerValue` / `AgeGroup` / `Position` — empirical grep on the codebase surfaced zero PHP-side string-literal comparisons against the eight player-value keys (the 0031 PDP-cycle seed), the U7-U23 / Senior age-group codes (the 0001 + 0051 seeds), or the 11 position abbreviations (the 0001 seed). The values live in `tt_lookups` and are read-only on the operator-facing surface; a constants class would document them without making any literal-to-constant swap. Deferred to a future PR-set if call sites surface — the issue's "every value" rule is satisfied at the call-site replacement layer, not by ahead-of-need declaration.
- `TeamLevel` / `AgeGroupCode` — `tt_teams` has no level / tier column (squad tier sits on `tt_team_blueprint_assignments.tier` per migration 0072, scoped for PR-set 7's `BlueprintTier` enum); the `age_group` column on `tt_teams` is VARCHAR but no equality comparisons surfaced in code.
- `PlayerOnePagerPdfExporter::statusLabel()` — has a defensive 6-value map (`active` / `archived` / `trial` / `released` / `contracted` / `inactive`) for display fallback against historical / drifted values; left as literals because the map intentionally accepts values outside the canonical five-state set and acts as a defensive translation surface, not a vocabulary contract.
- SQL string literals, `tt_lookups` seed values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 4 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach creates a new player via the admin form: stored with `status=active`. Status dropdown lists Active / Inactive / Trial / Released — unchanged from previous behaviour.
- Coach edits an existing trial player to `status=active` (signing flow): `JourneyEventSubscriber::emitStatusTransition()` writes a `signed` journey event via `EventEmitter::emit()` exactly as before.
- Coach edits a player to `status=released` or `status=graduated`: corresponding journey events fire.
- Player-create wizard, roster path: `status=active`. Trial path: `status=trial`. Preferred-foot dropdown accepts `left` / `right` / `both` and persists the lowercase key.
- CSV bulk import without a `status` column: defaults to `active`.
- Frontend trial-case create with inline new-player: new `tt_players` row carries `status=trial`; the trial case ties to it. Existing-player promotion flips the row to `trial`.
- Tournament wizard squad step: trial players surface with the Trial badge, unchecked by default.
- Workflow form "Record test-training outcome" (prospect admitted): new `tt_players` row carries `status=trial`.
- Workflow form "Await team offer decision" (accepted): existing player row flips to `status=active`.
- Demo-data seed run: every generated player carries `status=active` and the `tt_player_created` hook payload reflects the same.
- LabelTranslator round-trip: `playerStatus('graduated')` returns "Graduated" (previously fell through to `humanise()`); other arms unchanged.

---

# TalentTrack v4.12.7 — Vocabulary constants for PDP + trial (PR-set 3 of #988)

Third of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) shipped in v4.12.3; this ship covers the PDP-cycle and trial-case vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/PdpStatus.php` (new) — three lowercase constants for `tt_pdp_files.status`: `OPEN`, `COMPLETED`, `ARCHIVED`. Mirrors the PR-set 1 / 2 file shape (`const ALL` + static `isValid()`). The column is VARCHAR(20) with `DEFAULT 'open'` per migration 0031; `PdpFilesRepository::setStatus()` is the gate that rejects any value outside the three.
- `src/Domain/Vocabularies/Lookups/PdpVerdictDecision.php` (new) — four constants for `tt_pdp_verdicts.decision`: `PROMOTE`, `RETAIN`, `RELEASE`, `TRANSFER`. Backs the `pdp_verdict_decision` lookup seeded by migration 0112 with per-locale translations through `tt_translations`. `PdpVerdictsRepository::upsertForFile()` is the gate.
- `src/Domain/Vocabularies/Lookups/TrialCaseStatus.php` (new) — four constants for `tt_trial_cases.status`: `OPEN`, `EXTENDED`, `DECIDED`, `ARCHIVED`. Backs the `trial_case_status` lookup seeded by migration 0116.
- `src/Domain/Vocabularies/Lookups/TrialCaseDecision.php` (new) — six constants for `tt_trial_cases.decision`: `ADMIT`, `DENY_FINAL`, `DENY_ENCOURAGEMENT`, `OFFERED_TEAM_POSITION`, `DECLINED_OFFERED_POSITION`, `CONTINUE_IN_TRIAL_GROUP`. Backs the `trial_case_decision` lookup seeded by migration 0116. The three rolling-membership decisions (#0081 child 4) sit alongside the classic admit / decline triad — single vocabulary, one canonical list.

**PHP - literal -> constant replacements**

- `src/Modules/Pdp/Repositories/PdpFilesRepository.php` — insert default for new files moves from `'open'` to `PdpStatus::OPEN`; the `setStatus()` allowlist `in_array( $status, [ 'open', 'completed', 'archived' ], true )` becomes `PdpStatus::isValid( $status )`.
- `src/Modules/Pdp/Repositories/PdpVerdictsRepository.php` — drops the private `ALLOWED_DECISIONS` literal array; the `upsertForFile()` gate switches to `PdpVerdictDecision::isValid()`. The `label()` switch cases reference `PdpVerdictDecision::*` constants.
- `src/Modules/Pdp/Rest/PdpVerdictsRestController.php` — drops the private `ALLOWED_DECISIONS` literal array; the PUT-handler validation switches to `PdpVerdictDecision::isValid()`; the error payload's `allowed` key uses `PdpVerdictDecision::ALL`.
- `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` — the list-filter `$status_options` keys, the verdict-form `$decisions` keys, and the private `statusLabel()` switch cases all reference the new constants.
- `src/Modules/Pdp/Frontend/FrontendMyPdpView.php` — the read-only verdict `decisionLabel()` switch cases reference `PdpVerdictDecision::*`.
- `src/Modules/Trials/Repositories/TrialCasesRepository.php` — the `STATUS_*` and `DECISION_*` class constants now alias `TrialCaseStatus::*` and `TrialCaseDecision::*` rather than carrying duplicate raw strings. Backward compatible: every existing internal caller compiles and produces the same stored value. The `recordDecision()` allowlist switches from the self-constant triad to the `TrialCaseDecision::ADMIT|DENY_FINAL|DENY_ENCOURAGEMENT` triad; the status / decision label switches reference the new constants directly.
- `src/Infrastructure/Journey/JourneyEventSubscriber.php` — the post-trial-decision branches (signed / released journey events) switch from `'admit'` / `'deny_final'` literals to `TrialCaseDecision::ADMIT` / `TrialCaseDecision::DENY_FINAL`.
- `src/Modules/Trials/TrialGroupTeam.php` — the two `wpdb->prepare()` bindings for the trial-group active-member queries switch from the `'continue_in_trial_group'` literal to `TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP`.
- `src/Modules/PersonaDashboard/Kpis/TrialGroupActiveCount.php` — the KPI's active-trial-group-member query binding switches to `TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP`.
- `src/Modules/Workflow/Templates/ReviewTrialGroupMembershipTemplate.php` — the chain-step gate for the `continue_in_trial_group` branch switches to `TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP`.

**Out of scope for this PR-set**

- SQL string literals (`status IN ('open','extended')` in `TrialCasesRepository::findOpenForPlayer` and `listEndingBetween`, `status NOT IN ('completed','archived')` in `SeasonCarryover::copyOpenGoals`) stay as literals — DB is the source of truth, not the PHP layer.
- Form-internal radio-button values in `ReviewTrialGroupMembershipForm` (`offer_team_position`, `decline_final`) stay as form-input literals — they're transient HTML radio values mapped to canonical `TrialCaseDecision::*` values inside `serializeResponse()`, not themselves stored. Replacing them would conflate two vocabularies.
- The local `pdpFileStatusLabel()` switch in `PdpPrintRouter` translates an `'open'`/`'closed'` enum that is separate from the `tt_pdp_files.status` vocabulary — kept local per the existing comment.
- `LookupCanonicalSeeds.php` has stale / drift-prone entries for `pdp_verdict_decision` and `trial_case_status` ("On track / Behind / Ahead / At risk / Released" and "Open / In progress / Decision pending / Accepted / Rejected") that don't match the canonical pools. That's a #987 cleanup item, out of scope for #988.
- Migrations, `tt_lookups` seed values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 3 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach opens the PDP manage list at `?tt_view=pdp`: the status filter dropdown still shows Open / Completed / Archived; selecting one filters the file list as before.
- Coach opens a PDP file: the verdict-form dropdown still offers the four `promote` / `retain` / `release` / `transfer` decisions with the academy-progression labels; submitting still upserts the verdict.
- Coach records a trial decision via `TrialCasesRepository::recordDecision()` with `admit` / `deny_final` / `deny_encouragement`: stored as before; the journey subscriber emits the signed / released events on `admit` / `deny_final`.
- HoD landing's "Players in trial group" KPI counts trial cases with `decision = 'continue_in_trial_group'` (byte-identical to prior).
- ReviewTrialGroupMembershipTemplate chain-step gates the re-spawn on `decision === 'continue_in_trial_group'` (byte-identical to prior).
- Player / parent opens the read-only PDP at `?tt_view=my-pdp`: the verdict-decision label resolves through `PdpVerdictDecision::*` or the operator-edited `tt_translations` value, identical to prior behaviour.

---

# TalentTrack v4.12.4 — Match prep widen + landscape A4 print + save-indicator + in-place print button (closes #998)

Four bundled UX defects on the head-coach match-preparation surface (`?tt_view=match-prep&activity_id=<id>`), shipping together as one patch because they sit on the same three files.

## What ships

**(1) Widen on-screen** — `.tt-dashboard:has(.tt-match-prep)` lifts the wrapper max-width from 1100px to 1320px on the match-prep route only; every other dashboard view stays at 1100px. Desktop grid columns widen from `12.5rem | 1fr | 20rem` to `14rem | 1fr | 22rem`. Mobile and tablet breakpoints untouched.

**(2) Landscape A4 print CSS** — new `@page { size: A4 landscape; margin: 8mm }` plus an `@media print` block that drops the dashboard chrome (`.tt-breadcrumbs`, `.tt-back-link-wrap`, page-head actions, `.tt-mp-toolbar`) and every overlay (`.tt-mp-picker(-backdrop)?`, `.tt-mp-drawer(-backdrop)?`) so only the spreadsheet renders on paper. Selectors verified against the live markup rather than guessed. Forces the 3-column grid on regardless of print viewport width. Pitch tints, panel-head shading, and "on pitch" green cells preserved via `print-color-adjust: exact`. `break-inside: avoid` on each player row, goal box, and set-piece row prevents page-break splits.

**(3) Save-indicator layout shift** — `.tt-mp-save-state` gains `min-height: 1.4em`, `min-width: 12ch`, `display: inline-flex` so its bounding box stays stable while the textContent toggles between dirty / saving / saved / empty. Pure CSS defence; the JS textContent flip is unchanged.

**(4) Print button** — replaces the toolbar's `<a href="?tt_view=exports&exporter=match_prep_pdf&...">PDF (landscape A4)</a>` with a `<button type="button" data-tt-mp-print>Print (landscape A4)</button>` plus a one-line `window.print()` handler in `frontend-match-prep.js`. The `$pdf_url = add_query_arg([...])` block in `FrontendMatchPrepView::render()` is removed. The browser's "Save as PDF" within the print dialog handles file-output for free. The exports page's match-prep PDF exporter route stays available for direct visits to `?tt_view=exports`. Dutch string `Afdrukken (liggend A4)`.

## Files touched

- `assets/css/frontend-match-prep.css` — wrapper widening, grid column widths, save-state stability, print block.
- `assets/js/frontend-match-prep.js` — `data-tt-mp-print` click handler.
- `src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php` — PDF anchor → Print button; drop unused `$pdf_url`.
- `.local-mockups/match-preparation/index.html` — mirror the changes (mockup is design-of-record).
- `languages/talenttrack-nl_NL.po` — add `Print (landscape A4)` → `Afdrukken (liggend A4)`.
- `languages/talenttrack.pot` — add the same `msgid`.
- `docs/match-prep.md` + `docs/nl_NL/match-prep.md` — rewrite "Print to PDF" section to describe browser-print flow.
- `talenttrack.php` + `readme.txt` — version bump to 4.12.4, changelog stanza.

No schema, no REST, no behavioural change beyond the four items above.

---

# TalentTrack v4.12.3 — Vocabulary constants for goals + tasks (PR-set 2 of #988)

Second of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; this ship covers the goal-side workflow vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/GoalStatus.php` (new) — six lowercase snake_case constants for `tt_goals.status`: `PENDING`, `PENDING_APPROVAL`, `IN_PROGRESS`, `COMPLETED`, `ON_HOLD`, `CANCELLED`. Mirrors the PR-set 1 file shape (`const ALL` + static `isValid()`). The lowercase snake_case form is the canonical stored value per `LabelTranslator::goalStatus()` and the REST controller's defaults; the `goal_status` lookup row `name` column carries the TitleCase display label, but the table is the operator-facing surface and unaffected here.
- `src/Domain/Vocabularies/Lookups/GoalPriority.php` (new) — three lowercase constants for `tt_goals.priority`: `LOW`, `MEDIUM`, `HIGH`.
- `src/Domain/Vocabularies/Lookups/GoalApprovalDecision.php` (new) — three constants for the approval-form decisions stored in `tt_workflow_tasks.response_json`: `APPROVE`, `AMEND`, `REJECT`. Backs the `goal_approval_decision` lookup seeded by migration 0111.

**PHP - literal -> constant replacements**

- `src/Infrastructure/REST/GoalsRestController.php` — replaces the five raw `'pending_approval'` / `'pending'` literals (default status on create, force-approve gate for player-self-create, status update authorization check) and the `'medium'` priority default with the new `GoalStatus::*` / `GoalPriority::*` constants. REST endpoint payload-side behaviour is unchanged; the stored values are byte-identical to the previous release.
- `src/Modules/Goals/Admin/GoalsPage.php` — replaces the `'pending'` and `'medium'` form-default literals (status / priority dropdown `selected()` calls + the `handle_save` `$_POST` fallback) with the new constants.
- `src/Modules/Development/Notifications/GoalSpawner.php` — the idea-promotion goal materialisation hands `'pending'` / `'medium'` to `wpdb::insert(tt_goals)`; switched to the constants.
- `src/Modules/Workflow/Forms/GoalApprovalForm.php` — `DECISION_APPROVE` / `DECISION_AMEND` / `DECISION_REJECT` class constants now alias `GoalApprovalDecision::APPROVE` / `::AMEND` / `::REJECT` rather than carrying duplicate raw strings. Backward compatible: every existing internal caller continues to compile and produce the same stored decision value. The aliases stay one release before the umbrella's PR-set 8 PHPStan rule lands.

**Out of scope for this PR-set**

- `TT\Modules\Workflow\TaskStatus` already follows the constants-shaped pattern from the original v3.x ship; it carries the canonical six values (`open`, `in_progress`, `completed`, `overdue`, `skipped`, `cancelled`) plus helpers `isActionable()` and `label()`. Consolidating it into `Vocabularies\Lookups\TaskStatus` is a mechanical lift but pulls in two more touch points (`TasksRepository`, `FrontendMyTasksView`, `FrontendTaskDetailView`); deferred to keep this PR-set focused on the *new* constants classes. The existing class continues to be the source of truth for the task-status vocabulary in the meantime.
- SQL string literals, `tt_lookups` seed values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 2 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach creates a goal via the goals admin: defaults to `priority=medium`, `status=pending`. (Both stored as the lowercase form, unchanged from previous behaviour.)
- Player creates a goal via the player-self-create flow: stored with `status=pending_approval` regardless of payload override.
- Coach approves a pending-approval goal via the inline status dropdown: head-coach-only gate fires; status moves to `pending`.
- Coach uses the workflow goal-approval form: each `approve` / `amend` / `reject` decision serializes to the same byte value as before.
- Idea promoted to in-progress: spawns a `tt_goals` row with `status=pending`, `priority=medium`.

