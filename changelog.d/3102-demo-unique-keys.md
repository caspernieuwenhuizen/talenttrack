# Generating demo data twice no longer collides with itself (#3102)

Generating a demo preset into a club that already had one printed database
errors and quietly wrote fewer rows than the operator was told. Four generators
read their subjects from the whole club rather than from the batch they were
writing, met the rows a previous run had left, and let the INSERT fail against a
unique key instead of skipping them.

They now skip deliberately: a match that already has an analysis does not get a
second one, a training run that has already been observed is not observed again,
and a person who already has a staff development file or a course enrolment is
left alone. The counts the second run reports are the rows it actually wrote.

The other half of the same bug was subtler. Demo generation is reproducible on
purpose — the same preset and seed produce the same dataset — and uuids were
being drawn from that same seeded stream, so a second run re-minted
byte-for-byte the same uuid and collided with the `uk_uuid` key the first run
had filled. Reproducibility was always meant to cover the dataset, not the
identities, so uuids now come from the system random source. Nothing about the
generated academy changes.

Documented under *Generating twice into the same club*, so a lower second count
reads as what it is rather than as a failure.
