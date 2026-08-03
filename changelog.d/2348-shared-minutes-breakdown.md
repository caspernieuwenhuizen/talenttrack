# One shared per-match minutes breakdown component (#2348)

The per-match minutes breakdown table used by the Team · Minutes distribution
report and the Analytics minutes-played report is now a single shared component
(`MinutesBreakdown`), replacing two near-identical copies that had already
drifted in markup. Both reports render identical rows that still reconcile
exactly to the player's total. Presentation-only — no query or data change.
