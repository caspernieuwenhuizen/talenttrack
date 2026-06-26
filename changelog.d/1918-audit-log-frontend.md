# Audit log: Configuration tile now opens the frontend view (#1918)

The **Audit log** tile in Configuration → System no longer bounces into
wp-admin. It now opens the read-only frontend Audit log view
(`?tt_view=audit-log`) — a paginated, filterable browser over the academy's
`tt_audit_log` trail (who changed what, when), with an All-entries tab and a
Failed-logins aggregate. The tile is cap-gated to `tt_view_audit_log`, so it
only appears for holders who can read the log. The wp-admin tab
(`?page=tt-config&tab=audit`) stays as a power-user fallback.
