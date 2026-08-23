# Correction: photo capture is not off by default (#2695)

Bump: patch

The v4.96.0 notes and the photo-capture DPIA both said the
`exercises_vision_extraction` feature was off on a default install, and the DPIA
leaned on that as a safeguard. **It is on by default.** That statement was wrong
and has been corrected.

Nothing about your install's actual safety changes. A site that has not set
`TT_VISION_ENDPOINT` and `TT_VISION_DATA_REGION` still sends nothing — the
endpoint answers `503` and no photograph leaves the server. The protection is
real; it just comes from the destination declaration rather than from the
feature flag.

What this means in practice: if you were relying on the feature being off, it is
not, and you should switch it off explicitly. Simply leaving the two destination
settings unset already prevents anything being sent, and remains the thing the
DPIA treats as the deliberate act a signature authorises.

A test now compares the document against the code's actual default, so the two
cannot drift apart again.
