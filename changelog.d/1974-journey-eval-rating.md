# Player journey now records the actual evaluation rating (#1974)

Bump: patch

The player-journey evaluation event (`evaluation_completed`) read a
non-existent `overall_rating` column from `tt_evaluations`, so the query
errored and every evaluation was recorded on the timeline with an overall
of `0.0`. It now reads the real `rating` column, both for live saves
(`JourneyEventSubscriber`) and for the historical backfill
(`JourneyBackfillService`). Existing zeroed events are corrected the next
time the journey is rebuilt; no schema change.
