# Take a whole spreadsheet import back out (#2959)

Bump: minor

**Configuration → Import history** now lists every spreadsheet import: the
file, when it ran, who ran it, and what it created. Each one can be undone
in a single action.

An undo removes exactly the records that import created. Other imports,
records typed in by hand and demo data are all out of its reach. Before it
runs you are shown what will go, and if any of those records have been
edited since they arrived, how many — those edits go with them, so the
number is worth reading before confirming.

Undoing an import that has already been undone does nothing and says so.
The history keeps the row either way, so there is still a record that the
file was once brought in.
