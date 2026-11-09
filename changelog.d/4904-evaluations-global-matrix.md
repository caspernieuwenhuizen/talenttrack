# Evaluations list: academy-wide access follows the authorization matrix (#4904)

The evaluations list now decides between the academy-wide list and the
coach's team-scoped list by `evaluations` read at global scope in the
authorization matrix, instead of comparing two role names. A role an academy
grants that read now gets the academy-wide list. The head of development and
the academy admin keep it, whether or not the matrix is applied. The read-only
observer now lists every evaluation too, as the owner decided; the written
notes, player feedback and category notes stay hidden for them through the
sensitive-field registry, and the list's search still leaves the notes out.
A coach keeps their teams' evaluations plus the ones they wrote.
