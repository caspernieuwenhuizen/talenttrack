# The persona switcher no longer takes capabilities away (#1982)

Bump: patch

Someone who holds two personas — most often a coach whose own child is in the
academy — lost every staff capability the moment they switched the dashboard to
their second persona. The choice is stored on the account, so the loss followed
them across sessions, browsers and devices, and nothing on screen said why: a
coach would simply find that player notes they wrote last week had disappeared.

Authorization now resolves against every persona a user holds, and any one of
them granting access is enough. The switcher keeps doing what its name says —
choosing which persona the interface is dressed as. To act as another role with
that role's permissions, Impersonation and the matrix Preview page still do
exactly that, visibly, for as long as you leave them on.
