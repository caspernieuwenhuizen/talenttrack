# Fixed: uploading a photo failed with an error (#2674)

Bump: patch

Adding a photo to a player, team or training failed — the file showed "Could not be added" and nothing was saved. Uploading video, or pasting a
video link, was unaffected, which is why the problem looked intermittent.

The cause was a thumbnail step that used a WordPress function only available inside the admin screens, so it broke as soon as the upload came from
the media wizard. Photo uploads work again, and nothing was lost: the failure happened before anything was written, so no half-saved photos or
stray files were left behind.
