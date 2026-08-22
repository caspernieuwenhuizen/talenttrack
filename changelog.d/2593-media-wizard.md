# Media library: adding photos and video (#2593)

Bump: patch

The **Add media** wizard, and the upload control behind it. Four steps: who it is for, the files, the details, and a confirmation.

Uploads are saved as soon as they finish, before the last step — so a dropped connection or a closed tab never costs you a file you already
waited for. Leaving halfway means the photos are on the record already, just without a title you can add later.

Each file shows its own progress and can be cancelled individually without disturbing the others. On a phone the camera is one tap from the
drop zone, and the largest file the server accepts is shown before you pick one rather than after the upload fails.

Video gets a thumbnail without any extra software on the server: the browser takes a frame from the clip as it uploads.

Pasting a video link works for Veo, Hudl, YouTube and Vimeo. For YouTube and Vimeo the title and thumbnail are filled in automatically; anything
else is saved as a plain link with a title you type.

The capture date is what decides where media sits on a player's timeline, so the wizard asks for the day of the training or match rather than
assuming the day of upload — and fills it in from the photo when the photo carries one.
