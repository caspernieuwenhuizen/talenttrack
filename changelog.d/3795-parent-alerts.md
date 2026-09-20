# Alerts a parent can actually use, about their own child (#3795)

Bump: minor

A parent opening their alert settings was shown the whole catalogue — certificates expiring, teams without a head coach, invitations waiting to be sent — twenty-one conditions, not one of them about their child. Meanwhile `GET /alerts` returned an empty list, so the screen was complete-looking and attached to nothing. A family checking their son's record by hand every Sunday was doing it because nothing in TalentTrack ever told them anything had changed.

The alert preferences matrix is now filtered by **audience**: a definition reaches a person when the record it is about is in that person's scope. Deliberately not by capability — the four conditions a family most wants (no recent evaluation, an overdue goal, a PDP cycle with no conversation, an evaluation never shared) all declare a staff capability, because staff are who fixes them, so a capability filter would have removed exactly those. A parent's scope is their own linked children, read from the same guardian link the rest of the product uses, and re-checked on every hourly run.

Six alerts now reach a parent: those four, plus two written for families from the start — **New evaluation shared with the family** and **A goal for your child was updated**. What a family reads is written for a family: the fact, the child's name and nothing else, linking to their own child's record rather than to a staff screen. No ratings, no internal notes, no coach names.

The evaluation alert fires on the **share**, never on the save. An evaluation is recorded days or weeks before anyone decides the player and their family may read it, and an alert on the save would tell a family an assessment of their child exists before the academy chose to release it. Nothing is announced until the player-facing feedback has been written.

Staff matrices are unchanged, the two family-only alerts never appear on one, and email digests stay opt-in for everybody. Folds in #3803, which asked for the reuse half of this from the parent's side.
