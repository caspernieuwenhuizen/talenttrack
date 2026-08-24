---
title: Bulk exports
group: analytics
summary: Whole-table and whole-season downloads, as opposed to per-record exports.
audience: [user, admin]
module: TT\Modules\Export\ExportModule
order: 70
---

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

## What the values look like

Human-facing exports carry the same labels you see on screen, in the language the academy runs in. A player's status reads *Actief* rather than `active`, a coach's role reads *Trainer* rather than `coach`, and preferred positions read *Centrale verdediger / Linksback* rather than `["CB","LB"]`. This file is usually the one that leaves the academy — to a parent, a federation desk or the board — so it should not need decoding by whoever opens it.

A position code the academy added itself, which has no label yet, appears as the code. It is never dropped.

Three exports deliberately keep raw values, because something reads them back: **Demo-data round-trip** (re-imported, so codes must survive unchanged), **Full club-data backup** (a fidelity dump) and the **subject-access export** (the record as stored).

## Turning individual export tiles off (admin)

An academy admin can switch **individual export tiles** off — for example to hide the Audit log, the Full club-data backup, or Federation registration — without disabling file formats or the whole Exports surface. The toggles live on the **Modules** management page, under the **Export** module: one switch per tile (`Export: Players list`, `Export: Audit log`, …), grouped with the rest of the per-academy feature toggles.

All tiles are **enabled by default**, so nothing changes until you turn one off. Disabling a tile:

- hides it from the Exports page for everyone in the academy (including admins — so an academy that doesn't want its own backups exposed can hide them), and
- rejects that export at the endpoint, so it can't be run via a saved or hand-built link either.

The toggle only ever **narrows** access — a user still needs the underlying capability to see an enabled tile. Toggles are per-academy (club-scoped) and audit-logged.

The blocks are native `<details>` disclosures: keyboard-accessible, screen-reader-friendly, and usable down to a 360px phone where they stack into a single column.
