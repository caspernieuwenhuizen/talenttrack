<!-- audience: coach, admin -->

# Bulk exports

The **Exports** surface (`?tt_view=exports`) is the one place for the academy's bulk exporters — the whole-table / whole-season downloads, as opposed to the per-record exports (a player one-pager, a scouting-report PDF, a PDP) which stay on each record's own detail page where the relevant id is in context.

## Layout (v4.26.20+)

Exporters are grouped into purpose-based sections, and each exporter is a collapsed accordion block so the page stays scannable:

- **Squad & players** — Players list, Team roster + season stats, Federation registration (JSON).
- **Activities & attendance** — Attendance register, Team activity history, Team calendar (iCal).
- **Evaluations** — Evaluations export, Player evaluations (flat).
- **Goals** — Goals list.
- **Reports & people** — KPI snapshot, Coach / staff directory.
- **Admin & compliance** — Audit log, Full club-data backup, Demo-data round-trip.

Each block's collapsed header shows the export title plus a format badge per supported output (CSV / XLSX / PDF / ICS / JSON / ZIP), so you can see what an export produces without opening it. Expand a block to set its filters, pick a format (when more than one is offered), choose columns (for tabular exports) and run it.

Every block is cap-gated: you only see the exporters your role permits, and a section with no permitted exporter renders no heading. Running an export is unchanged — it posts to the export handler with a nonce and streams the file.

The blocks are native `<details>` disclosures: keyboard-accessible, screen-reader-friendly, and usable down to a 360px phone where they stack into a single column.
