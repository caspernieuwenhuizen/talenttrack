# Specs

This folder holds shaped specs — ideas that have been through a Claude Code shaping session and are ready to implement. Each file here should have a corresponding GitHub issue labeled `ready-for-dev`.

Filename mirrors the originating idea: `NNNN-<type>-<slug>.md`. The 4-digit ID is inherited from `ideas/` and stays stable across idea → spec → shipped.

Structure per spec file:

  # <title>
  
  ## Problem
  What hurts today. Who feels it.
  
  ## Proposal
  What we do about it. High-level approach.
  
  ## Scope
  What's in.

  ## Wizard plan
  Required per `CLAUDE.md` § 3 (wizard-first record creation). One of:
  - **Slug**: `<wizard-slug>` — new wizard. Briefly describe its steps.
  - **Existing wizard extended**: `<wizard-slug>` — describe what step or branch is added.
  - **Exemption**: `<one-sentence reason>` — must match the exemption rules in `CLAUDE.md` (lookup/single-field edits, or bulk operations on existing records).

  ## Out of scope
  What's deliberately not included.
  
  ## Acceptance criteria
  Bullet points. Specific. Testable.
  
  ## Notes
  Architectural callouts, edge cases, decisions made during shaping.

## Folder layout

- **`specs/`** — actively-implementable specs (Ready in SEQUENCE.md).
- **`specs/parked/`** — fully-shaped specs whose trigger-to-start hasn't fired yet. Move out of `specs/` so the Ready folder really means "ready"; move back when a trigger fires.
- **`specs/shipped/`** — closed specs. ID preserved.

When the spec ships, move the file to `specs/shipped/NNNN-<type>-<slug>.md`. When a Ready spec gets parked because the trigger hasn't fired (or trigger conditions changed), move to `specs/parked/`. Move back to `specs/` if the trigger fires later.
