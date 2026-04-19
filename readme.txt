=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.1
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.6.1 — Sprint 1b part 2 (custom fields integration) =
* Custom fields now appear on the Admin → Players add/edit form as an "Additional Fields" section below core fields.
* Validation errors redisplay the form with clear error messages and preserve submitted values.
* Player detail view (admin) shows custom field values read-only.
* Player dashboard Overview tab shows custom field values in a styled block.
* Coach Player Detail tab shows custom field values when viewing a player.
* REST API `/players` and `/players/{id}` responses now include a `custom_fields` object (field_key → typed value).
* REST API POST/PUT accept a `custom_fields` object. Validation failures return HTTP 422 with the standard errors envelope; the player row is not modified if validation fails.
* REST controller overhauled: full player field coverage (matches admin form) and standard envelope.
* Added "Go to Admin" link in the user-menu dropdown for administrator users only.
* New Dutch translations for custom-fields labels and validation messages.

= 2.6.0 — Sprint 1b part 1 (custom fields foundation) =
* Polymorphic custom-field tables + admin tab for managing field definitions.

= 2.5.1 — Sprint 1a polish =
= 2.5.0 — Sprint 1a (frontend-first application) =
= 2.4.1 — i18n completion pass =
= 2.4.0 — Sprint 0 Phase 4 (full i18n) =
= 2.3.0 — Sprint 0 Phase 3 (observability & governance) =
= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =
= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =
= 2.0.1 =
= 2.0.0 — Sprint 0 Phase 1 (architectural foundation) =
= 1.0.0 =
* Initial release.
