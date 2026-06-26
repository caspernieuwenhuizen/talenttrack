# PDP visibility: unify frontend and REST behind one matrix-aware check (#1923)

PDP-file access is now decided in a single place (`PdpAccess`), so the
rendered files tab and every REST surface answer the same question. This
closes the frontend/REST divergence (#1758) where a Head of Development who
does not personally coach a player was denied the files tab even though the
API let them through. The PDP REST endpoints that previously authorised on
"is the user logged in?" now check capabilities via the authorization
matrix, and the verdict sign-off attribution no longer relies on a role-name
string compare. Effective access is unchanged for every persona — this
removes drift and a legacy auth smell without widening or narrowing anyone.
