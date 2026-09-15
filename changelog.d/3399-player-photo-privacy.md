# Player photographs are no longer public files (#3399)

Bump: minor

Every player photograph was stored as a plain `wp-content/uploads/` web
address and served straight from it. That meant a photograph of a child sat
at a guessable path with no sign-in check of any kind: anyone holding the
link could open it, whether or not they had an account, and whether or not
they had ever been given access to the academy.

Photographs now live in the same private store the rest of TalentTrack's
media uses, behind the same check. A photo is served only to somebody who
is signed in and allowed to see that player, and a link copied out of the
page stops working for anybody else.

**Existing photographs are moved for you when you upgrade, and the old
public copies are deleted.** That is deliberate: leaving them in place would
have fixed the screens while every address already written down, cached, or
sitting in a backup kept working forever. Anything that linked directly to a
player's photo file — outside TalentTrack — will stop resolving, and that is
the point of the change rather than a side effect.

Photos hosted somewhere else entirely, such as a club's own CDN, are left
exactly as they are.

Uploading a photo works the same way it always has. What happens afterwards
is different: the file is moved into the private store immediately and the
public copy is removed, so picking a photo no longer leaves one behind in
the media library.

Printed sheets and PDF exports embed the picture in the file itself, so a
one-pager still shows a face when it is opened later or on another machine.

One thing that never worked now does: the player card was reading a field
that has never existed in the database, so it has shown a blank avatar on
every install since it was written. It shows the player's photo.
