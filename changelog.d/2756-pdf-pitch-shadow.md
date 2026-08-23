# Match prep PDF: dark block over the second-half pitch (#2756)

Exporting the match-preparation PDF while the page was scrolled — which it
always is, since the grid is taller than the window — painted a hard-edged
dark block over part of a half-pitch, usually the second half. The pitch
colours themselves were never wrong: the image capture was given the wrong
scroll offsets, so the pitch's drop shadow landed inside the pitch instead
of around it, and the pitch clipped it into a block over the line-up. The
capture now uses the page's real scroll position, and the pitch joins the
other surfaces whose shadow is dropped for the export. Both half-pitches
come out the same light blue again; the on-screen view is unchanged.
