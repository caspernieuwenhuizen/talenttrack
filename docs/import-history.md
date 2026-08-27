---
title: Import history
group: configuration
summary: What each spreadsheet import brought in, and how to take a whole import back out again.
audience: [admin]
module: TT\Modules\Import\ImportModule
views: [import-history]
order: 162
---

# Import history

Every spreadsheet import is recorded: the file it came from, when it ran, who ran it, and how many teams, players and staff it created. **Configuration → Import history** lists them, most recent first.

An import is a starting point rather than a commitment. Uploading the wrong file, or the right file with a column in the wrong place, is a normal first-day mistake — and the fix should not be deleting two hundred players by hand.

## Undoing an import

**Undo this import** removes exactly the records that import created. Anything else is untouched: other imports, records typed in by hand, and demo data are all out of reach of the undo.

Before it runs you are shown what will go — "3 teams, 24 players" — and you have to confirm.

### When records have been worked on since

If some of the imported records have been edited since they arrived, the confirmation says so and how many. Those edits go with the records; the undo does not try to keep them.

This is a warning rather than a block, because the commonest reason to undo is the wrong file entirely, and you should not have to unpick that by hand just because somebody opened one of the records. But read the number before confirming — if it is large, it may be quicker to correct the import than to undo it.

The count only covers records that track when they were last changed, so treat it as "at least this many" rather than an exact figure.

## Undoing twice

Undoing an import that has already been undone does nothing and says so. The history keeps the row, showing the import as already undone, so there is still a record that the file was once brought in.

## What is not covered

Undo removes records; it does not restore anything the import overwrote. If an import updated an existing record rather than creating one, undoing does not put the old values back.
