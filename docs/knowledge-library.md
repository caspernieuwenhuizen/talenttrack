---
title: Knowledge library
group: development
summary: Courses for coach development, shipped as markdown and read in the app.
audience: [admin, dev]
module: TT\Modules\Knowledge\KnowledgeModule
feature: knowledge_courses
tier: standard
order: 75
---

# Knowledge library

The knowledge library holds courses for coach development. A course ships with
the plugin as markdown, is read in the app, and — once the later waves of
[epic #2641](https://github.com/caspernieuwenhuizen/talenttrack/issues/2641)
land — tracks progress, gates lessons and rolls completion up per team.

This page documents the corpus: what a course is on disk, and what the CI gate
checks. The reader, the schema and the statistics are documented separately as
they ship.

## What ships today

The content spine only: the corpus format, the parsers and the registry. There
is no reader view, no progress tracking and no gating yet. The first course —
*Periodiseren in voetbaltaal*, a Dutch trainerscursus on football
periodisation — is in the tree and registers.

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
| `methodology_principles` | no | Principles this course teaches |
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
| `tt-actionline` | body rows: `label \| quality% \| seconds` | no |
| `tt-model` | — | no |
| `tt-pitchsize` | `format` | yes |
| `tt-zeropoint` | `method` | yes |
| `tt-weekplanner` | — | yes |
| `tt-loadmatrix` | `cycle`, `cycles` | yes |
| `tt-quiz` | — | placeholder until #2647 |
| `tt-assignment` | `id` | placeholder until #2648 |

Every block renders a usable state server-side. The script upgrades that
state; it never creates it. A reader with JavaScript blocked still gets the
pitch table, the model and the default load matrix.

### One source for the numbers

Supercompensation times, the overload step tables, the pitch-size rule and
the session types all live in `Periodisation`. `tt-zeropoint`,
`tt-weekplanner` and `tt-pitchsize` read them, and the script gets the same
values through `wp_localize_script`.

This matters more than it looks: a course that teaches "4v4 needs 72 hours"
alongside a planner that warns at 48 would be worse than either alone. When
the Training module needs these numbers (#2493), it reads them from here.

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
the reader, the gate (#2645) and the statistics report (#2650) all ask it
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

The certification bridge and the methodology binding (#2649) hang off the
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
GET    /talenttrack/v1/courses                            catalogue + your state
GET    /talenttrack/v1/courses/{slug}                      manifest + per-lesson state
POST   /talenttrack/v1/courses/{slug}/enrolments           enrol self, or assign
PATCH  /talenttrack/v1/courses/{slug}/progress/{lesson}    mark read, persist tool state
DELETE /talenttrack/v1/enrolments/{id}                     withdraw
GET    /talenttrack/v1/people/{id}/learning                one person's record
```

Marking a lesson read enrols the reader on first touch — a separate enrol step
before you can open lesson one is a step nobody would understand.

Lesson bodies are deliberately not served yet. `/courses/{slug}/lessons/{lesson}`
arrives with the reader (#2646), once the gate (#2645) can decide whether a
given lesson is open; serving bodies before then would ship the unlocked
version of a sequential course.

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
- a translated lesson with no canonical counterpart

Run it locally with `php tools/check-courses.php`. It needs no WordPress and
requires the real parsers, so the gate cannot drift from the runtime it guards.

## Switching it off

The module is registered in `config/modules.php` and can be disabled entirely.
The courses themselves sit behind the `knowledge_courses` sub-feature, so an
academy that runs its coach education elsewhere can switch off the curriculum
while the module stays available for other material.

## Related

- [Epic #2641](https://github.com/caspernieuwenhuizen/talenttrack/issues/2641)
  — the full plan and the wave breakdown
- `docs/contributing.md` — audience markers and the Dutch translation rule
