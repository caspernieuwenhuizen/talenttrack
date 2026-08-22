# Media library: demo content, storage visibility, and the off-switch verified (#2596)

Bump: patch

The last of the media library work — the parts that make it a finished feature rather than a set of screens.

A demo academy now has media in it: a squad photo per team, a few player portraits and one external video link, so the media tabs show what they
are for instead of sitting empty. The placeholder images are drawn when the demo data is generated rather than shipped with the plugin, and nothing
is fetched over the internet, so generating a demo academy works offline.

Once an academy has media stored, the total appears as **Media stored** on the academy admin's system-health strip. Uploaded video is not small
and nothing reclaims it automatically, so the number belongs somewhere an admin already looks. There is no automatic clean-up: deciding when old
media should go is a policy question, not one to guess at.

The go-live runbook gains a media section covering the checks worth doing before an academy starts uploading — including the one that matters on
nginx servers, where the folder-level block does nothing and TalentTrack's own permission check is the only thing protecting a child's photograph.

Switching media off is now verified end to end. Turning the module off makes the whole feature unreachable; turning the feature off hides the
screens and keeps the files. Neither deletes anything, and switching back on restores exactly what was there — so an operator can try it.
