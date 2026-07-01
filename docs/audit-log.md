<!-- audience: admin -->

# Audit log

**Dashboard → Configuration → Audit log** (`?tt_view=audit-log`)

A read-only, paginated browser over the academy's audit trail — every settings change and sensitive-data access recorded by `AuditService` in `tt_audit_log`. It answers *who changed what, when*, so an operator can trace a configuration change or a sensitive-record view back to the user, time and IP behind it.

The frontend view is the canonical surface (#1918); the older wp-admin tab (`?page=tt-config&tab=audit`) stays available as a power-user fallback but Configuration no longer bounces you there.

## Access

The tile and the view are gated by the `tt_view_audit_log` capability — held by admins / club-admins through the authorization matrix, never by a role-name check. A user without the capability sees no Audit log tile in Configuration, and a direct visit to `?tt_view=audit-log` returns a permission notice. Every query is club-scoped (`club_id`), so a future multi-tenant install never leaks one academy's trail to another.

## What it shows

The **All entries** tab lists the trail newest-first, 50 rows per page:

| Column | What it shows |
| --- | --- |
| **When** | Timestamp the entry was recorded. |
| **User** | The actor — display name, or `#id` when the name is unavailable, or *(system)* for entries with no user (cron, migrations). |
| **Action** | The recorded action key (for example `config.update`, `lookup.needs_review`, `login_fail`). |
| **Entity** | The entity type the action touched, plus its `#id` when one applies. |
| **IP** | The source IP captured at the time. |
| **Payload** | The JSON detail recorded with the entry (old/new values, context). |

### Filters

Above the list, the shared filter bar narrows the trail. All filters are optional and combine:

- **Action** and **Entity** — dropdowns built from the distinct values actually present in the trail.
- **User #** — numeric (`inputmode="numeric"`); the WordPress user ID of the actor.
- **From** / **To** — a date range (`type="date"`).

On wide screens the filters sit in a single inline row; on phones and tablets they collapse behind a **Filters** button that opens a bottom sheet holding the same controls. Changing a dropdown applies immediately; **Clear** resets every filter. Pagination uses an `apage` query parameter so it never collides with WordPress's reserved `paged`.

### Failed logins

A second tab, **Failed logins**, aggregates `login_fail` entries over the last 7 and 30 days: a daily breakdown, the top-10 attempted usernames, and the top-10 source IPs. There is no automatic lockout — the view exists to surface volume so the operator can act when an unusual pattern emerges.

## REST

The same data is exposed read-only at `GET /wp-json/talenttrack/v1/audit-log`, gated by the same `tt_view_audit_log` capability and club-scoped in its `WHERE` clause. It accepts `action`, `entity_type`, `entity_id`, `user_id`, `date_from`, `date_to`, `page` and `per_page`, and returns paginated rows with `X-WP-Total` / `X-WP-TotalPages` headers. A future SaaS frontend can render the same trail without rebuilding the query. There is no write endpoint — the audit trail is append-only and is written only by `AuditService`.

## See also

- [Configuration — General](configuration-general.md)
- [Configuration — Lookups](configuration-lookups.md) (the canonical-language review tool writes `lookup.needs_review` entries here)
- [Access control](access-control.md)
