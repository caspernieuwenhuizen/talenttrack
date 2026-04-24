<!-- type: feat -->

# Audit log viewer — frontend surface for browsing `tt_audit_log`

Raw idea (carved out during #0019 Sprint 5 shaping):

TalentTrack has a `tt_audit_log` table (created by migration 0002) that accumulates audit events across the plugin's life. Today there is no UI at all for viewing it — not in wp-admin, not on the frontend. The only way to see audit events is to query the database directly.

When #0019 Sprint 5 was being scoped, the audit log viewer was originally included alongside the other admin-tier surfaces. During shaping it was deferred out because:

- It has genuinely open questions that weren't worth answering in the crowded Sprint 5 context.
- Those questions deserve dedicated thinking, not hurried decisions.
- Half-built audit tooling is worse than no audit tooling (misleads admins about what's being tracked).

This idea captures the carve-out so it isn't lost.

## What needs a proper shaping session

Before this becomes a spec, answer:

1. **Retention policy** — do audit entries live forever, or roll off after N days? Current table has no retention, so it'll grow indefinitely unless we decide otherwise.
2. **What actions are logged?** Today, not all actions are audited. Need to audit the audit coverage: what fires an audit event, what doesn't? Which gaps matter?
3. **Export format** — CSV? JSON? Both? What does an admin actually do with exported audit data?
4. **Privacy/redaction** — does any logged content include PII that needs redaction for export or for non-admin viewers?
5. **Filter dimensions** — who (user), what (action), when (date range), which entity (player ID, etc.). What's the MVP?
6. **Who should be able to view the audit log?** Admins only? HoD? This affects the capability gate.
7. **Does it generate an audit trail for itself?** (Viewing the audit log — is that itself an audited event? Probably yes, for at least "export" actions.)

## Rough scope (before shaping)

- Frontend view under the Administration tile group (from #0019 Sprint 5). Gated by appropriate capability.
- List view powered by `FrontendListTable` (from #0019 Sprint 2).
- Filters: user, action type, entity type, date range.
- Detail view per event: full context, before/after state where applicable.
- Export to CSV (open question above).
- Retention policy UI (open question above).

## Out of scope (for v1)

- Real-time streaming of audit events.
- Integration with external SIEM / log-shipping systems.
- Writing new audit events that don't exist today — this idea is about *viewing*, not expanding coverage. Coverage expansion is a separate concern.

## Touches (when specced)

New:
- `src/Shared/Frontend/FrontendAuditLogView.php`
- `includes/REST/AuditLog_Controller.php`
- Possibly `src/Modules/Audit/` if we consolidate logic (currently scattered)

Existing:
- `tt_audit_log` table schema may need indexes added if browsing + filtering makes queries slow.

## Sequence position

After #0019 finishes. Not urgent — the audit log has been accumulating entries with no viewer for its entire life; a few more months is fine.

## Estimated effort

~6–8 hours once shaped. Could be more if retention policy or export format require schema changes or background jobs.
