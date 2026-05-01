<!-- audience: admin -->

# Impersonation

> Documentation for the user impersonation feature shipped in v3.72.0 as part of the #0071 authorization-matrix-completeness epic.

Native admin-to-user impersonation lets an Academy Admin (the WordPress `administrator` or anyone holding the `tt_club_admin` role) switch into another user's session, see exactly what that user sees, and switch back. Two real problems this solves:

1. **Testing**: "what does this parent's dashboard look like for someone whose child is in U10?"
2. **Support**: "the user reports a bug; let me see what they see."

Today the alternatives are asking the user to share their screen or recreating the exact role-and-team-assignment combination on a sock-puppet account, both of which are slow and error-prone.

## Who can impersonate

The capability `tt_impersonate_users` is granted by default to:

- the WordPress `administrator` role (always — superadmins should retain emergency access)
- the `tt_club_admin` role (Academy Admin in matrix terms)

**No other persona ever holds this capability.** Specifically: Head of Development does NOT get impersonation rights — even after the #0071 narrowing of HoD to a development-focused persona, impersonation reveals everything about a user including content explicitly hidden from HoD by the matrix (configuration data they no longer have edit rights on, etc.). If a future club wants to grant impersonation to a non-admin role, they can do it via the matrix (the cap is matrix-bridged), but the default is admin-only.

## How it works

Two stages with explicit return:

1. **Start.** From the People admin page (or any surface that lists users), click "Switch to this user" next to the target's row. Confirm in the modal. The page reloads as the target user — the dashboard renders exactly as they see it.
2. **Active.** A bright-yellow non-dismissible banner sits at the top of every page: *"Impersonating Anna de Vries. Every action is logged."* — with a "Switch back" button.
3. **End.** Click "Switch back" in the banner. The session is restored to the original admin. (Or close the browser — a daily cleanup cron closes orphan rows after 24 hours.)

Under the hood: a signed `tt_impersonator_id` cookie carries the actual admin's ID; `wp_set_auth_cookie` swaps the WordPress session to the target's identity. Both transitions write to `tt_impersonation_log`.

## What's logged

Every impersonation session writes a row to `tt_impersonation_log` with:

- **actor_user_id** — the admin
- **target_user_id** — who they impersonated
- **club_id** — enforces the tenant boundary
- **started_at** / **ended_at** — UTC timestamps
- **end_reason** — `manual` (clicked Switch back) / `expired` (daily cron closed an orphan) / `forced` / `session_ended`
- **actor_ip** / **actor_user_agent** — for forensics
- **reason** — optional admin-supplied note ("debugging ticket #1247"); empty by default

The log is separate from `tt_authorization_changelog` because they record different domains (matrix-config edits vs authentication events) and conflating them would muddy queries. Both Academy Admin and Head of Development can read the log; only Academy Admin can delete rows.

## Defence in depth

The service rejects with a distinct error code:

| Error code | Reason |
|------------|--------|
| `forbidden` | Actor doesn't hold `tt_impersonate_users`. |
| `target_not_found` | Target user doesn't exist. |
| `admin_target_forbidden` | Target also holds `tt_impersonate_users` — admin-on-admin is forbidden. |
| `self_impersonation` | Actor and target are the same user. |
| `already_impersonating` | The actor is already inside an impersonation session. Stacking is forbidden. |

In multi-tenant deployments (post-v1), cross-club impersonation requires an explicit `tt_super_admin` cap not granted by default.

## What you can and can't do during a session

**You can** do anything the target user could do — read their dashboard, view their player records, click links, navigate the site.

**You can't** trigger destructive admin operations from inside an impersonation session. Specifically blocked: matrix Apply, role grants, role revokes, backup restores, demo-data resets, all `tt_delete_*` admin handlers, and bulk imports. The reasoning is that an admin debugging a parent's view shouldn't accidentally trigger destructive operations from inside that session. Switch back to perform the destructive action.

Email and push notifications that would have been triggered by the target user's actions are also suppressed — you don't want a real notification firing because of an admin's debugging.

## Recommendations

- **Always supply a reason note** (e.g. ticket number) when starting a session so the audit log is searchable.
- **Switch back as soon as you're done** — don't leave sessions open. The 24-hour cron will eventually close orphans, but auditability is better when you click Switch back.
- **Don't impersonate without a clear reason.** Every session is logged with your IP and user agent; this is a permanent audit trail.

## Reviewing the log

The log is queryable via the REST API at `GET /wp-json/talenttrack/v1/impersonation/log` (cap-gated on the `impersonation_log` matrix entity — Academy Admin RCD, Head of Development R). A wp-admin surface for the log is planned but not in v1; until it ships, query the REST endpoint directly with appropriate filters.

## Out of scope

- A wp-admin audit surface beyond REST (planned for a follow-up).
- Cross-club impersonation in multi-tenant deployments (gated on `tt_super_admin` cap not granted by default).
- Automatic re-authentication for 2FA installs — `wp_set_auth_cookie` skips the 2FA challenge today; a `define( 'TT_IMPERSONATION_REQUIRES_2FA_REVERIFICATION', true )` constant is reserved for clubs that need it.
