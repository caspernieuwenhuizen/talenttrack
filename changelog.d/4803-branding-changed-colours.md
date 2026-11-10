# The Branding form saves only the colours an academy changed (#4803)

Saving the colours under Configuration → Appearance now stores only the colours that differ from the field's default; a colour left at its default is saved as not set and follows the design's own colour. Each colour has a "Reset to default" button. On update, colours an earlier save stored at their default, and danger, warning, success or info colours stored as black, are cleared. Nothing changes on screen: those values were already treated as not set. Colours an academy chose are kept.
