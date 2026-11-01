# Evaluation wizard: a rating that can't be saved is reported, not dropped (#4617)

When a coach rates players from an activity and the wizard can't save some of those ratings (the player didn't take part, or the coach can't rate them on that activity), the coach is now told how many were not saved and why. Before, those ratings were dropped without a word, and if none could be saved the wizard said "No ratings were entered", which wasn't true. That message now shows only when nothing was typed.
