# Commercial mode is now a property of the install, and there is a way to record what a club bought (#3466)

Bump: minor

Whether an install enforces plans was decided in `talenttrack.php`, which
meant it was decided for the whole fleet at once: turning it on for one
install would have turned it on for every install on the next update.
`TT_COMMERCIAL_MODE` is now only a default, applied when `wp-config.php`
has not already said otherwise. An install opts in with one line in its own
`wp-config.php`, and updating the plugin never changes that answer.

The second half is the part that made commercial mode unusable rather than
merely awkward. An install in commercial mode asks the control plane what
the club is entitled to, keeps a local copy of the answer, and falls back
to Not activated when there isn't one — and nothing anywhere wrote that
copy. Every install flipped on would have locked itself to one team and
twenty-five players on contact.

`wp tt entitlement show | set --tier=… | clear` writes it. `show` reports
the recorded plan, how old it is, whether it is due a refresh, whether it
is still honoured, and the plan the install actually resolves to — which
differs from the recorded one when commercial mode is off or a developer
override is live. An unrecognised tier is refused rather than quietly
normalised to Not activated, which is the shape this bug would otherwise
have taken on a paying club's install.

It needs shell access on purpose. There is still no screen, no setting and
no REST route that writes a plan, because what a club is entitled to is not
something the club's own site can be talked into changing.

Nothing changes on an install that has not defined the constant, which is
all of them today.
