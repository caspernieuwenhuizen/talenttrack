---
title: Review gate — reports quality
group: developer
summary: The checklist a PR must pass when it touches a report view, a report query, a print or PDF renderer, a CSV export or a dashboard KPI.
audience: [dev]
order: 45
---

# Review gate: reports quality

**Triggered by** any change to a report view, a report query, a print or
PDF renderer, a CSV export, or a dashboard KPI.

**Run by** the architect. The reviewer ticks the list in the PR. QA checks
the screen, the print and the PDF in its walkthrough.

Every report answers a question about a player: how much did they play, how
often were they there, how are they rated. This gate keeps that answer the
same on every surface that shows it. The narrative reference is
[`docs/reports.md`](../reports.md).

## Checklist

- [ ] **Accuracy: one shared read per figure.** Every figure (minutes,
      attendance, ratings, goals) comes from the one domain read that owns
      it, never a local `SUM()` or a copy of the rule. The PR names the read.
- [ ] **Accuracy: totals reconcile.** A report total equals the count of its
      drill-down rows, and the same figure for the same player and period is
      equal on every report that shows it, with a test.
- [ ] **Accuracy: edge cases tested.** Duplicates, archived and guest rows,
      an empty period, and old data written before the current rule.
- [ ] **Consistency: one label source.** Labels come from the lookup or the
      shared translator (`LookupTranslator`, `LabelTranslator`), never a
      local map or a raw stored key. The same term means the same figure
      everywhere.
- [ ] **Consistency: one renderer.** Screen, print, PDF and REST apply the
      same rule and show the same numbers, the way the monthly team report's
      screen and print share one renderer.
- [ ] **Usability: filters.** Filters are visible, survive a reload in the
      URL, and an unknown filter value is refused, not dropped.
- [ ] **Usability: empty states say why.** "No matches in this period", "No
      minutes recorded yet": never a blank table, and never a bare 0 that
      means "no data".
- [ ] **Usability: mobile and export.** The report renders at 360 px without
      horizontal scroll ([`ux-mobile.md`](ux-mobile.md)), and an export
      carries the same columns and numbers as the screen.

## Sweep (quarterly)

For each figure (minutes, attendance, ratings, goals), grep the report
queries and renderers for a local `SUM()`, `COUNT()` or status list that
repeats the owning read's rule, and list each copy. Pick three players and
one period and compare the figure on every report that shows it, on screen,
in print, in the PDF and through REST. Open five reports on an empty period
and at 360 px. File each finding as an issue.
