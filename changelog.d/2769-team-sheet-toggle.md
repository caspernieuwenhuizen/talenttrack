# The match day team sheet has its own switch (#2769)

Bump: minor

One setting used to gate
both match-prep exports, so an academy that files match forms digitally could
only hide the **Wedstrijdformulier afdrukken** button by also losing **PDF
exporteren** — the sheet the coach actually takes to the touchline. They are two
documents for two readers: the coach's carries the plan, the referee's carries
identity and eligibility.

**Match day team sheet** is a new feature under Match prep, on by default, so
nothing changes for an academy that still hands paper to the referee. Switch it
off and the button leaves the toolbar, the print URL refuses, and the
server-side export on the Exports page stops offering it — while the coach's own
PDF is untouched. That last part is new too: the server-side team-sheet export
previously ran whatever the toggle said.
