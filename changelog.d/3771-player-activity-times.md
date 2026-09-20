# A player's own activity screens name the time, not just the day (#3771)

The "My activities" surface knew the day of every training and fixture and
never said when any of them ran, so a player who wanted to know what time to
be at the pitch had to text a coach for something TalentTrack already stored.

"Coming up" now carries each activity's time window beside its date, and the
activity detail names the presence time when one is set, then the kick-off and
end time on a game, or a single time window on a training, tournament or other
activity. An activity with no times saved renders exactly as before — no empty
placeholders. The wording and the formatting come from the same helper the
staff activity detail and the peek panel use, so the two sides of the same
fixture agree.
