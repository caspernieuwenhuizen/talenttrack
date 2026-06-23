# Dutch eval-category labels no longer leak English (#1733)

Bump: patch

The New-evaluation rating screen (and anywhere eval categories render) leaked
English labels — "Tactical", "Physical", "Short pass", "Dribbling", "Offensive
positioning" — alongside the few that already showed Dutch. The category
vocabulary is seeded in `tt_eval_categories` and resolved through
`tt_translations`, but only a handful of Dutch rows existed, so the rest fell
back to the raw English label on nl_NL installs.

A new idempotent migration seeds the authoritative Dutch label for every
default eval-category and sub-skill straight into `tt_translations`, keyed by
the stable `category_key`. It only seeds a category whose label is still the
seeded English default, so an academy that renamed a category keeps its own
wording; re-running is a no-op. No `.po` or code change — `displayLabel()`
already prefers `tt_translations`.
