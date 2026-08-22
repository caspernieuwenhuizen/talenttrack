# One gate for what an install can show you (#2645)

Bump: minor

Two corpora in the plugin carry the same four keys — `module`, `feature`,
`tier`, `capability` — and both need the same question answered: can this
install, and this reader, have this? The help topics under `docs/` and the
courses under `courses/` were about to grow two separate answers to that,
which would have drifted the first time anyone added a fifth gate or fixed a
bug in one of them.

`ContentGate` is now the single resolver, in shared space so neither module
owns it. Courses consume it today; the help corpus consumes it when its own
gating work lands.

The verdict it returns is not a boolean, because the three ways content can
be out of reach are not interchangeable to the person in front of it.
**Unavailable** means this install does not have it and no permission changes
that. **Denied** means it is here and somebody else can see it. **Locked**
means you will be able to, once you have done something first. Showing the
same message for all three is how a product ends up telling a head of academy
to ask their administrator about a feature their licence does not include.

On top of that, courses gain the two gates that are about the learner rather
than the install: a course can require another course first, and a sequential
course opens one lesson at a time.

Two decisions worth knowing. Content this install cannot have is **absent**
and returns a 404, not a 403 — a 403 confirms the thing exists here, which is
what hiding it was for. And locked content stays **listed**, because hiding a
locked lesson makes a course look shorter than it is and nobody can work
towards something they cannot see.

The gate is enforced where it can actually be walked around: submitting
progress for a locked lesson is refused, not just hidden in the reader.

An unknown key value leaves content visible rather than hiding it. A typo in a
feature name silently removing a topic is a bug found months later, if ever;
the corpus lints are what catch the typo.
