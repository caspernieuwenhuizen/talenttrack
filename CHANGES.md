# TalentTrack v4.0.2 — Quick Action widget Dutch labels render translated (closes #805)

## Pilot report

> quick action widget has english titles for the quick actions, how can this happen?

## Root cause

The 8 action-card labels (`+ New evaluation`, `+ New goal`, `+ New activity`, `+ Add player`, `+ Add team`, `+ New scout report`, `+ New trial`, `+ New test training`) lived in a `private const ACTIONS` array keyed by id, and the render path resolved them via `__($action['label_key'], 'talenttrack')`.

`wp i18n make-pot` does NOT extract `__()` calls with variable args. The 8 msgids never landed in `talenttrack.pot`. Over time the i18n-sync workflow marked the existing Dutch translations as **obsolete** (`#~`) in `nl_NL.po` since the bot couldn't find a live source reference for them.

Result: on Dutch installs, the action-card widgets rendered the English msgid literal even though Dutch translations existed in the .po file — a structural i18n drift, not a missing-translation gap.

## Fix

Two parts:

1. **Refactor**: new static `ActionCardWidget::actionLabel( string $id )` with literal `__()` calls in a switch. The 8 msgids are now extractable on the next `wp i18n make-pot` run.
2. **`nl_NL.po`**: un-obsolete the 7 existing entries (removed the `#~` prefix) and add `+ New test training` which was missing. Re-titled the comment block to point at the v4.0.2 / #805 context.

Both `render()` and `dataSourceCatalogue()` now go through `actionLabel()` so the widget admin picker AND the rendered tile show the same translated label.

## Why this matters beyond cosmetics

Every dynamic `__($variable)` call has this exact problem — the bot's next msgmerge run marks the msgid obsolete and translations silently rot. Adjacent surfaces that follow the same anti-pattern (`__($row->name)`, `__($key)`, etc.) are vulnerable to the same drift. Worth a follow-up audit but out of scope for this ship.

## Files touched

- `src/Modules/PersonaDashboard/Widgets/ActionCardWidget.php` — added `actionLabel()` static; `render()` + `dataSourceCatalogue()` use it.
- `languages/talenttrack-nl_NL.po` — 7 entries un-obsoleted + 1 new (`+ New test training`).
- `talenttrack.php` + `readme.txt` + `CHANGES.md` — version bump.

## How to test

1. On a Dutch install: open any coach / HoD dashboard → confirm the action-card tiles render the Dutch labels (`+ Nieuwe evaluatie`, `+ Nieuw doel`, etc.) instead of the English msgid.
2. Wp-admin dashboard layout editor → action-card widget data-source picker → confirm the dropdown shows Dutch labels for each action id.
3. Pre-`v4.0.2` install rolled back: same labels render in English — confirms the fix is what's making the difference.
