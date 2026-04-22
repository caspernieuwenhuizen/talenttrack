# Ideas

This folder is where rough ideas live before they become specs. Dump any thought here in one markdown file per idea, however unfinished. First # heading is the working title. Rest is freeform.

## Filename format

  NNNN-<type>-<slug>.md

Examples:

  0008-bug-calendar-timezone-off.md
  0009-feat-bulk-csv-export.md
  0010-epic-billing-module.md

- **NNNN** — 4-digit zero-padded global ID. Assign the next unused number (highest existing + 1). IDs are permanent. Never renumber.
- **type** — one of: `feat`, `bug`, `epic`, `question`, `needs-triage`. Matches the `<!-- type: ... -->` marker inside the file.
- **slug** — short, kebab-case, descriptive. Drop the type word from the slug (use `0010-epic-billing-module.md`, not `0010-epic-billing-module-epic.md`).

You can reference an idea as `#0007` in chat and Claude Code will find it.

## Type marker inside the file

Each idea file should start with a single frontmatter-like line indicating its type. The filename segment and this marker must match.

  <!-- type: feat -->            standard spec-able feature
  <!-- type: bug -->             a bug that needs investigation
  <!-- type: epic -->            too big for one sprint, will need decomposition
  <!-- type: question -->        not an idea, a question to self
  <!-- type: needs-triage -->    unclear, more info needed before shaping

If the type changes during triage (e.g. `needs-triage` → `bug`), rename the file to match (ID stays the same) and update the marker.

## Lifecycle

1. Create idea at `ideas/NNNN-<type>-<slug>.md`
2. When ready to shape: "help me turn #NNNN into a proper spec — ask me whatever you need"
3. Claude Code moves the file to `specs/NNNN-<type>-<slug>.md` (ID preserved) and optionally opens a GitHub issue
4. Later: "implement #NNNN" or "implement specs/NNNN-..."

Keep this folder flat — no sub-folders. When an idea is shaped, its file moves out of `ideas/` entirely (to `specs/`), so the top level of `ideas/` is always the live backlog.
