# Change a coach's role on a team without losing the assignment (#2608)

Bump: minor

Functional roles → Assignments now has an **Edit** action. Promoting an assistant
coach to head coach — or the reverse — used to mean unassigning the line and
building a new one from scratch, which silently discarded the original start date.
Editing changes the role in place and keeps the assignment's history.

The change is more than cosmetic: it rewrites the person's head-coach flag on that
team, so the coach lands on the right persona dashboard on their next page load and
workflow notifications route to them. Team and person stay fixed on the edit form —
moving either is a different assignment.

An **End date** field also appears on both the create and the edit form. The
assignment record has always carried one; the form simply never offered it, so an
assignment could not be closed off anywhere in the interface.
