---
title: Knowledge library
group: development
summary: Courses for coach development, shipped as markdown and read in the app.
audience: [admin, dev]
views: [knowledge, course, lesson, my-learning, submission-review]
module: TT\Modules\Knowledge\KnowledgeModule
feature: knowledge_courses
tier: standard
order: 75
---

# Knowledge library

The knowledge library holds courses for coach development. A course ships with
the plugin as markdown, is read in the app, tracks progress, gates lessons and
— once the last wave of
[epic #2641](https://github.com/caspernieuwenhuizen/talenttrack/issues/2641)
lands — rolls completion up per team.

This page documents the whole feature: what a course is on disk, how the reader
gates it, how a lesson is checked, and how a practical assignment is handed in
and reviewed.

## What ships today

Everything except the statistics roll-up:

- the corpus format, the parsers and the registry
- the ten interactive blocks
- enrolment, progress and completion
- the four gates — module, feature, licence tier, capability — plus sequential
 unlock and course prerequisites
- the reader: library, course, lesson and *My learning*
- per-lesson quizzes, scored server-side
- practical assignments, with a submission flow and a reviewer's queue

The first course — *Periodiseren in voetbaltaal*, a Dutch trainerscursus on
football periodisation — is in the tree and registers.

## The corpus

```
courses/
  voetbalperiodisering/
    course.md                     manifest — front matter only
    01-voetbaltaal.md             lesson
    02-vier-kenmerken.md
    quizzes/
      01-voetbaltaal.json         quiz payload for that lesson
  nl_NL/
    voetbalperiodisering/         locale twin, same shape
      course.md
      01-voetbaltaal.md
```

A course registers by existing. `CourseRegistry` is a projection of the
folder, not a list beside it — drop a folder in with a valid `course.md` and it
is a course; delete the folder and it is gone. There is no second place to
edit, and therefore no second place to forget.

A folder the registry cannot parse is skipped rather than fatal, so a
half-written course in a branch never breaks a reader's page. `check-courses.php`
is what turns that silence into a CI failure.

## Manifest keys

Front matter is parsed by `DocFrontMatter`, which handles scalars and inline
lists and nothing else. It is deliberately not a YAML implementation; if a
course needs a richer structure, the answer is fewer keys, not a bigger parser.

| Key | Required | Meaning |
| --- | --- | --- |
| `title` | yes | Shown in the library index and as the course heading |
| `lessons` | yes | Lesson slugs **in teaching order** |
| `summary` | lint | One sentence under the title in the index |
| `source_lang` | no | Locale the canonical files are written in |
| `audience` | no | Personas the course is written for |
| `capability` | no | Capability a viewer needs. Empty means not cap-gated |
| `feature` | no | Sub-feature key that switches the course off |
| `tier` | no | Licence tier. Defaults to `standard` |
| `requires` | no | Course slugs that must be completed first |
| `methodology_principles` | no | Topics this course teaches. Descriptive only — see below |
| `certification_name` | no | Name of the certificate completion issues. Defaults to the title |
| `valid_for_months` | no | How long that certificate stays valid. Absent means it never expires |
| `estimated_hours` | no | Study load |
| `sequential` | no | Whether lessons unlock in order. Defaults to `true` |

Two of these are less obvious than they look.

**`lessons` decides the order.** Not the filenames. A numbered filename is a
convenience for whoever opens the folder; retiring lesson 4 of ten should not
mean renaming six files and invalidating every stored `lesson_slug` in the
progress table.

**`source_lang` names the language the canonical files are written in.** The
docs corpus is English-first with translations under `docs/<locale>/`. The
first course is Dutch-first. Rather than ship an English shell nobody wrote, a
course declares its own source language, and the reader falls back to it — a
viewer whose locale has no translation gets the course in the language it was
written in, and the interface can say so.

## Lesson keys

| Key | Meaning |
| --- | --- |
| `title` | Required. A lesson without one does not register |
| `objectives` | Learning objectives, shown at the top of the lesson |
| `assignment` | `true` when completing the lesson needs an approved assignment |
| `quiz` | `true` when completing the lesson needs a passed quiz |
| `estimated_minutes` | Reading time |

`assignment` and `quiz` are declarations, not content. The assignment text
lives in the body as a `tt-assignment` block; the quiz payload lives in
`quizzes/<lesson>.json`. Keeping the declaration in front matter means the
course structure can be answered without parsing ten lesson bodies — which is
exactly what the library index needs.

Both default to `false`, so a lesson only ever opts in to a requirement.

## Interactive blocks

Markdown is the storage format, not the render. A lesson renders to HTML
through PHP, and interactive elements are fenced sections whose info string
names a renderer:

````markdown
```tt-zeropoint method="extensive_endurance"
```

```tt-callout type="warning"
Bij 7v7 is de berekende breedte te smal.
```
````

`BlockRegistry` maps the info string to a class implementing `BlockRenderer`.
Each renderer emits `.tt-*` markup and declares whether it needs the block
script, so a lesson made only of prose and callouts loads no JavaScript.

An info string nothing claims renders as a code block. A course written
against a newer release, opened on an older one, loses one widget rather than
the whole lesson.

| Block | Attributes | Interactive |
| --- | --- | --- |
| `tt-callout` | `type` — `objectives`, `key`, `warning`, `note` | no |
| `tt-reveal` | `question` | no |
| `tt-check` | `prompt`, `answer` | yes |
| `tt-actionline` | body rows: `label \| quality% \| seconds` | no |
| `tt-model` | — | no |
| `tt-pitchsize` | `format` | yes |
| `tt-zeropoint` | `method` | yes |
| `tt-weekplanner` | — | yes |
| `tt-loadmatrix` | `cycle`, `cycles` | yes |
| `tt-quiz` | — | yes |
| `tt-assignment` | `id` | yes |

Every block renders a usable state server-side. The script upgrades that
state; it never creates it. A reader with JavaScript blocked still gets the
pitch table, the model and the default load matrix.

### The inline check

```
```tt-check answer="B" prompt="Ajax speelde zaterdag. Kan 4v4 op dinsdag?"
- A. Ja, twee dagen is genoeg
- B. Nee, 4v4 vraagt 72 uur herstel
- C. Alleen als de wedstrijd kort was
> 4v4 is de meest intensieve vorm en vraagt drie dagen.
```
```

Options are `- A. text` list items; the explanation is the blockquote that
follows. Both are ordinary markdown, so a check reads as a question with an
answer even in a raw diff — which is the point of keeping the corpus in
markdown at all.

**Why it exists next to `tt-quiz` and `tt-reveal`.** The quiz sits at the end
of the lesson, which is the wrong place to discover you misread paragraph two.
`tt-reveal` sits in the right place but asks for nothing — a `<details>` opens
whether or not the reader thought about it. Committing to an answer *before*
seeing the right one is the mechanism that makes retrieval practice work, and
only `tt-check` makes the reader commit.

**Scored in the browser, and that is not a hole in the quiz's rule.**
`tt-quiz` keeps its answer key server-side because a score is at stake: it
gates the next lesson and lands in a coach's development record. A check
records nothing, unlocks nothing and appears in no report, so there is no
result to protect — and what it needs instead is a verdict with no network
round trip. A reader who digs the answer out of the DOM has skipped an
exercise they could have skipped by scrolling. The graded quiz is unaffected.

**Without JavaScript** the options are radio inputs inside a `<details>`: pick
one, open the disclosure, read the answer. That is the `tt-reveal` behaviour,
which is the right degradation. The script upgrades it to an instant verdict
and locks the options — answering is one-way, because retrying until it goes
green turns retrieval practice into guessing.

`course-lint` fails a check with no prompt, fewer than two options, no answer,
an answer naming an option that does not exist, or no explanation. The answer
is compared through `CheckBlock::inspect()` rather than a second parser in the
gate, so the lint and the renderer cannot drift.

### One source for the numbers

Supercompensation times, the overload step tables, the pitch-size rule and
the session types all live in `Periodisation`. `tt-zeropoint`,
`tt-weekplanner` and `tt-pitchsize` read them, and the script gets the same
values through `wp_localize_script`.

This matters more than it looks: a course that teaches "4v4 needs 72 hours"
alongside a planner that warns at 48 would be worse than either alone. When
the Training module needs these numbers, it reads them from here.

The step tables are written out rather than generated. They are not a
rectangular grid — after 2 × 15 the next step is 3 × 11, not 3 × 10 — and a
generated grid shifts every step number from the seventh on.

### Adding a block

Implement `BlockRenderer`, then either add the class to `BlockRegistry::all()`
or hook `tt_knowledge_blocks`. Escape everything interpolated: the corpus is
plugin-shipped, but translated courses are edited by people who are not
reviewing PHP.

## Quiz payload

```json
{
  "pass_mark": 4,
  "questions": [
    {
      "id": "q1",
      "type": "single",
      "prompt": "How many hours of supercompensation does 4v4/3v3 need?",
      "options": ["24", "48", "72", "96"],
      "answer": 2,
      "explanation": "..."
    }
  ]
}
```

Question types: `single` (one index), `multiple` (array of indices), `order`
(every option index in the correct sequence), `match` (a `pairs` array, plus
one option index per pair, in pair order).

Answers are stored as option indices. The scoring runs server-side when the
reader lands in
[#2647](https://github.com/caspernieuwenhuizen/talenttrack/issues/2647) — the
answer key must never reach the browser.

## Enrolment and progress

Courses are files; a person's relationship to a course is data. Migration
0225 adds four tables.

| Table | Holds |
| --- | --- |
| `tt_course_enrolments` | one person on one course — status, due date, started, completed. Root entity, carries `uuid` |
| `tt_course_progress` | one row per lesson: read, quiz passed, assignment approved, plus `tool_state` |
| `tt_course_quiz_attempts` | every attempt, not just the last |
| `tt_course_submissions` | an assignment and its review verdict. Root entity, carries `uuid` |

`course_slug` and `lesson_slug` are strings with no table behind them. A slug
that stops resolving is a course withdrawn in a later release; those rows are
shown as **retired**, never deleted, because a coach's completion history has
to outlive the course that produced it.

Assignment attachments are not in `tt_course_submissions`. They ride
`tt_media_links` with `entity_type = 'course_submission'`, so a photo of a
whiteboard plan goes through the same private store and lifecycle as every
other file.

### What counts as complete

`CourseCompletionService` owns the rule, and is the only place that does —
the reader, the gate and the statistics report all ask it
rather than deciding for themselves.

A **lesson** is complete when every requirement its front matter declares is
met: read it always, pass the quiz when `quiz: true`, get the assignment
approved when `assignment: true`. A **course** is complete when every lesson
its manifest declares is complete.

Requirements are read from the corpus on every call, never cached against the
enrolment. A course revision that adds a lesson reopens the people who had
finished the old version, rather than leaving them certified for a course they
have not done. `percent` floors rather than rounds, so nine of ten lessons
reads 90%.

Two hooks fire on the transition, once each:

| Hook | Fires when |
| --- | --- |
| `tt_knowledge_course_completed` | an enrolment reaches completion |
| `tt_knowledge_course_reopened` | a completed enrolment no longer is |

The certification bridge and the methodology binding hang off the
first, so the completion service does not have to know what happens next.

## The reader

Four routable surfaces, all owned by the `knowledge_courses` feature — so
switching the feature off takes the routes down as well as the tiles, and a
bookmarked lesson URL stops resolving rather than rendering a surface the
academy turned off.

| Slug | Surface |
| --- | --- |
| `knowledge` | Library index. Tile in the **Learning** group |
| `course` | One course: state, progress, lesson list. `?slug=` |
| `lesson` | The reader. `?slug=&lesson=` |
| `my-learning` | One person's own record. Tile in the **Me** group |

### Navigation (CLAUDE.md §5)

The chain is `Dashboard › Knowledge library › Course › Lesson`. The course
crumb is the way back up to the course and the library crumb is the way back
to the library — there is no separate back button, because that would be the
third affordance §5a forbids.

`RecordSpine` pins the course identity on the course and lesson views, so a
coach eleven lessons into a long course never has to scroll up to remember
which one they are in. **No tabs**: a course has one view, not alternative
views of one record, and using the spine's tab slot for the sake of it would
be decoration.

Every link between these surfaces goes through `CrossViewLink::render()` with
a gate registered in `CoreSurfaceRegistration`, so a link to a view the
reader cannot open is not rendered at all (§7 / #2304). `KnowledgeLinks`
builds the hrefs and always attaches the `tt_back` hint; it deliberately does
not decide visibility.

### Resume, and marking a lesson read

Opening a course goes to the **first incomplete lesson**, not lesson one.
Opening a lesson enrols the reader on first touch — a separate enrol step
before you can read lesson one is a step nobody would understand.

Marking a lesson read is an **explicit control**, never a scroll heuristic. A
coach who skims and clicks it has made a claim; a scroll listener has only
measured a thumb. It is a real form POST, so the lesson completes with
JavaScript unavailable; `knowledge-reader.js` upgrades that to an in-place
save.

The end-of-lesson block also names what the lesson still wants — a passed
check, an approved assignment — because a coach who marks a lesson read and
sees the course percentage not move needs to know it is the quiz, not a bug.

### Interactive block state

`tt-zeropoint` and `tt-weekplanner` persist to
`tt_course_progress.tool_state` through
`PATCH /courses/{slug}/progress/{lesson}`, debounced. The zero-point
measurement a coach takes in module 4 is still there in module 11, where the
final assignment asks for it.

Rehydration is why `knowledge-reader.js` is enqueued as a *dependent* of
`knowledge-blocks.js`: it assigns `window.TTKnowledge.savedState` at parse
time, which is before either script boots on `DOMContentLoaded`, so the
blocks read their saved state during their own init rather than being
re-initialised afterwards. The first paint deliberately does not save —
rendering what was already stored is not a change, and writing it back would
touch the record on every page view.

### Quizzes

A lesson declaring `quiz: true` must also carry a `tt-quiz` block, or the
questions are scored by nothing and appear nowhere. The corpus lint fails a
PR for either half being missing — a rule added after #2647 found ten lessons
with valid payloads and no block.

**Scoring is server-side, and that is not a preference.** The payload carries
the answer key, so a client-side scorer would have to be handed the answers to
do its job. `QuizScorer` marks the submission; the browser only sends what was
filled in and renders what comes back.

**Options are shuffled per render.** Every `order` and `match` answer in the
shipped corpus is the identity permutation — the stored order *is* the correct
sequence — so rendering options as stored would hand the reader the answer to
every sequencing question in the course. `single` and `multiple` are shuffled
too, so a corpus author who habitually writes the right answer first cannot
leak a pattern across a course.

Because the shuffle happens per render, the browser cannot submit indices:
it would be naming positions the server has already forgotten. It submits
**option labels**, which the reader can already see, and the server maps them
back. No index and no answer key crosses the wire, and there is no per-render
state to keep. The corpus lint guarantees no two options in a question share a
label, which is what makes the mapping unambiguous.

| Type | Control | Submits |
| --- | --- | --- |
| `single` | radio group | one label |
| `multiple` | checkbox group | labels, order irrelevant |
| `order` | a number box per option | `label => position`, sorted server-side |
| `match` | a select per left-hand item | one label per pair, in pair order |

Ordering uses position boxes rather than drag-and-drop. Dragging is nicer with
a mouse and unusable with a keyboard, and §2 requires a non-gesture path
anyway — typing a position *is* that path rather than a fallback bolted beside
one.

**No partial credit.** A multi-part answer is either the answer or it is not;
half an ordering is not half an understanding of the sequence. A skipped
question is wrong rather than ignored, because a quiz where skipping cannot
hurt you is one a reader passes by answering only what they are sure of.

**Every attempt is recorded**, passed or not, in `tt_course_quiz_attempts`.
Retakes are unlimited. Passing writes `quiz_passed_at` on the lesson's
progress row, which is what the sequential gate reads.

Explanations come back for right answers as well as wrong ones: a coach who
guessed correctly learns as much from the reason as one who did not.

The quiz is a real `<form>`. With JavaScript unavailable it posts, is scored
by the same `QuizScorer`, and re-renders with the result above the lesson;
`knowledge-quiz.js` upgrades that to an in-place submission using the same
field names, so the two paths are the same payload by construction.

### Gating

Six gates, resolved in one pass. The first four are shared with the help
corpus and live in `TT\Shared\Content\ContentGate`; the last two are about
what the learner has done and live in `CourseAccessResolver`.

| Gate | Source | Verdict kind |
| --- | --- | --- |
| `module` | `ModuleRegistry::isEnabled()` | unavailable |
| `feature` | `FeatureRegistry::isEnabled()` | unavailable |
| `tier` | `LicenseGate::effectiveTier()` | unavailable |
| `capability` | `current_user_can()` / `user_can()` | denied |
| `requires:` | prerequisite course not completed | locked |
| `sequential:` | previous lesson not complete | locked |

The three kinds are not interchangeable. **Unavailable** means this install
does not have it and no permission changes that. **Denied** means it is here
and someone else can see it. **Locked** means you will be able to, once you
have done something first. Rendering the same message for all three is how a
product tells a head of academy to ask their administrator about a feature
their licence does not include.

Consequences:

- Unavailable and denied courses are **absent** from the library and return
 **404**, not 403 — a 403 confirms the course exists here, which is what
 hiding it was for.
- Locked courses and lessons stay **listed**, with their verdict. Hiding a
 locked lesson makes a course look shorter than it is.
- The gate is enforced on the **write path**, not only in the reader. Hiding
 a locked lesson means nothing if `PATCH …/progress/{lesson}` marks it read;
 that route returns 403 with the verdict attached.

Two conventions inherited deliberately from the registries:

**An absent key is not a gate.** Content with no `module:` is never
module-gated.

**An unknown key value leaves content visible.** A typo in
`feature: knowlege_courses` must not silently hide a course — that is a bug
found months later, if ever. The corpus lint is what catches the typo.

Nothing in the gate is cached: module and feature state are mutable at
runtime and capability is per-user, so a cached verdict would mean a module
toggle does not take effect until the next plugin update.

### Capabilities

| Capability | Grants |
| --- | --- |
| `tt_view_knowledge` | see the library, work through a course, see **your own** record |
| `tt_view_knowledge_statistics` | see everyone's progress |
| `tt_manage_knowledge` | assign, set due dates, withdraw, review submissions |

Three levels rather than the usual view/manage pair, because a coach must be
able to see their own progress without seeing their colleagues'. Folding the
roll-up into `tt_view_knowledge` would make hiding a column the only thing
standing between a coach and their peers' completion rates.

### REST

```
GET    /talenttrack/v1/courses                              catalogue + your state
GET    /talenttrack/v1/courses/{slug}                       manifest + per-lesson state
POST   /talenttrack/v1/courses/{slug}/enrolments            enrol self, or assign
PATCH  /talenttrack/v1/courses/{slug}/progress/{lesson}     mark read, persist tool state
POST   /talenttrack/v1/courses/{slug}/submissions/{lesson}  hand in an assignment
GET    /talenttrack/v1/submissions                          your review queue
PATCH  /talenttrack/v1/submissions/{id}                     record a verdict
DELETE /talenttrack/v1/enrolments/{id}                      withdraw
GET    /talenttrack/v1/people/{id}/learning                 one person's record
```

Marking a lesson read enrols the reader on first touch — a separate enrol step
before you can open lesson one is a step nobody would understand.

A verdict is a `PATCH` on the submission rather than an `/approve` verb: the
outcome is a field on a record, and modelling it as an action would need a
second endpoint the day "unapprove" is wanted.

## Assignments and review

Every module of the periodisation course ends in a *praktijkopdracht* the coach
runs with their own team. A quiz can establish that a coach knows 4v4 needs
seventy-two hours; only a mentor reading a submitted twelve-week plan can
establish that they built one. That is why completion needs a human, and why
the review queue exists.

### Handing in

The lesson's `tt-assignment` block renders the assignment and, below it, the
state of the coach's own work — nothing, awaiting review, changes requested, or
the final verdict. It is a plain `<form method="post">`, so handing work in
never depends on JavaScript.

There is also a guided path at
`?tt_view=wizard&tt_wizard=submit-assignment&slug={course}&lesson={lesson}`:
write → attach → confirm. Both end in the same `SubmissionService` call.

A resubmission is always a **new row**. The earlier attempt and the feedback on
it stay intact — that history is the record of the coaching, and overwriting it
would erase the reviewer's side of the conversation.

### The four states

| State | What the coach sees | Can they submit? |
| --- | --- | --- |
| nothing handed in | the assignment and a form | yes |
| awaiting review | what they wrote, and that it is with a reviewer | no |
| changes requested | the feedback, and the form again | yes |
| approved / rejected | the verdict | no |

A rejection does not reopen the form. Asking for changes is how a reviewer
invites another attempt; collapsing the two would leave no way to say no and
mean it.

### Who reviews

Mentorship first, capability second:

1. the learner's mentor from `tt_staff_mentorships`, if they have one
2. otherwise nobody — the submission stays unrouted and is visible to
 **every** holder of `tt_manage_knowledge`

Unrouted is a state, not a failure. Picking an arbitrary capability holder at
submit time would look tidier in the column and would quietly make one person
responsible for a queue nobody told them about.

The reviewer is resolved **when the work is handed in** and written onto the
row, never recomputed on read. A mentorship that ends mid-review must not make
a submission vanish from the queue of the person already reading it.

A holder of `tt_manage_knowledge` can rule on anything, including routed work —
somebody has to be able to clear the queue when a mentor is away, and the row
records who actually decided it.

Note that a mentor holds no management capability. Gating the queue on one
would hide it from exactly the people work is routed to, so the review surfaces
gate on `ReviewerResolver::isReviewer()` instead — tile, cross-view link, view
and REST route alike.

### Feedback is mandatory unless you are approving

Approving needs no justification. Asking for changes or turning work down does,
and an empty one is refused rather than stored — an outcome without a reason is
not review. Enforced in `SubmissionService`, so the form and the API agree.

### What approval does

Approval stamps `tt_course_progress.assignment_approved_at` and re-runs
`CourseCompletionService::recalculate()`. Withdrawing an approval clears it and
recalculates again, which can un-complete the course. Leaving the stamp in
place would leave a certificate standing on work a reviewer has since retracted.

### Attachments

Documents only — PDF, Word, spreadsheet, OpenDocument or plain text. No
photographs and no video.

This is a safeguarding boundary rather than a preference. Attachments ride the
media library (`tt_media_links` with `entity_type = 'course_submission'`), and
a submission hangs off no player and no team — so an image attached to one
would sit outside the consent, visibility and retention rules that govern
player media, and a photograph taken at a training can hold minors. The
assignments ask for written plans, so the narrow lane costs nothing.

`MediaAttachmentPolicy` owns the decision. The uploader reads it — which is why
it offers no camera here — and `create_media` re-checks the sniffed kind before
anything is written, because `accept` is a hint to a file picker and not a gate.

Who can open an attachment: the coach who handed it in, the reviewer it is
routed to, and holders of `tt_manage_knowledge`. That is the one branch in
`MediaVisibilityService` that is not a matrix scope, because a submission has
no team behind it to scope against.

### The review queue

`?tt_view=submission-review`, oldest first — a queue ordered newest-first
starves whoever handed in on a busy week. Each card shows the assignment text
from the corpus above the submission, so a reviewer reading "I measured 4v4 at
eleven minutes" knows what was asked.

### The alert

`knowledge.submission_awaiting_review` is a state-derived alert, not an event
mail. The queue is a *state*: an alert says what is waiting now and resolves
itself when the queue empties, where a "submission arrived" mail gives one
notification per submission and no way to tell what is still outstanding.

One occurrence per submission, so a five-deep queue reads as five things to do.
`info` on arrival, ageing to `attention` after a week.

## The CI gate

`tools/check-courses.php` runs on every PR touching `courses/`, the Knowledge
module or `DocFrontMatter`. It fails on:

- a course folder with no `course.md`, or a manifest that does not parse
- a missing `summary`
- a `tier` the License module does not define — the list is scraped from
 `FeatureMap`, not duplicated here
- a lesson named in `lessons:` with no file, or one that does not parse
- a lesson file on disk that `lessons:` does not name
- a `requires:` slug that no course provides, or a course requiring itself
- a lesson declaring `quiz: true` with no payload, invalid JSON, no questions,
 a `pass_mark` that is missing or higher than the question count, a duplicate
 question id, an unknown question type, fewer than two options, or an answer
 index out of range
- a `tt-check` with no prompt, fewer than two options, no answer, an answer
 naming an option that does not exist, or no explanation
- a translated lesson with no canonical counterpart

Run it locally with `php tools/check-courses.php`. It needs no WordPress and
requires the real parsers, so the gate cannot drift from the runtime it guards.

## Completion becomes a certification

Finishing a course writes a row to `tt_staff_certifications` — the table
StaffDevelopment already owns. That one row is what stops the library
being an island: the completion appears on the coach's staff record and their
PDP, the org-wide expiry roll-up picks it up, and the certificate-expiring
alert can nudge for a refresher, all without the knowledge module knowing any
of those surfaces exist.

It is also where CLAUDE.md §1 is satisfied rather than exempted. The player
question is the one `StaffCertificateExpiringAlert` already answers from the
other side: *every player in the squad needs the person running their training
to be qualified to run it.*

**Both transitions.** `tt_knowledge_course_completed` issues;
`tt_knowledge_course_reopened` archives. The second matters because #2648 made
approval reversible — a reviewer who withdraws an approval un-completes the
course, and a certificate left standing would be a qualification resting on
retracted work, sitting in the club's expiry roll-up. Archived rather than
deleted: a certificate issued and then withdrawn is a fact about the coaching.

**Idempotent.** `tt_course_enrolments.certification_id` remembers which row a
completion produced, so completing twice refreshes rather than duplicates, and
a complete → reopen → complete cycle reuses one row instead of leaving a trail
of archived duplicates on somebody's record.

**The lookup row.** Migration 0231 seeds the `cert_type` for the shipped
course, with its Dutch label in **`tt_translations`** — `tt_lookups.translations`
was dropped in migration 0087, so a seed that writes there loses the label
silently. Any other course resolves-or-creates its type at completion time, so
adding a course never needs a migration. `KnowledgeCertificationTest` asserts
the seeded name and the manifest's `certification_name` still match, because
a drift there creates a second untranslated row and orphans the seeded label
without anything failing.

### Is the staff around this squad trained?

`TeamCourseCoverage::forTeam( $team_id, $course_slug )` answers it in one
query, joining `tt_user_role_scopes` (staff assigned to the team) to
`tt_course_enrolments`. A `LEFT JOIN`, deliberately: somebody who never
started the course is the answer to the question, not a row to omit.

**This does not join on methodology, and the epic said it would.** Building it
showed why it cannot. `tt_principles` holds tactical game principles keyed
`AO-01`; `tt_methodologies` holds *playing* methodologies — formations,
principles, set pieces — and the shipped default is `jo14-1-hedel`. The
periodisation course teaches physical periodisation, which is a training
methodology and belongs to neither. Binding it to a playing methodology would
assert a relationship that is not there.

`methodology_principles` therefore stays descriptive: a statement of what the
course covers, read from the corpus when something wants to show it. It is not
copied into the database, because the corpus is versioned with the plugin and
a copy would only go stale.

## Assigning a course

`?tt_view=wizard&tt_wizard=assign-course` — course → people → deadline →
confirm. Requires `tt_manage_knowledge`.

The people step filters staff to the personas in the course's `audience`, and
falls back to the full staff list *with a visible explanation* when nobody
matches — which happens on any install where staff records exist but accounts
are not linked yet. An administrator who can see why the list is unfiltered
can act on it; one staring at an empty step can only guess.

Re-assigning is a no-op. `EnrolmentRepository::enrol()` is keyed on
`(club_id, course_slug, person_id)`, and the confirm step reports how many
people are actually new, so re-running the wizard over a squad shows "3 new,
12 already enrolled" rather than a silent success. An existing enrolment is
left exactly as it was, deadline included — changing somebody's deadline is a
different decision from assigning them a course.

## The statistics report

Three lenses in the Reports module, reached from the reports launcher
and dispatched through `?tt_view=standard-report&slug=learning-…`:

| Slug | Answers |
| --- | --- |
| `learning-courses` | Per course: enrolled, not started, in progress, completed, overdue, median days, and the lesson readers stop at |
| `learning-people` | Per person: courses, percent complete, overdue, awaiting review, last activity |
| `learning-teams` | Per team, per course: how much of the staff around that squad has finished |

Built as standard reports rather than as a page of their own, so they inherit
the launcher, the per-report toggle, the page head and the `.tt-rep-*`
stylesheet. Chrome lives in `FrontendStandardReportsView`; the tables live in
`Knowledge\Frontend\LearningReports`, which keeps a module's markup out of a
shared 1,500-line file while still using its surface.

All aggregation is `LearningStatisticsService` (§4) — one grouped query per
question, never one per row. The report and the REST endpoints therefore
cannot disagree about what "complete" means.

### Where learners stall

`dropOffFor()` finds the lesson with the largest fall in readers against the
one before it. It is the only figure on the report that says something about
the **course** rather than about the people taking it: a lesson half the
cohort stops at is usually badly written, badly placed, or asking for
something the reader cannot do yet. Completion percentages never surface
that, which is why it has its own column and a note under the table saying
how to read it.

### Three visibility levels

| Sees | Capability |
| --- | --- |
| Own record only | `tt_view_knowledge` |
| Everyone's | `tt_view_knowledge_statistics` |
| Assign and chase | `tt_manage_knowledge` |

Enforced in the REST `permission_callback`, not by hiding a column — a coach
who may see their own progress must not be able to read the academy's
completion rates by calling the endpoint the report calls. The CSV export is
gated on the same capability for the same reason.

A coach without the statistics capability is **shown their own record**, not
refused the page. Own-record access is a level, not an absence of one.

### REST

```
GET /talenttrack/v1/knowledge/statistics         every course + the per-person table
GET /talenttrack/v1/courses/{slug}/statistics    one course, its drop-off and team coverage
GET /talenttrack/v1/teams/{id}/learning          one team's staff, per course
```

`/teams/{id}/learning` takes an optional `course` to narrow to one; without it
every shipped course is reported, because "is my staff trained" is rarely
about a single course once a club runs several.

### Presentation

Status is a chip carrying a **word** as well as a hue — "3 overdue", "All
trained", "2 to go" — so the table survives a reader who cannot separate red
from green. Semantic colours only, kept off the module accent. A clean row
shows a muted zero rather than a green chip, so the row that needs chasing is
the one that shouts. Numeric columns are `tabular-nums`; tables scroll inside
`.tt-table-wrap` so the page body never scrolls sideways.

The export humanises on the way out: "Nobody has finished yet" rather
than a blank cell, lesson titles rather than slugs.

## Page width

The reader is a three-column grid. Prose sits in the middle track at a
readable measure (`--tt-lesson-measure`, 76ch); anything that is a figure
rather than a sentence spans all three:

- the four calculators, by their shared `.tt-lesson-tool` class
- tables, via `.tt-lesson-table-scroll`
- `.tt-lesson-actionline`, `.tt-lesson-model`, the quiz and the assignment
- anything else that opts in with `.tt-lesson-wide`

A course is worked through at a desk, and the tools are what the reader came
for. Capping a seven-day planner at a paperback column while 700px of window
sits unused beside it was the problem this solves; unclamping the prose as well
would have traded it for 140-character lines. Below the breakpoint `min()`
collapses the middle track to 100%, the outer tracks become zero, and mobile
renders exactly as before.

The objectives and completion panels that bracket the lesson body dropped their
cap entirely — a capped panel above and below a full-width body reads as an
indent nobody asked for.

## Switching it off

The module is registered in `config/modules.php` and can be disabled entirely.
The courses themselves sit behind the `knowledge_courses` sub-feature, so an
academy that runs its coach education elsewhere can switch off the curriculum
while the module stays available for other material.

## Related

- [Epic #2641](https://github.com/caspernieuwenhuizen/talenttrack/issues/2641)
 — the full plan and the wave breakdown
- `docs/contributing.md` — audience markers and the Dutch translation rule
