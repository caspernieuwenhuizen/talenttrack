# Audit log, usage statistics and migration tables on a phone read like every other table (#4917)

The audit log, the impersonation and failed-login tables, the usage statistics dashboard and its drill-downs, and the migrations list now use the shared table layout. On a phone each row is a card with each value beside its column's label, as on the other screens that have moved. An impersonation session that is still open now keeps its warning colour on a phone too. From 480px wide nothing changes. Screen readers read each table as a table with its column headers.
