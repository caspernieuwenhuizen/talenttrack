# Match prep PDF: no more placeholder text or stray buttons on paper (#2755)

The exported match-prep PDF no longer prints the grey hint text from empty
fields. An unfilled goal line comes out as a blank ruled line instead of
"Doelstelling 2…", and a player with no note prints an empty cell instead of
"…". The `×` that clears a set-piece player and the `→` that copies the first
half's line-up to the second no longer print either — they're on-screen
controls, not part of the team sheet.

The export is an image capture rather than a browser print, so it never read
the print stylesheet that already handled all of this. The placeholder half
could not be fixed from CSS at all: the capture engine ignores `::placeholder`
and paints an empty field's `placeholder` attribute as ordinary text, which is
why the hints came out darker on paper than they look on screen. The attribute
is now removed from the capture clone, which every surface using the shared
image-export module benefits from. Nothing changes on screen or in the browser
print dialog.
