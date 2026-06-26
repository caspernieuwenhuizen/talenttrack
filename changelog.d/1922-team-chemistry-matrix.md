# Team chemistry access now follows the authorization matrix (#1922)

Team chemistry and Team blueprint access is now decided by the
authorization matrix instead of hardcoded role capabilities, with a single
shared decision (`TeamChemistryAccess`) behind both the rendered screens
and the REST API so the two can no longer disagree.

As a result, two roles that previously had access no longer do:
**assistant coaches and read-only observers no longer have access to team
chemistry** (the chemistry board and the team blueprint screens). This
matches the academy roles the matrix already grants the feature to — head
coaches, team managers, scouts, head of development, and academy admins
keep their access unchanged. The stale read capability is removed from the
read-only-observer role automatically on upgrade.
