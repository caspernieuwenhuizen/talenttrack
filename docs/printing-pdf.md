# Printing & PDF export

TalentTrack can generate clean, print-optimized views for player reports — useful for meetings with parents, handouts during evaluations, or archiving paper trails.

## Print buttons

Pages that support printing have a print icon in their top-right:

- **Player rate cards** — full rate card with FIFA card, radar, trends
- **Evaluation detail view** — one evaluation with all its categories and notes

## What happens on print

Clicking the print button navigates to a URL like `?tt_print=<id>` which:

1. The PrintRouter (hooked early in WordPress's request pipeline) intercepts
2. Renders a standalone HTML page with no admin chrome, no theme, no sidebar
3. Academy logo, branded colors, clean typography
4. Auto-opens the browser's print dialog via JavaScript

## Exporting to PDF

Use your browser's **Save as PDF** in the print dialog. Chrome, Safari, Firefox, and Edge all support this natively — no server-side PDF library needed.

The print view opens in a new window with Print, Download PDF, and **Close window** buttons. Close when you're done — the main TalentTrack tab stays exactly where you left it.

Set page size to A4 (or Letter for US). Set margins to "Default" or "Normal". Landscape works better for rate cards (more horizontal room for the radar + trend charts side-by-side).

## Styling

The print layout is CSS-only; what you see is what prints. Colors and logo come from your [Configuration](?page=tt-docs&topic=configuration-branding). If your PDF looks bland, check that your primary color and logo are set.
