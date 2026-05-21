# Versioning — how to read the plugin version number

**Audience:** club admins reading the wp-admin "Plugin Updates" panel.

TalentTrack uses [Semantic Versioning](https://semver.org) from version 4.0.0 onward.

A version reads as `MAJOR.MINOR.PATCH`. For example, **4.1.3**:

| Part | Meaning |
|---|---|
| `4` (major) | A new major version means a change that needs you to do something on upgrade — a database column rename, a REST API contract change, a capability matrix change. We will call this out explicitly in the upgrade notes. |
| `1` (minor) | A new feature has shipped (e.g. a new dashboard widget, a new export, a new wizard step). Upgrade is safe; the new feature appears alongside existing ones. |
| `3` (patch) | Bug fixes and small enhancements within the same minor. Always safe to upgrade. |

## What does the v4.0.0 reset mean for me?

Nothing operationally. The reset was cosmetic — versions `3.110.x` had drifted to a meaningless place; v4.0.0 redraws the line. No data was changed, no setting needs your attention, no migration runs. The plugin update goes through automatically via your normal WordPress update flow.

## Reading the changelog

Every release lands a stanza in `CHANGES.md` (also visible from the WordPress Plugins page → "View details") explaining what changed, why, and how to test if you want to verify.

When in doubt, the **major number** is the signal: if it bumps, look for the upgrade notes. If only minor or patch bumps, you can update at your normal cadence.
