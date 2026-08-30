# Trials that end through the workflow tasks now close on the timeline (#3138)

A trial case can end in six ways, and only three of them were being announced.
The other three are written by the trial-group workflow tasks, which wrote the
case row directly, so a trial that ended because the family declined the offered
place showed on the player's journey as a trial that started and never finished.
The accept branch of *Await team-offer decision* and the final-decline branch of
*Review trial group membership* had the same gap: no *Signed after trial*, no
*Released after trial*, and no move of the player's status.

Recording a decision now goes through one place, which announces it, and the
workflow tasks use it. Which decisions reach the timeline is a separate
judgement: *Continue in the trial group* and *Offered a team place* write nothing
on purpose, because the first says the trial is still running and the second is
mid-conversation. Writing *Trial ended* for either would be actively wrong rather
than merely missing.

The player's status is now written by one owner instead of two. Trials decided
before this stay as they were unless the journey is rebuilt.
