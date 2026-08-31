# Setup can import your squad in the app (#3260)

Bump: minor

**Import your squad** — the step that decides whether a new academy's first experience of TalentTrack is a spreadsheet that loaded or one that did not — was still sending you to the WordPress admin. It now runs where the rest of Setup does: download the template, upload it, read what it found, confirm.

Nothing about how a workbook is read has changed. There is still exactly one importer behind both screens, so what counts as a valid file, what it reports and how it tags the rows are identical whichever one you use — and you can still start the step in one and finish it in the other.

The two-pass rule is intact: the first upload tells you what the file contains and writes nothing, and a workbook with problems never reaches the second pass. You choose the file again to confirm, because a browser will not let a page re-send a file it was handed, and holding your squad list on the server on the chance you press the button is not something to do quietly.

Only **Add your staff** is still admin-only.
