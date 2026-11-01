# Spond: the sync preview no longer shows a false end-time change on matches (#4562)

The Spond integration monitor listed an end-time change on every match whose end the sync had filled in itself (kick-off + 105 minutes), as if the next sync would clear it. It will not. The preview now works out the times from the sync's own mapping, the match fallback end included, so it lists only the changes a sync would really make, and each event's `end_time` in `POST /teams/{id}/spond/preview` shows the end the sync would store. A match whose Spond end really changed, and a training with no Spond end, show their change as before.

Late matches imported with the old 23:59 end (before #4529) will move to the wrapped end (for example 00:15) on their next sync, and the preview will now show that as a real change.
