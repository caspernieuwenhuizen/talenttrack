<!-- type: feat -->

# #0058 — Wizard-first as a development standard

## Problem

#0055 (record-creation wizards) shipped the framework: `WizardInterface`, `WizardRegistry`, `WizardState`, generic driver, four real wizards (new-player, new-team, new-evaluation, new-goal). That epic's PR said new flows "MUST" use the framework, but it never updated `CLAUDE.md`, `DEVOPS.md`, or the spec template to enforce it. So the rule lives only inside one merged PR description.

That gap means the next person (or instance of Claude Code) who adds a creation flow ships a flat form by default — because nothing in the standards layer says otherwise.

This spec writes the rule down where every contributor will see it.

Who feels it: future contributors (don't know the rule), future-Claude-Code (doesn't see it in `CLAUDE.md`), the user (has to remember to point at the framework every time a new feature is shaped).

## Proposal

Three small, mostly-docs edits:

1. **`CLAUDE.md` § new section "Wizard-first record creation"** — one paragraph stating the rule, one paragraph on exemptions, one line on the retrofit policy.
2. **`specs/README.md` template** — add a required "Wizard plan" section to the spec structure.
3. **PR checklist** — one-line item under § 5 Definition of done: "Wizard-first standard met or exemption justified in spec."

No code. No schema. No retrofit work. Just the rule, written down.

## Scope

### `CLAUDE.md` — new section after § 2 (Mobile-first), before § 3 (SaaS-ready)

Insert the following section:

> ## 3. Always-on principle — Wizard-first record creation
>
> Any new feature that introduces a record-creation flow — a new top-level entity, or a new sub-entity reachable from a "+ New …" button — **MUST** ship with a wizard implemented against `Shared\Wizards\WizardInterface`, registered in `WizardRegistry`, and reachable via `?tt_view=wizard&slug=<…>`.
>
> The flat-form path remains in the codebase as the power-user fallback. The wizard's final step hands off to it. Entry-point gating via `WizardEntryPoint::urlFor()` decides which path the user lands on, governed by the `tt_wizards_enabled` site option.
>
> Multi-step flows beyond record creation (settings panels with > 5 fields, anything involving file upload + mapping + confirmation, anything that mutates more than one table on save) **SHOULD** also ship as a wizard. Single-purpose admin pages and lookup tables remain flat — no benefit from a wizard there.
>
> **Exemptions** require an explicit `Wizard plan: exemption — <reason>` line in the feature's spec. Two pre-approved exemptions:
>
> - (a) lookup / vocabulary edits (single-field changes).
> - (b) bulk operations on existing records.
>
> **Retrofit policy**: existing flat-form-only flows are NOT required to be retrofitted. The rule applies forward only, from the merge date of #0058. Specific retrofit work, if it ever feels worth doing, gets its own idea file.

(Subsequent section numbers shift by one — § 3 SaaS-ready becomes § 4, § 4 Mandatory reading becomes § 5, § 5 Definition of done becomes § 6.)

### `specs/README.md` — add "Wizard plan" section

Update the spec-structure list to include one new required section between **Scope** and **Out of scope**:

> ### Wizard plan
>
> One of:
>
> - **Slug**: `<wizard-slug>` — new wizard. Briefly describe its steps.
> - **Existing wizard extended**: `<wizard-slug>` — describe what step or branch is added.
> - **Exemption**: `<one-sentence reason>` — must match the exemption rules in `CLAUDE.md`.

This makes the wizard decision explicit at spec-shaping time, not implicit at PR-review time when it's too late to redesign cheaply.

### `CLAUDE.md` § Definition of done — add wizard-first checkbox

Under the existing § 5 (becoming § 6), add a new heading:

> **Wizard-first (`CLAUDE.md` § 3 — record creation):**
>
> - [ ] If this PR creates a new record-creation flow: a wizard exists for it, registered in `WizardRegistry`.
> - [ ] If this PR is exempt: the exemption is justified in the spec's "Wizard plan" section.

### Existing specs — opportunistic backfill

The 5 specs currently in `specs/` that describe new record-creation flows (#0017 trial cases, #0028 conversational goals (thread is creation-flow-adjacent), #0039 staff development, #0042 youth contact strategy phone field, #0053 player journey events) are **not retrofitted**. Per the retrofit policy above, the rule applies forward only.

For specs that are already shipped, no change. For specs that haven't been built yet, when they reach implementation, the engineer adds a "Wizard plan" line via PR — that's enough.

## Wizard plan (this spec)

Exemption: docs-only change. No record-creation flow involved. Justified per the rule itself.

## Out of scope

- **Retrofit existing flat-form flows** — the rule applies forward only. Retrofit work is its own idea per the policy.
- **Mandate wizards for editing existing records** — wizards are creation-only; editing stays as the flat form. (The framework supports edit wizards; the standard doesn't require them.)
- **CI gate to enforce the rule mechanically** — too noisy. The PR checklist is the gate; reviewer judgment is the enforcement.
- **Per-module variant rules** — same rule applies whether you're in Players, Activities, Trials, or anywhere else.

## Acceptance criteria

### `CLAUDE.md`

- [ ] New § 3 "Wizard-first record creation" inserted between current § 2 and § 3.
- [ ] Subsequent section numbers shifted (SaaS-ready → § 4, Mandatory reading → § 5, Definition of done → § 6).
- [ ] Two exemption categories explicitly listed.
- [ ] Retrofit policy line included.

### `specs/README.md`

- [ ] "Wizard plan" subsection documented in the spec-structure list.
- [ ] Three options for the section content (Slug / Existing wizard extended / Exemption) explicitly documented.

### Definition-of-done checklist

- [ ] Two new checkbox items added under a "Wizard-first" heading in the existing checklist.

### No regression

- [ ] Any pending PR that doesn't yet have a "Wizard plan" section in its spec is grandfathered (the rule applies to PRs opened after merge).
- [ ] All five always-on principles in `CLAUDE.md` (player-centric, mobile-first, **wizard-first**, SaaS-ready, definition-of-done) read coherently as one set.

## Notes

### Sizing

~30-45 minutes. Pure docs.

### Hard decisions locked during shaping

1. **Insert as new § 3 in `CLAUDE.md`** — between Mobile-first (§ 2) and SaaS-ready (existing § 3). Wizard-first is a UX standard like Mobile-first; they belong together.
2. **Two exemptions only**: lookup edits + bulk operations. Don't multiply exemptions; the standard is meant to be a default, not optional.
3. **Forward-only retrofit policy** — explicit so future debates don't reopen.
4. **PR checklist gate, not CI gate** — mechanical enforcement is too noisy across the codebase.

### Cross-references

- **#0055** — record-creation wizards; this spec writes down the rule that #0055's framework expects.
- **#0056** — mobile-first cleanup pass; same "write the standing rule down clearly" pattern.

### Player-centric question (per `CLAUDE.md` § 1)

Indirectly: every coach, parent, and admin reaches a player record through a creation flow first. A guided wizard creates better-quality records (less missing data, fewer "I'll fix it later" half-fills) than a 30-field flat form. Better records → better player journey data → better insight. The standard exists to keep that quality consistent across every future feature.
