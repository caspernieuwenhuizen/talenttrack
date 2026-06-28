# Strava integration — activity ingest (#2058)

Bump: minor

Imports a connected player's Strava activities onto their TalentTrack record
(epic #2002). When an activity is recorded, it's fetched with the player's
token and saved to the player's own activity list — distance, duration, pace
and elevation only. Heart-rate and other biometric data are never read or
stored, by design, so the integration works for the academy's mostly-minor
cohort without tripping Strava's under-16 heart-rate restriction. Deleting an
activity in Strava (or disconnecting) archives it on our side. A new read
endpoint exposes a player's imported activities for the profile timeline.
