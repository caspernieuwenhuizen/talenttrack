# Trial funnel reconciles: pending row, window label, scout links (#2347)

The Season · Trial funnel's Per-decision table now lists the outcomes of cases
opened in the window plus a **Pending (not yet decided)** row and a **Total**
row that sums to *Trial cases opened*, so the breakdown reconciles. The
Decision rate tile carries a one-line note that its numerator (cases decided,
by decision date) and denominator (cases opened, by open date) use different
windows. Each scout name in the Per-scout table links to that scout's Scout
report card, gated on the same `tt_view_reports` capability the card enforces.
