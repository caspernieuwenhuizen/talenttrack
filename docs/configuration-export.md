---
title: Configuration — Export
group: configuration
summary: What the bulk exporters include, and who may run them.
audience: [admin]
order: 118
---

# Configuration — Export configuration

**Dashboard → Configuration → Export configuration** (`?config_sub=export`)

Downloads this academy's configuration as a single JSON file: every setting, plus which modules and features are switched on or off. Admin / club-admin only (`tt_edit_settings`).

Two things it is good for:

- **Knowing what this install actually has.** Before writing training or onboarding material, check which surfaces exist here. A module that is off takes its screens with it, and there is no point documenting a screen nobody can reach.
- **Setting up a second academy the same way.** The file is stable, versioned JSON, meant to be diffed between installs and — eventually — imported. There is no importer yet; see [Limits](#limits).

## What is in the file

Configuration is not stored in one place, and the export is the only surface that reads all of it together.

| Section | Source | What it holds |
| --- | --- | --- |
| `settings` | `tt_config` | Every academy setting: branding, colours, fonts, tile appearance, rating scale, date and locale, match minutes per age group, persona-dashboard toggles, wizard toggles, and more. |
| `options` | `wp_options` | Install-level values: plugin version, dashboard page, licence tier and plan. |
| `modules` | `tt_module_state` | Every module, on or off, with its label, category, and the screens it owns. |
| `features` | `tt_feature_state` | Every sub-feature within a module, on or off, with the screens it gates. |

Each module and feature carries its **human label** and its **`view_slugs`** — the `?tt_view=` addresses it owns. That is what makes the file usable for the training-material question: a module entry tells you not just that something is off, but which screens go away when it is.

```json
{
  "class": "TT\\Modules\\Journey\\JourneyModule",
  "label": "Player journey",
  "enabled": true,
  "always_on": false,
  "under_development": false,
  "view_slugs": ["injuries", "my-journey", "player-journey"]
}
```

A module that is switched off is still listed, flagged `"enabled": false`. Omitting it would defeat the purpose — "what is unavailable here" is half the question.

Features are only listed when their parent module is on. A feature under a disabled module is moot: the module already took the surface away.

### Reading it for coverage

`enabled` tells you whether a surface exists on this install. It does not tell you whether a *particular user* can reach it — that also depends on their capabilities and persona. For "which screens should the training material cover at all", `enabled` is the right question. For "what does a scout see", check the authorization matrix as well.

## What is not in the file

- **No player data.** Not in any form, in any section.
- **No credentials.** `tt_config` holds live integration secrets — the Strava app secret, the Spond account password and token, the DeepL API key, the Google service account. Their values are replaced with `"[redacted]"` and the key names are collected under `redacted_keys`.

The key name is kept even when the value is redacted, deliberately: "Strava is configured on this install" is exactly the sort of thing the export exists to tell you, and it is not sensitive. The value is.

Redaction is by pattern (any key containing `secret`, `password`, `api_key`, `token`, `credential`, `private_key`, `service_account`, or ending `_enc`) plus a short explicit list for keys that do not match a pattern. A new integration following the existing naming convention is redacted the day it lands.

## Provenance

Every file carries `schema_version`, `exported_at`, `plugin_version`, and `club_id`. `schema_version` is bumped whenever the payload shape changes in a way a consumer would have to care about — a consumer meeting a version it does not understand should refuse it rather than guess.

The export is recorded in the audit log.

## Through the API

```
GET /wp-json/talenttrack/v1/exports/config_json?format=json
```

Same capability gate, same payload, same audit entry. The rendered download and the API return an identical snapshot — the assembly lives in `ConfigSnapshotService`, which both call.

## Limits

- **Export only.** There is no importer yet: you cannot apply an exported file to another install from this screen. The Backups screen owns the existing data-migration import flow and is the likely home for a configuration importer when one lands.
- **Settings and toggles only.** Lookups and vocabularies, custom field definitions, the capability matrix, personas, workflow templates and methodology sets are *not* included. Those are academy content rather than availability. For a full data snapshot, use **Configuration → Backups** instead.
- **Needs the Export module.** The tile is hidden when the Export module is switched off, because that module owns the download handler.

## Related

- [Modules](modules.md) — switching modules and features on or off.
- [Backups](backups.md) — full club-data snapshot, restore, and the `.ttmig` migration flow.
- [Exports](exports.md) — the bulk data exports (players, attendance, goals).
