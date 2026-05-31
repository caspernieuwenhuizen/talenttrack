# VCT config tiles — design notes (VCT-12)

Two new tiles added to the Configuration grid (`?tt_view=configuration`):

- **VCT macro-blocks** — operator-editable templates for the wizard's
  block builder (warming-up, hoofddeel, cooldown, theme-blocks).
- **VCT age-profiles** — per age-band caps (workload, intensity per
  MD-day, session length) consumed by the wizard's workload check.

Pattern lifted from the existing **PDP blocks** tile in the same
grid — same visual weight, same count badge, same inline-form CRUD
when clicked (CLAUDE.md §3 exemption b for lookup/vocabulary editors).

## What's already seeded

VCT-2 (Phase 1) shipped a complete seed for both. Operator edits
land via the existing VCT admin pages; no schema or REST work is
needed for this issue beyond adding the two tiles to the grid.

## Open questions

- Should archived macro-blocks render in a "Toon gearchiveerd" subview
  or stay always-visible with strikethrough? Mockup leaves it open.
- Age-profile tile shows raw count "7 leeftijdsbanden" — should it
  also surface a warning when any band has no workload-cap set?
