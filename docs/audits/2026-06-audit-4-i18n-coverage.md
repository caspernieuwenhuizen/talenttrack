<!-- audience: dev -->

# Audit 4 — i18n coverage + drift (June 2026)

Mechanical sweep of `languages/talenttrack-nl_NL.po` for translation drift, plus a
heuristic scan of `src/` for English literals that look like they escaped the
gettext extractor. Issue: [#1178](https://github.com/caspernieuwenhuizen/talenttrack/issues/1178).

## Summary

| Metric | Count |
|---|---:|
| Total msgids in `talenttrack-nl_NL.po` | **6 344** |
| Empty `msgstr ""` (untranslated) | **910** (14.3%) |
| `#, fuzzy` entries | **0** |
| Hardcoded-English suspects (heuristic, 100-file sample) | **~15 confirmed** (after false-positive review) |

The headline is: **910 msgids ship the English source verbatim to Dutch
pilot installs**, and they cluster heavily — the top 10 source files account
for 451 of them (~50%). Filling these in batches per file is the obvious
shape: each PR translates one file's strings in `nl_NL.po`, no PHP/JS
changes required.

Zero fuzzies means msgmerge has been kept clean (commit `69624c8`
"chore(i18n): sync .pot from PHP source + msgmerge into .po" is the
cause). The remaining work is purely translator output.

## Table A — Untranslated msgids, grouped by source file

Top 30 source files by empty-msgstr count. Each row is a single-PR target.

| Count | Source file |
|---:|---|
| 54 | `src/Shared/Frontend/FrontendExportsView.php` |
| 53 | `src/Modules/Goals/Print/PlayerGoalIntakePrintRouter.php` |
| 49 | `src/Shared/Frontend/FrontendConfigurationView.php` |
| 49 | `src/Modules/Vct/Frontend/FrontendVctConfigView.php` |
| 46 | `src/Shared/Frontend/FrontendPlayerDetailView.php` |
| 44 | `src/Modules/MatchExecution/Frontend/FrontendMatchExecutionView.php` |
| 42 | `src/Modules/TeamDevelopment/Frontend/FrontendTeamChemistryView.php` |
| 31 | `src/Modules/Vct/Frontend/FrontendVctLibraryView.php` |
| 30 | `src/Modules/Methodology/Print/MethodologyReferencePrintRouter.php` |
| 28 | `src/Modules/Authorization/Admin/AuthChainDebugPage.php` |
| 22 | `src/Modules/Vct/Frontend/FrontendVctSessionView.php` |
| 18 | `src/Modules/Tournaments/Frontend/FrontendTournamentMatchAddView.php` |
| 17 | `src/Modules/MatchPrep/Print/MatchPrepPrintableRenderer.php` |
| 16 | `src/Infrastructure/Query/LabelTranslator.php` |
| 14 | `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` |
| 14 | `src/Shared/Frontend/FrontendTeamBehaviourCaptureView.php` |
| 13 | `src/Modules/Analytics/Frontend/FrontendExploreView.php` |
| 13 | `src/Modules/Export/Exporters/KpiSnapshotXlsxExporter.php` |
| 13 | `src/Modules/Trials/Letters/LetterTemplateEngine.php` |
| 12 | `src/Shared/Frontend/FrontendTeamDetailView.php` |
| 11 | `src/Modules/Analytics/Frontend/FrontendMinutesTeamReportView.php` |
| 10 | `src/Modules/Tournaments/Wizard/MatchesStep.php` |
| 10 | `src/Modules/Tournaments/Wizard/SquadStep.php` |
|  9 | `src/Modules/PersonaDashboard/Widgets/MarkAttendanceHeroWidget.php` |
|  9 | `src/Modules/MatchExecution/Frontend/FrontendMatchExecutionsListView.php` |
|  9 | `src/Modules/Pdp/Rest/PdpBlocksRestController.php` |
|  9 | `src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php` |
|  8 | `src/Modules/Comms/Templates/GuestPlayerInviteTemplate.php` |
|  8 | `src/Shared/Frontend/FrontendActivitiesManageView.php` |
|  8 | `src/Modules/MatchExecution/Rest/MatchExecutionRestController.php` |

(Long tail of ~140 more files holding 1–7 untranslated strings each.)

## Table B — Top 20 pilot-critical empty msgstrs

Ranked by surface visibility (widget = +25, wizard step = +20, dashboard
= +10, plus a small bump for confirm/delete/save vocabulary). These ship
English on every Dutch dashboard render.

| Source | msgid |
|---|---|
| `src/Modules/PersonaDashboard/Widgets/MatchesNeedingReviewWidget.php:40` | "Matches needing review" |
| `src/Modules/PersonaDashboard/Widgets/DataTableWidget.php:49` | "Behaviour pending" |
| `src/Modules/PersonaDashboard/Widgets/DataTableWidget.php:112` | "You have not logged any prospects yet. Use the "+ New prospect" hero above to start." |
| `src/Modules/PersonaDashboard/Widgets/DataTableWidget.php:157` | "Since" |
| `src/Modules/PersonaDashboard/Widgets/DataTableWidget.php:159` | "Up to date — all your players have a recent behaviour rating." |
| `src/Modules/PersonaDashboard/Widgets/MarkAttendanceHeroWidget.php:221` | "Live match" |
| `src/Modules/PersonaDashboard/Widgets/MarkAttendanceHeroWidget.php:233` | "Resume match" |
| `src/Modules/PersonaDashboard/Widgets/MarkAttendanceHeroWidget.php:246` | "Today's match" |
| `src/Modules/PersonaDashboard/Widgets/MarkAttendanceHeroWidget.php:257` | "Kickoff %s" |
| `src/Modules/PersonaDashboard/Widgets/MarkAttendanceHeroWidget.php:282` | "Edit prep" |
| `src/Modules/PersonaDashboard/Widgets/MatchesNeedingReviewWidget.php:43` | "Lists match executions that have ended but not yet been finalised, on teams the coach can review." |
| `src/Modules/PersonaDashboard/Widgets/MatchesNeedingReviewWidget.php:87` | "%d match ended, waiting for finalize." |
| `src/Modules/PersonaDashboard/Widgets/MatchesNeedingReviewWidget.php:115` | "Review ›" |
| `src/Modules/PersonaDashboard/Widgets/RecentCommentsWidget.php:231` | "%d min ago" |
| `src/Modules/PersonaDashboard/Widgets/RecentCommentsWidget.php:235` | "%d h ago" |
| `src/Modules/PersonaDashboard/Widgets/RecentCommentsWidget.php:239` | "%d d ago" |
| `src/Modules/Tournaments/Wizard/SquadStep.php:87` | "Tick the players in the squad and mark which specific positions each can play. …" |
| `src/Modules/Wizards/Evaluation/BehaviourStep.php:60` | "Optional — record a quick behaviour rating for this activity. Skip any player you do not want to rate." |
| `src/Modules/Wizards/Evaluation/ReviewStep.php:259` | "No ratings were entered. Rate at least one category for at least one player before saving." |
| `src/Modules/Wizards/Goal/PlayerStep.php:53` | "You don't coach any teams yet, so there's no roster to pick from. Ask an administrator to assign you to a team." |

## Table C — Hardcoded English suspects (escaped gettext)

100-file PHP sample under `src/`, with a regex-based heuristic looking
for multi-word English literals inside `echo` / `return` / `sprintf` /
`wp_die` / `WP_Error` outside any `__()` / `_e()` / `esc_html__()` etc.
wrapper. Many heuristic hits were false positives (HEREDOC-style
concatenations across line breaks, `label_key` arrays that are
re-translated downstream by a switch in `actionLabel()`). The
genuine leftovers:

| File | Line | Literal | Surface |
|---|---:|---|---|
| `src/Modules/Development/Frontend/IdeaPromoteHandler.php` | 23, 24, 29, 41 | `"Not logged in."` / `"Insufficient permissions."` / `"Bad request."` / `"Not found."` | `wp_die()` to dev users |
| `src/Modules/Development/Frontend/IdeaRefineHandler.php` | 19, 20, 25, 29 | same set | `wp_die()` |
| `src/Modules/Development/Frontend/IdeaRejectHandler.php` | 18, 19, 24 | same set | `wp_die()` |
| `src/Modules/Development/Frontend/IdeaSubmitHandler.php` | 20, 21 | same set | `wp_die()` |
| `src/Modules/Development/Frontend/TrackDeleteHandler.php` | 17, 18, 25 | same set | `wp_die()` |
| `src/Modules/Development/Frontend/TrackSaveHandler.php` | 17, 18 | same set | `wp_die()` |
| `src/Modules/Invitations/Frontend/InvitationAcceptHandler.php` | 26 | `"Invalid token."` | `wp_die()` |
| `src/Modules/Invitations/Frontend/InvitationCreateHandler.php` | 18, 19, 25 | same `wp_die()` set + `"Invalid kind."` | `wp_die()` |
| `src/Modules/Invitations/Frontend/InvitationRevokeHandler.php` | 16, 17, 22 | same set | `wp_die()` |
| `src/Modules/Invitations/Frontend/MessageSaveHandler.php` | 19, 20 | same set | `wp_die()` |
| `src/Modules/Backup/Admin/BackupSettingsPage.php` | 342 | `'Unknown error'` | `esc_html()` fallback for upstream error |
| `src/Infrastructure/REST/BaseController.php` | 55 | `'Field "%s" is required.'` | REST sprintf format string outside `__()` |

The `wp_die()` strings are the cleanest fix surface: each Handler file
takes a one-line edit to wrap every English literal in
`__( …, 'talenttrack' )`. They're visible to any user who hits an
error path (CSRF mismatch, permission denied, malformed POST).

False positives in the scan that don't need fixing:

- `ActionCardWidget::ACTIONS` — `label_key` strings look hardcoded but
  are dispatched through a static `actionLabel()` switch with `__()`
  per case. The dual-list shape exists deliberately so
  `wp i18n make-pot` extracts them (see commit `v4.0.2 #805`).
- `confirm('<?php echo esc_js( __( … ) ) ?>')` patterns in admin pages
  (BackupSettingsPage, MatrixPage, etc.) — the literal *is* wrapped,
  the regex tripped on the nested quotes.
- Mfa wizard steps containing apostrophes inside the literal — the
  regex got confused by the quote nesting; the literals are wrapped.

## Recommended workflow (continuous version)

Wire the drift detection into CI so the next missed Dutch string is
caught pre-merge instead of pilot-reported. Shape:

1. **Weekly GitHub Action** (e.g. Mondays 06:00 UTC) that:
   - Runs `wp i18n make-pot` on `src/`.
   - Runs `msgmerge --update languages/talenttrack-nl_NL.po
     languages/talenttrack.pot --no-fuzzy-matching`.
   - Parses the merged `.po` and counts empty + fuzzy entries.
   - If either count grew vs. last successful run, files (or
     updates) a single tracking issue titled
     "i18n drift: <N> empty msgstrs, <M> fuzzy" with a per-file
     breakdown copied into the body.
2. **PR-time check** that fails if a PR introduces a new untranslated
   msgid in `nl_NL.po` (i.e. delta of empty msgstrs > 0).
3. (Bonus) extend the existing
   `scripts/audit-no-legacy-sessions.php` pattern with a stricter
   "hardcoded English in `wp_die` / `WP_Error` / `sprintf` first arg"
   check that catches the dev-handler class of leak in Table C.

The continuous version is what the issue spec proposed; the per-file
follow-ups below cover the existing backlog.

## Follow-up issue specs (filed under #1178)

Each is an executor-drainable batch. Skipped: ~140 source files that
each hold 1–4 untranslated strings on non-pilot-critical surfaces —
those get swept by the CI drift report once it's running.

| # | Issue | Scope | Strings |
|---|---|---|---:|
| 1 | [#1204](https://github.com/caspernieuwenhuizen/talenttrack/issues/1204) | PersonaDashboard widgets — highest-traffic surface | 24 |
| 2 | [#1205](https://github.com/caspernieuwenhuizen/talenttrack/issues/1205) | Evaluation + Activity + Goal wizard steps | 22 |
| 3 | [#1206](https://github.com/caspernieuwenhuizen/talenttrack/issues/1206) | FrontendExportsView (bulk exports) | 72 |
| 4 | [#1208](https://github.com/caspernieuwenhuizen/talenttrack/issues/1208) | FrontendConfigurationView + LabelTranslator | 66 |
| 5 | [#1214](https://github.com/caspernieuwenhuizen/talenttrack/issues/1214) | MatchExecution view + list + REST | 76 |
| 6 | [#1215](https://github.com/caspernieuwenhuizen/talenttrack/issues/1215) | Tournaments wizard + match-add view | 61 |
| 7 | [#1216](https://github.com/caspernieuwenhuizen/talenttrack/issues/1216) | MatchPrep wizard + printable | 34 |
| 8 | [#1217](https://github.com/caspernieuwenhuizen/talenttrack/issues/1217) | VCT module — all surfaces | 173 |
| 9 | [#1218](https://github.com/caspernieuwenhuizen/talenttrack/issues/1218) | Goals print + Pdp + TeamDevelopment | 144 |
| 10 | [#1219](https://github.com/caspernieuwenhuizen/talenttrack/issues/1219) | Comms email templates (15 files) | 69 |
| 11 | [#1220](https://github.com/caspernieuwenhuizen/talenttrack/issues/1220) | Wrap hardcoded English in `wp_die()` handlers | 38 |
| 12 | [#1223](https://github.com/caspernieuwenhuizen/talenttrack/issues/1223) | CI weekly drift-report workflow + PR check | (CI) |

Translator-pass issues 1–10 cover **761 of the 910 empty msgstrs**
(~84%). The remaining ~149 are spread across the long tail of files
holding 1–7 untranslated strings each — those get swept by the CI
drift report (#1223) once it's running.

## Quality notes

- The 910 figure was measured against the freshly-msgmerged `.po`
  (commit `69624c8`). It is a real ship-blocker number, not an
  artefact of unsynced metadata.
- The widget translations (issue 1 above) are the single highest-impact
  fix in the audit. Anything else can wait; that one should ship next.
- The hardcoded `wp_die()` leak (issue 10) is also a process leak:
  the dev `IdeaPromoteHandler` was added without anyone running
  `wp i18n make-pot`. Once the CI drift report (issue 11) lands,
  this class becomes auto-detected.
