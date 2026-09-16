# One answer to "is this a guardian of this player", and a clear rule about released players (#3476)

Six places in the code asked whether a user was a registered guardian of a
particular player, each with its own hand-written query, and none of them
checked which academy the link belonged to. On a single-academy install that
changes nothing today; on the authorization path it is the wrong thing to leave
lying around before there is a second one.

They had also drifted apart on a real question. A guardian whose child had been
released saw no parent dashboard and no child switcher — those read one query —
but could still open the child's record by typing the address, because the
access check read another. Neither behaviour was chosen; they were two
different queries that happened to disagree.

**A release now ends the guardian's access**, consistently: dashboard,
child switcher, the child's development pages, the permission matrix, the
development-plan print and the conversation endpoints all give the same
answer. The club has finished with the player, and the family's login to the
academy's record of them ends with it.

A family who needs their child's history after a release should ask for a
subject-access export — a deliberate act, with a record of who asked — rather
than relying on a login that quietly kept working. **Academies with a released
player whose parents still have an account should expect those parents to lose
access on update.**
