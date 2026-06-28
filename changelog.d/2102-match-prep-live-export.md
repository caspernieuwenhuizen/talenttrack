# Match prep: Export as PDF now captures the live on-screen view (#2102)

Bump: patch

The match-prep toolbar's **Export as PDF (A4)** button now takes a picture
of the on-screen match-prep grid exactly as it appears — both formation
pitches (blue 1e / orange 2e with the white name pills), the Selection ·
minutes table, Wedstrijddoelen, Doen per speler and Roles & set pieces —
and lays it out on **portrait A4**, scaled to page width and split across
pages on overflow. Previously the export captured a separately-styled
print document, so it never matched what the coach laid out on screen.
The capture engine (html2canvas + jsPDF) stays lazy-loaded on first click.
The standalone print route and the browser print dialog remain available
as fallbacks; the team-sheet print is unchanged.
