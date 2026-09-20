# The demo wipe deletes large batches in full, and says so when it cannot (#3813)

Wiping a demo batch built one `DELETE … WHERE id IN (…)` per entity type with a placeholder for every tagged row. Evaluation ratings run at roughly twenty-five per evaluation, so a medium batch produced a statement several megabytes long; MySQL refused it, the wipe recorded "0 rows deleted", and then dropped the demo tags anyway. The rows survived with nothing left to say they were demo data — 298,000 of them on the install where this was measured — and a second wipe could no longer find them.

Every id-set delete in the cleaner is now issued in chunks of a thousand, and the tags for an entity type are only dropped once its delete has actually landed. A refused delete is reported as a failure rather than as a row count of zero, and the demo-data screen now says which entity types were left behind and that the wipe should be run again.

Installs already in this state are not repaired by re-running the wipe — the tags that would have found those rows are gone — so they need an orphan sweep by foreign key instead.
