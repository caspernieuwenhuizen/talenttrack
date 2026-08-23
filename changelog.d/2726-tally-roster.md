# Match analysis: the roster is a tally sheet, and the wizard is styled again (#2726)

Bump: patch

Two fixes to the match analysis that shipped in v4.96.0, one of them
visible the moment you opened the wizard.

**The wizard steps rendered unstyled.** The stylesheet was enqueued on the
first step only, and every wizard step is its own page load — so steps two
to five arrived with no CSS. That is worse than it sounds for this screen:
the marker chips are a hidden radio plus a styled label, so losing the
stylesheet turned them back into raw browser radio buttons stacked down the
page. Assets are now asked for once per step, from one place.

**Marking players is a tally sheet now, not fourteen forms.** The squad
renders as a grid of names; tap one and pick ▲ Stood out, ● As expected or
▼ Below par. The name takes that colour and the player drops into a Notes
list underneath, where the note and phase fields live. Only the players you
marked have a note field, so a squad of fourteen fits on one phone screen
and an analysis you have not started has no text boxes on it at all.

Nothing about what gets stored has changed, and the whole squad is still
listed — that is what stops the quiet players being skipped. What changed is
that the page no longer asks fourteen questions to collect two answers.

The section ratings (Went well / Mixed / Needs work) also moved from a
wrapping row to a two-column grid on phones, where the Dutch labels no
longer fit on one line.

Without JavaScript the roster falls back to the plain form — every player,
every field — so nothing is lost, it is just longer.
