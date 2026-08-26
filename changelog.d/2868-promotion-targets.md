# Season rollover only offers teams a squad can actually move up to (#2868)

Bump: patch

The **Promote to** column offered every other team in the academy, with no
reference to age group. In a two-team academy that meant the older side was
offered the younger one as somewhere to be promoted to — the only destination it
had was a step backwards.

A team is now only offered as a target when its age group is genuinely older.
The oldest team in the academy gets no targets and keeps *No promotion / stays*,
which is the right answer for a leaving cohort: those players are handled
individually on the next step, as released or graduated.

The ordering comes from the order age groups are arranged in settings, so an
academy that names its categories its own way still gets sensible answers. Where
two categories sit at the same position — a specialist group alongside an age
band, say — neither is offered as a promotion for the other.

Moving a team *down* a category is deliberately still not possible here. That is
a correction rather than a season transition, and a screen whose whole vocabulary
is promotion is the wrong place for it.
