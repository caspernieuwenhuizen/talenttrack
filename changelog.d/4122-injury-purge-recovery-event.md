# Recycle bin: purging an injury also removes its recovery event (#4122)

An injury's journey has two events: the started event, filed under the injury, and the recovery event, filed as `injury_recovery`. The permanent delete only removed the first, so purging an injury with a return logged left "Injury recovered" on the player's journey, pointing at a medical record that no longer existed. Both events now go with the injury, and the purge report counts them together.
