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
