# First-class sub-principles + JO13-1 formation diagram fix (#2369)

Bump: minor

Methodology sub-principles are now a first-class entity: the concrete per-line
coaching points that support each main principle (grouped by game phase, then
by line — aanvallers / middenvelders / verdedigers / algemeen). They have their
own read surface under the **Spelprincipes** tab, a **Sub-principes** authoring
tab, and full REST CRUD at `/methodology/sub-principles`. The JO13-1 Hedel set
ships with its complete per-line sub-principes seeded from the playing-style
document. Separately, the JO13-1 **1-4-3-3** formation now renders correctly on
both the Formaties and Visie tabs — its diagram coordinates were missing, so it
previously fell back to a generic shape.

Wizard plan: exemption (a) — a sub-principle is a lookup-like, single-line
coaching note authored under an existing principle/phase, so it takes the flat
inline-editor path like the other methodology vocabulary tabs; a multi-step
wizard would add friction without value.
