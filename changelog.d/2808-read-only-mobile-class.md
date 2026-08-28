# The read-only mobile class now actually reads only (#2808)

Bump: patch

Ten analysis surfaces are classified `read_only` — readable on a phone,
edited at a desk. The class, its config entries and `isReadOnly()` shipped
with the classification work, but nothing consumed them, so a `read_only`
surface behaved exactly like an ordinary one.

On a phone these surfaces now render without the controls that write. In
practice that is one control: the saved-views strip's save, rename,
overwrite and delete. The reports themselves carry no form that mutates
anything. The apply links stay — applying a saved view is a plain link, and
it is the reason to show the strip on a phone at all — and the script that
only exists to save and delete is no longer loaded there.

Nothing changes on desktop or tablet, the surfaces are not gated behind the
desktop prompt, and `?force_mobile=1` opts out for a visit exactly as it
does for a desktop-only surface. Controls are removed rather than disabled,
and there is no banner: the class means the surface reads on a phone, and a
row of greyed-out buttons says the opposite.

The three conditions every classification gate shares — a phone, the club
setting on, no per-visit override — now live in one place
(`MobileDetector::phoneGateApplies()`) instead of being spelled out
per gate, which is how `?force_mobile=1` would otherwise end up honoured on
one class and quietly ignored on another.
