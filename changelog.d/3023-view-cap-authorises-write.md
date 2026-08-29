# Permission to read something no longer permits changing it (#3023)

Bump: patch

Five wp-admin screens gated their **save** on a capability whose name says *view*: Category Weights, Custom Fields, Evaluation Categories, Eval Type Categories, and People. On each one the menu entry that leads there was already gated on the narrower read capability, so the page was reachable by URL for someone the entry point deliberately hides it from — and the write behind it was authorised by permission to read. Category Weights was the sharpest case: a view capability decided who could rewrite the weighting behind every composite rating in the academy.

Each now reads with its view capability and writes with its edit one. Both already existed in every case; the pages simply did not consult them.

**This changes who can do what on existing installs, chiefly for Head of Development.** They hold every per-area *view* capability by design, which adds up to the `tt_view_settings` umbrella — and their edit capabilities were deliberately removed when the settings permissions were split. These pages were handing the edit back through the view umbrella. A Head of Development who genuinely needs to change one of these should be granted the matching edit capability, which is now a deliberate act. Club Admin and administrator are unaffected; coaches and team managers never held the umbrella.

Two write actions still name a read capability and are recorded in the access-control guide rather than quietly widened, because no narrower capability exists yet and inventing one is a change to the permission model in its own right: granting or revoking a role on a person, and archiving a scheduled report.
