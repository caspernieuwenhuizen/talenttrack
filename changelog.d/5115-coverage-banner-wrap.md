# Monthly report PDF: the coverage banner prints in full (#5115)

The yellow banner at the top of the monthly team report's PDF, which says how
many completed activities have a register and which were never closed, was one
line cut at 150 characters and clipped at the right margin. A Dutch banner
naming its activities lost its end. It now prints the whole sentence and wraps
inside the page, and the page estimate counts the lines it wraps to. The screen
view and the page count of a typical report are unchanged.
