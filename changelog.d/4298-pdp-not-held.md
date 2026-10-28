# A PDP talk that did not take place can be marked as not held, so the cycle carries on (#4298)

Bump: minor

When a development talk never happens, because the player was ill, a week was cancelled or the coach changed, the coach of the PDP file or the head of development can now mark the current talk as **Not held**, with a short reason. The next talk in the cycle then becomes the current one, so the rest of the season, the end-of-season talk included, can be recorded and signed as usual. Before, one missed talk stopped the cycle for good. The not-held talk stays on the file with its date and reason, and the player and their parents see the same on My PDP. It never shows as overdue, the player is not asked for a reflection on it, PDP planning counts it as not held rather than conducted, and it counts as closed for the end-of-season verdict. The mark can be undone until a later talk moves on. This release adds two columns to the PDP conversations table; the migration runs on update.
