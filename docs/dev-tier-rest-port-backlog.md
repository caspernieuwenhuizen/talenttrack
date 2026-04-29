<!-- audience: dev -->

# REST port backlog — `admin_post_*` / `wp_ajax_*` handlers

Tracking the remaining handlers that should move to REST endpoints when next touched, per the **port-on-touch policy** introduced in #0052 PR-B.

The policy: when you touch a file that registers `admin_post_*` or `wp_ajax_*` handlers, port the handler to a REST endpoint in the same PR if the change is non-trivial. Trivial changes (typo fix, copy edit) don't trigger the port.

## Progress

| Status | When | What |
| - | - | - |
| Done | #0052 PR-B (this) | `LookupsRestController`, `AuditLogRestController`, `InvitationsRestController` shipped under `/wp-json/talenttrack/v1/`. |
| Done | Earlier | Players, Teams, Activities, Evaluations, Goals, People, Custom Fields, Functional Roles, Eval Categories, Config, PDP (Conversations / Files / Verdicts / Seasons), Team Development (Formation Templates, Pairings), Journey, Player Status, Spond, Persona Dashboard, Threads. |

## Backlog — port when next touched

These files register `admin_post_*` / `wp_ajax_*` handlers that should be replaced by a REST endpoint when the file is next non-trivially edited. Listed by module, then file. Suggested REST verb + path is the *target* shape; the actual implementation may differ.

### Invitations

| File | Current handler | Suggested REST shape |
| - | - | - |
| `Frontend/InvitationCreateHandler.php` | `admin_post_tt_invitation_create` | Already covered by `POST /v1/invitations` (PR-B). The admin-post handler stays for now since the existing form-post UX redirects after submit; remove on next touch. |
| `Frontend/InvitationAcceptHandler.php` | `admin_post(_nopriv)_tt_invitation_accept` | Covered by `POST /v1/invitations/{token}/accept`. Keep until the acceptance page UX migrates. |
| `Frontend/InvitationRevokeHandler.php` | `admin_post_tt_invitation_revoke` | Covered by `DELETE /v1/invitations/{id}`. |
| `Frontend/MessageSaveHandler.php` | `admin_post_tt_invitation_message_save` | `PUT /v1/invitations/messages/{kind}`. |

### Development (idea-management surfaces)

| File | Current handler | Suggested REST shape |
| - | - | - |
| `Frontend/IdeaSubmitHandler.php` | `admin_post_tt_dev_idea_submit` | `POST /v1/dev/ideas` |
| `Frontend/IdeaPromoteHandler.php` | `admin_post_tt_dev_idea_promote` | `POST /v1/dev/ideas/{id}/promote` |
| `Frontend/IdeaRefineHandler.php` | `admin_post_tt_dev_idea_refine` | `PUT /v1/dev/ideas/{id}` |
| `Frontend/IdeaRejectHandler.php` | `admin_post_tt_dev_idea_reject` | `POST /v1/dev/ideas/{id}/reject` |
| `Frontend/TrackSaveHandler.php` | `admin_post_tt_dev_track_save` | `PUT /v1/dev/tracks/{id}` |
| `Frontend/TrackDeleteHandler.php` | `admin_post_tt_dev_track_delete` | `DELETE /v1/dev/tracks/{id}` |

### Backups / Translations / Methodology / Workflow / Misc

The remaining ~30 admin-post / wp-ajax callsites are spread across:

- `Modules/Backup/Admin/*` — backup run / restore / settings save handlers.
- `Modules/Translations/Admin/*` — translation cache rebuild + .mo regenerate.
- `Modules/Methodology/Admin/*` — methodology import/export.
- `Modules/Workflow/Admin/*` — workflow template enable/disable.
- `Modules/Authorization/Admin/*` — matrix grant/revoke.
- `Modules/Configuration/Admin/*` — config tab saves.
- `Modules/Reports/Admin/*` — scout-access grant.
- `Shared/Admin/*` — bulk actions, exports.

For each: when the file is next non-trivially touched, port the relevant handler. The existing controller patterns (`BaseController`, `RestResponse`) make each port a ~30-60 minute task. No need to port them en masse.

## What "non-trivial" means

- ✅ **Trigger a port**: adding a field, changing validation, changing the redirect path, fixing a security issue, refactoring the handler body, changing what gets written.
- ❌ **Don't trigger a port**: typo in a strings, copy edit, code-style fix, comment update.

When in doubt, port. The REST surface gets stronger with every port; the admin-post surface shrinks.
