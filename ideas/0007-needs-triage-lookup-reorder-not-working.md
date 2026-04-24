<!-- type: needs-triage -->

# Lookup tables — drag-to-reorder not visible (investigation)

Raw idea:

I still do not see "order" as input in the back-end pages for example where to manage positions. it only seems to be applied to evaluations, that is not right.

## Expected behavior

v2.19.0 shipped drag-to-reorder on lookup tables: Positions, Age Groups, Foot Options, Goal Status, Goal Priority, Attendance Status, Evaluation Types. Drag the ⋮⋮ handle to reorder, saves via AJAX, sort_order column updates live.

## What to investigate

- Are drag handles rendered on TalentTrack → Configuration → Positions? (visible when hovering rows)
- Is SortableJS actually loaded on that page? Check browser console for `Sortable` global + check Network tab for the script file.
- Is the DragReorder class wired into the specific page? src/Shared/Admin/DragReorder.php registers the AJAX handler; the UI-side enqueue is in Menu.php or per-page.
- Does the Positions table in the DB have a `sort_order` column populated? Likely yes since the lookup type existed before 2.19.
- Is the admin JS even firing on Configuration page, or only on pages with the sortable class attribute?

## Likely fixes

Depends on investigation, but probably one of:
- DragReorder::init() enqueues SortableJS on too narrow a hook pattern, missing Configuration page
- The Configuration page's lookup table doesn't have the CSS class DragReorder's JS looks for
- The .drag-handle column is rendered but styled as display:none

## Touches

src/Shared/Admin/DragReorder.php
src/Modules/Configuration/Admin/ConfigurationPage.php
Possibly assets/js/drag-reorder.js or similar
