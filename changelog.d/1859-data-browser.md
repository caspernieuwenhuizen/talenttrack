# Data Browser — read-only frontend table browser (#1859)

Bump: minor

A new **Data Browser** tile (under Administration, for administrators and Club Admins only) lets you browse the raw data behind TalentTrack, read-only. Each `tt_*` table is listed with a friendly label, description and row count; opening one shows semantic column headers with explanations, the actual stored rows (paginated and searchable), the tables it connects to, and clickable foreign keys that jump to the referenced row. Core player-centric tables get hand-written labels; the rest fall back to humanised names. Tables holding sensitive data about minors (medical, safeguarding, family) are badged, and opening one is recorded in the audit log. The same data is exposed read-only over the REST API at `/talenttrack/v1/data-browser`.
