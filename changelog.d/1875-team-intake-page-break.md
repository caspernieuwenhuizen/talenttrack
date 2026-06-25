# Team season-intake print: clean one-page-per-sheet pagination (#1875)

Printing the season-intake for a whole team produced sheets that cascaded and
overlapped — each player's pages drifted onto trailing blank pages instead of
breaking cleanly. The print stylesheet pinned each sheet to a `min-height` of a
full A4, which rounds past the printable height on some renderers and bleeds
every sheet onto the next page. Each sheet now uses an exact A4 box with
clipped overflow and an explicit page break, so a batch of N players prints
exactly 3N clean pages.
