# The Analytics explorer asks for permission; bulk invites ask whose team (#3161)

The Analytics explorer had no permission check of its own, and nothing in
front of it had one either. On an academy that had switched the explorer
on, anyone who could reach the dashboard could open its URL and group any
figure in the system by player or by team — with a link straight to each
profile. It now needs the same permission the Analytics page beside it
does, and asks for it before it exports anything.

**Bulk invite a team** took the team from the form and never checked it was
yours. A request built by hand could create account invitations for another
team's players, addressed to their guardians. The team must now be one you
coach, unless you are someone who may invite academy-wide.
