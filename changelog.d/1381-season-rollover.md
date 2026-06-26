# Season rollover — bulk cohort promotion (#1381)

Bump: minor

A new end-of-season tool moves whole squads up an age group in one pass and
writes a dated journey event for every affected player. The flow has three
steps — map each source team to a target team, choose which players move (and
whether each is promoted, released or graduated), then review the exact
changes before confirming.

Safety is built in: a full backup runs automatically before any record is
touched, and if the backup fails the rollover is aborted with nothing
changed. The confirm step posts through admin-post.php and redirects back
(post/redirect/get), so refreshing the result page cannot re-run the move.

Released players are deliberately **left active** — they get a dated
`released` journey event but are not archived, so the data-retention clock
never starts here. There is no season-entity creation or assignment in this
version; the rollover is purely a team move plus a journey event.

This is a bulk operation on existing records, so per the wizard-first rule it
takes wizard **exemption (b)** (bulk operations) and ships as a dedicated
multi-step view rather than a record-creation wizard. The same logic is
reachable over REST at `POST /talenttrack/v1/season-rollover/plan` (dry-run)
and `POST /talenttrack/v1/season-rollover/execute`.
