# Every screen now says whether it is meant for a phone (#2807)

Bump: minor

Until now the app had only ever been told about twenty-five of its screens:
a handful were marked "needs a desktop", three were marked "built for a
phone", and the remaining hundred and twenty-five were treated as phone-
friendly by default — not because anyone had decided they were, but because
nobody had said otherwise.

All 151 screens now carry an explicit answer, each with a sentence saying
why. Fifty-six screens that a phone could open and shouldn't have — bulk
grids, permission matrices, imports, integration setup — now say so and
offer to email the link instead. Twenty-eight are recognised as phone-first
and get the mobile layout properly. Analytics, Reports and Usage statistics
open on a phone for the first time.

Academies that would rather their people squint at a desktop layout can
still switch the whole thing off in Configuration, and tablets are never
affected.
