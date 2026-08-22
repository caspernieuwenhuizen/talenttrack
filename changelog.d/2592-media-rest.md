# Media library: the API behind photos and video (#2592)

Bump: patch

The media library's REST surface: uploading, listing, editing, attaching to more than one record, and serving the files themselves. Still nothing
to click — the screens follow — but everything the feature will do is now reachable and permission-checked.

Photos and video are served only through TalentTrack, which checks who is asking before it sends a single byte. Video supports seeking, so a clip
can be scrubbed on a phone rather than downloaded whole. Anything TalentTrack does not recognise as a safe image or video is offered as a download
rather than displayed, and nothing served this way is stored in a shared cache.

Asking for a photo you are not entitled to see returns "not found" rather than "not allowed". That is deliberate: "not allowed" would confirm the
item exists in this academy, which is the one thing worth hiding from someone guessing.

Pasting a link to a video hosted elsewhere works for Veo, Hudl, YouTube and Vimeo. For YouTube and Vimeo the title and thumbnail are fetched
automatically, and the thumbnail is copied into the academy's own storage so viewing a clip does not tell the video provider which coach looked at
which player. Links to anywhere else are stored exactly as pasted, with a title you type — TalentTrack never contacts an address it does not
recognise.
