# Paste a screenshot straight into the media uploader (#3092)

On a computer, Ctrl+V (Cmd+V on a Mac) now adds an image from the clipboard to
the upload list, so a screenshot no longer has to be saved to disk and found
again in the file picker. It goes through the same size check, progress bar and
cancel button as a picked file, and is named after the moment it was pasted so a
grid of screenshots stays readable.

Pasting into the video-link box still pastes text there, a paste carrying no
image is left alone, and a target that only accepts documents refuses a pasted
image with the same message it gives a refused upload.
