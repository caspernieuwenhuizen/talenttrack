# Data Browser search now matches column names (#2161)

Bump: minor

The Data Browser index search now also matches column names, so typing
"minutes", "club_id" or "uuid" surfaces every table that has a matching column —
not just tables whose name or description mention it. When a table surfaces
because of a column, the result row shows which column matched. Existing
table-name / description matching and the table-page row-value search are
unchanged. Column lists are already cached per table, so there is no extra
query cost.
