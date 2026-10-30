# Tournaments open again for coaches and staff when the authorization matrix is on (#4386)

The Tournaments tile was registered against the matrix entity `tournament`,
while the authorization matrix grants `tournaments`. On an install with the
matrix active, every non-administrator got "no access" on the tournaments
screen, including the head coaches and head of development who hold the
right. The tile now names the right entity, and a new test checks that every
registered tile's entity is one the matrix actually grants on, so this kind
of mismatch cannot come back unnoticed.
