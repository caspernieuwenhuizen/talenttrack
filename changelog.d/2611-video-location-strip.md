Bump: minor

Uploaded video no longer keeps the location its camera recorded. TalentTrack
finds the parts of an MP4 or MOV where phones write coordinates and blanks them
before storing the file — without re-encoding, so the picture and sound are
untouched and the file is byte-for-byte the same length. After an upload the
queue says what happened: that location data was removed, or, in the rare case
that the file contains something TalentTrack cannot read, a warning that it may
still say where it was filmed. Photos were already stripped on upload; video was
the documented exception, and no longer is.
