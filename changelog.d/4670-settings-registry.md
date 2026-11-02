# Configuration grouped by task: ten sections, every setting once, and a filter box (#4670)

Bump: minor

The Configuration page now groups its settings by what an administrator wants
to do, in ten sections that always come in the same order. Each section opens
with the question it answers: Academy, Players & teams, Development &
evaluations, Activities & matches, Lookups, People & access, Messages & tasks,
Data, Features & plan and System. The old page had about twenty sections, one
per owning module. Every setting now appears once: the access matrix is no
longer listed twice, and setup tiles are no longer shown a second time.
Custom fields, evaluation categories and their weights had a screen but no
link anywhere in the app; they now have an entry. A **Find a setting** box
filters as you type, by name, description and extra words in Dutch and
English (*logo*, *weging*, *spond*, *2fa*). It ignores capitals and accents
and says so when nothing matches.

The System section and the access matrix are for academy administrators only.
A switched-off module's settings stay findable for an administrator, marked
*off* under Features & plan, with a link to turn the module back on. A
section with fewer than three settings you may open folds into the next one.

`GET /talenttrack/v1/settings-entries` returns the same list, limited to what
the caller may open. A new CI check, `tools/check-settings-entries.php`, fails
any settings screen that has no entry. The wp-admin Configuration page is
unchanged.
