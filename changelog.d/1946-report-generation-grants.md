# Authorization: bridge report generation to the matrix (#1946)

The report-generation capability `tt_generate_report` (distinct from
`tt_generate_scout_report`) is now resolved from the authorization matrix once it is
active. Generating a report is a create act, so the cap is bridged through
`LegacyCapMapper` to `reports:create_delete`. Because the `reports` matrix entity
previously granted coaches and the Head of Development only read access, a naive
bridge would have revoked generation from them — so access is preserved by adding
the `create_delete` grant instead: head coaches and assistant coaches at team scope,
the Head of Development globally (the Academy Admin already held it). Both coach
personas are seeded so assistant coaches keep generation (the `tt_coach` role backs
both). Team managers, scouts, players and parents keep read-only and gain nothing.
A backfill migration adds the new grants to existing installs.
