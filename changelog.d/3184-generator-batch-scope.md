# A second demo generation run builds a full academy, not a thinner one (#3184)

Generating demo data twice into the same club produced fewer rows the second
time. Two generators picked their subjects from the whole club rather than from
the batch they were writing — match analyses looked at every played match,
training observations at every completed run — so the second run met the first
run's work and skipped it. The counts on screen were honest about what had been
written and misleading about why.

Both now read only what their own run created, and the "roughly two in three"
choices key off a subject's position in that run rather than off its database
id, so the same preset and seed produce the same academy wherever it is
generated.

Staff development and knowledge courses still work from the whole club, and say
so in the docs: those are about the people the academy employs, who may already
exist rather than having been generated. Also fixed: the training-run generator
looked up trainings without filtering by club.
