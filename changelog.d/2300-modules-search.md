# Search box on the Modules & features page (#2300)

Bump: patch

The frontend **Modules & features** page (`?tt_view=modules`) now has a
search box at the top that filters the module cards and their nested feature
toggles live as you type, matching on name or description. A match inside a
feature auto-expands its module; empty categories drop out and an empty-state
line shows when nothing matches. With dozens of per-report and per-export
toggles, finding a specific one no longer means scrolling the whole list.
Client-side only — no reload, and the full list still renders with JavaScript
off.
