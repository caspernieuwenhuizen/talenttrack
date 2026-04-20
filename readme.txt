=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.7
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.6.7 — Fix PHP parse error in MigrationRunner + include v2.6.6 Activator =
* FIXED: MigrationRunner.php shipped in v2.6.5 contained a // comment with a literal PHP close-tag sequence inside it. PHP's lexer treats that sequence as an actual close tag even inside // comments, so the file silently dropped into HTML mode mid-function. This is why CI has been failing every release since v2.6.4 — and why the 3rd release asset (talenttrack.zip) was missing. The build's PHP lint step is working exactly as it should.
* This release bundles the corrected MigrationRunner.php together with the v2.6.6 Activator-based schema reconciliation.

= 2.6.6 — Schema reconciliation via Activator =
= 2.6.5 — [failed lint] Migration loader via eval() =
= 2.6.4 — [failed lint] Migration loader hardening =
= 2.6.3 — Migrations admin page =
= 2.6.2 — Fail-loud save handlers =
= 2.6.1 — Custom fields integration =
= 2.6.0 — Custom fields foundation =
= 2.5.x — Frontend-first application =
= 2.4.x — i18n =
= 2.3.0 — Observability =
= 2.2.0 — REST envelope =
= 2.1.0 — Migrations =
= 2.0.x — Architectural foundation =
= 1.0.0 =
* Initial release.
