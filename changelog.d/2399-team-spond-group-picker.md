# Head coaches pick their team's Spond group themselves (#2399)

Bump: patch

Connecting Spond for your own team stopped half-way: a head coach could save
the team's Spond login and test it, but linking the actual **group** still
happened on the team edit form, which most coaches can't open — so activities
didn't flow until an admin stepped in.

The **Spond connection** panel now includes the group picker. It appears once
the login works (listing groups needs a working Spond login, so before that the
panel says what to do rather than showing an empty dropdown), and the list is
cached for five minutes so re-opening the panel is instant.

If the group you pick is already linked to another team, the panel names that
team and warns you — then lets you save anyway. Two teams sharing one Spond
group is a normal setup for a combined age-group calendar; both teams simply
import the same events.

Access is scoped to the exact team, the same as the credential and test actions:
a coach can finish the setup for their own team and no one else's.
