<!-- audience: admin -->

# Configuration — General

**Dashboard → Configuration → General** (`?config_sub=general`)

Academy-wide basics that affect how dates and the calendar read across TalentTrack. Settings are stored per club in `tt_config`, so a future multi-tenant install keeps each academy's choice separate. Saving goes through `POST /wp-json/talenttrack/v1/config` like the other inline configuration forms; the page is admin / club-admin only (`tt_edit_settings`).

## Settings

| Setting | What it does |
| --- | --- |
| **Date notation** | How dates are written — System default (the WordPress date-format option), `31-12-2026`, `31/12/2026`, `31.12.2026`, `12/31/2026`, ISO `2026-12-31`, or long `31 December 2026`. The form shows a live example of today's date as you pick. |
| **First day of the week** | Monday (default) or Sunday — the day the **team planner** week grid starts on. |
| **Timezone** | Academy-wide default timezone (the standard WordPress timezone list). |
| **Locale** | Default language for date and number formatting. Only installed languages are listed. |

## How the date notation is applied

Date notation resolves through a single helper, `TT\Shared\Dates\TTDate`, so the academy's choice is honoured in one place rather than re-decided at every call site. The **System default** preset reproduces the WordPress date format exactly, so an install that never touches the setting renders unchanged.

Surfaces adopt the helper incrementally. The **team planner** honours the first-day-of-week immediately; broader adoption of the date-notation preset across the rest of the product rolls out in follow-up work for this setting. Until a surface adopts the helper, it keeps its current format.

## See also

- [Configuration and branding](configuration-branding.md)
- [Modules](modules.md)
