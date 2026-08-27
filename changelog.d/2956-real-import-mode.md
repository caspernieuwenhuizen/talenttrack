# Imported club records are no longer treated as demo data (#2956)

Bump: minor

A spreadsheet import can now bring in a club's real teams, players and
staff. Those records are tracked separately from generated demo data, in
their own tables, so clearing out demo data can never reach them — before
this, anything the importer created was recorded as demo data and a
routine "wipe demo data" would have deleted it.

An import can also be checked before it is applied: uploading now reports
what the workbook contains, and what needs fixing, without creating a
single record until you say so.

Both are available over the API as well as in the product.
