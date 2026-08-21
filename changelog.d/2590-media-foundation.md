# Media library: foundation for photos and video on the player record (#2590)

Bump: minor

Groundwork for attaching photos and video to players, teams and activities. This release ships the storage and data model, not yet the screens —
a new **Media library** module appears in Modules, switchable on or off like any other, and does nothing visible until the upload and gallery
surfaces land.

Files are deliberately kept out of the WordPress media library, whose addresses are public and cannot be withdrawn. Media is stored in a private
folder under randomly-generated names, with every request for a file checked by TalentTrack before any bytes are sent. Photos have their embedded
information — including the location a phone records at a training ground — read for the capture date and then stripped before storage. File types
are decided by the file's own content rather than its name, and SVG is refused outright.

Permanently deleting a player now also deletes their media. A photo attached only to that player is removed along with its file; media also
attached to a team or an activity is kept, because those records still point at it. Previously a polymorphic attachment like this would have been
missed by the deletion sweep, leaving photographs on disk after an erasure request.

Two known limits, both documented on the Media library page: video files keep their own embedded data, because stripping it needs tooling the
plugin does not ship — use a Veo, Hudl, YouTube or Vimeo link to keep footage off the server. And the folder-level block on direct web access
works on Apache but not on nginx, where TalentTrack's own permission check is the boundary.
