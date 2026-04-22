# Ideas

This folder is where rough ideas live before they become specs. Dump any thought here in one markdown file per idea, however unfinished. Filename is a slug (e.g. frontend-bulk-csv-export.md). First # heading is the working title. Rest is freeform.

When an idea is ready to become an actionable spec:
1. Open a Claude Code session at repo root
2. Ask: "help me turn ideas/<slug>.md into a proper spec — ask me whatever you need"
3. Iterate until the spec is clear (problem, proposal, scope, out-of-scope, acceptance criteria)
4. Claude Code moves the file to specs/<slug>.md and opens a GitHub issue labeled ready-for-dev
5. Later session: "implement specs/<slug>.md"

## Labels

Each idea file should start with a single frontmatter-like line indicating its type:

  <!-- type: feature -->         standard spec-able feature
  <!-- type: bug -->             a bug that needs investigation
  <!-- type: epic -->            too big for one sprint, will need decomposition
  <!-- type: question -->        not an idea, a question to self
  <!-- type: needs-triage -->    unclear, more info needed before shaping

Keep this folder flat. Don't create sub-folders. A growing list of ~50 markdown files is fine; when it stops being manageable we'll reconsider.
