# Parents and players search on the shared engine (#4673)

Bump: minor

Parents and players now use the same search box and results page as staff.
A parent finds their own children's activities, evaluations, goals and
messages, and nothing of another family; evaluation types match in the
parent's own language, so *wedstrijdevaluatie* finds a match evaluation, and
dates such as *3 nov* or *2026-11-03* work everywhere. A player finds
themselves, their own team's activities, their teammates by name (opening the
teammate card) and their own evaluations and goals, never another player's.
Neither gets quick actions or settings. A parent's activity result opens on
the right child, and with more than one child an evaluation or goal says
which child it is about. The separate parent search page and its header
magnifier are gone; `GET /me/search` still answers in its old shape, built
from the same engine, and the old help topic opens the search topic.

The teammate card follows the roster, like My Team: it opens for an active
member of the player's own team, not for a trialist or a released or
inactive player who still carries the team id. Search lists teammates by the
same rule.
