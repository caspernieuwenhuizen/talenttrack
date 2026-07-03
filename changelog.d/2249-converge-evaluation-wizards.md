# One evaluation wizard behind every door (#2249)

Bump: minor

The dashboard "Mark attendance" hero, the activity completion buttons and
the New-evaluation wizard now all reach the same unified flow. The old
`mark-attendance` wizard is now a thin alias that seeds the activity
branch, so existing links and bookmarks keep working. The activity path
is attendance → "rate now?" → quick rating; behaviour rating moved to the
"Evaluate 1 player" deep path so it isn't lost. No data-model change —
the same attendance and evaluation rows are written as before.
