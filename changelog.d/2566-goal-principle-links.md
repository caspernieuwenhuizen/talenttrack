# Goals now say what they develop (#2566)

Bump: minor

Every screen where a goal is written — the goal form, the quick-add box on the
coach dashboard and the wp-admin form — now asks **What does this goal
develop?** and offers the principles of the club's active methodology. Tick as
many as apply; a goal can serve more than one, and the field is skippable
because a goal without a principle is still a good goal.

That link is what the rest of the system aims at. Training plans rank exercises
by how many of a squad's open development targets they touch, and the
per-principle reporting on the persona dashboard counts tagged goals — both of
which resolved to nothing on a real install, because the field existed on one
screen a coach never opened and was set on none of 109 goals.

Existing goals are left exactly as they are: a principle inferred from a goal's
title would be a guess, and guessed data in a coverage panel is worse than a
thin one. The panel fills up as goals are re-authored. A freshly generated demo
academy now shows the mechanism working end to end.
