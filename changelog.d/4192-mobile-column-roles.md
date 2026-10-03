# Compact list rows on phones: title, subtitle and badges (#4192)

Bump: minor

On a phone, rows in the shared list views are now compact: a bold title line, a muted subtitle line underneath, and pills or short values at the top right, with a label/value line only for the remaining columns. The whole row stays one tap target of at least 48 px and can be reached and opened from the keyboard. The players list is the first to use it: a row reads "Name #7" with the foot pill at the right and the team underneath, so far more players fit on the first screen. Every other list gets the same layout using its first column as the title and its second as the subtitle, until it declares its own. View authors choose per column with a new `mobile` key (`primary`, `secondary`, `badge`, `detail`, `hide`). The table on tablet and desktop is unchanged.
