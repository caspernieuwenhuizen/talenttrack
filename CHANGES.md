# TalentTrack v4.3.15 — Team planner localised day-header fix (closes #945)

## Symptom

On a Dutch install, the team planner's calendar showed the literal letter `V` in every column header instead of the expected `ma di wo do vr za zo` (Dutch short day names).

## Root cause — msgid collision in the .po

`FrontendTeamPlannerView.php:222` was calling `wp_date( __( 'D', 'talenttrack' ), … )` — wrapping a PHP date-format character through `__()`. The Dutch `.po` carried:

```
#: src/Modules/Authorization/Admin/MatrixPage.php:49
#: src/Modules/Planning/Frontend/FrontendTeamPlannerView.php:222
msgid "D"
msgstr "V"
```

The `msgstr "V"` was correctly entered for the matrix admin page's use of `'D'` (column abbreviation for **Verwijderen** = Delete). gettext doesn't distinguish the two uses — same `msgid`, both call sites resolved to `msgstr "V"`. The planner then got `wp_date('V', …)`, which emitted the literal `'V'` because that's not a recognised PHP date-format character.

Same shape on line 223 with `'M j'`: format strings going through `__()` is wrong as a class.

## Fix — three changes

### 1. Don't pass date-format strings through `__()`

`FrontendTeamPlannerView.php:212-213, 222-223`:

```php
// Before:
wp_date( __( 'M j', 'talenttrack' ), strtotime( $week_start ) ),
wp_date( __( 'D',   'talenttrack' ), strtotime( $day ) );

// After:
wp_date( 'M j', strtotime( $week_start ) ),
wp_date( 'D',   strtotime( $day ) );
```

`wp_date()` already localises its output through the site locale; the format characters stay as PHP-recognised codes.

### 2. Disambiguate the matrix-page abbreviations via `_x()`

`MatrixPage.php:47-49`:

```php
// Before:
'read'          => __( 'R', 'talenttrack' ),
'change'        => __( 'C', 'talenttrack' ),
'create_delete' => __( 'D', 'talenttrack' ),

// After:
'read'          => _x( 'R', 'matrix column abbreviation for Read',   'talenttrack' ),
'change'        => _x( 'C', 'matrix column abbreviation for Change', 'talenttrack' ),
'create_delete' => _x( 'D', 'matrix column abbreviation for Delete', 'talenttrack' ),
```

Future plain `__('D')` calls now can't collide with the matrix's Delete column.

### 3. Update Dutch `.po`

`languages/talenttrack-nl_NL.po`:

```
msgctxt "matrix column abbreviation for Read"
msgid "R"
msgstr "L"  # Lezen

msgctxt "matrix column abbreviation for Change"
msgid "C"
msgstr "A"  # Aanpassen

msgctxt "matrix column abbreviation for Delete"
msgid "D"
msgstr "V"  # Verwijderen
```

The `i18n-sync` workflow on push to main regenerates `.pot` and `msgmerge`s the other locale `.po` files; the obsolete un-contextualised `"D"` / `"C"` entries get marked `#~` automatically on the next sync pass.

## What this restores

- Dutch install: planner header shows `ma di wo do vr za zo` (and dates like `6 jun`).
- fr/de/es installs: locale-correct equivalents (no longer fall back to the planner literal).
- Matrix admin: keeps its localised single-letter abbreviations (R/C/D on English, L/A/V on Dutch).

## Why patch

UI fix; no schema, no migration, no behavioural change on English installs.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.14` → `4.3.15`.
