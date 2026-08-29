# Match prep: revert to how it was when you opened the screen (#3006)

Autosaving surfaces gain a second range of undo. **Undo** takes back the last
change; **Revert changes**, beside it, puts the whole record back to the state
it was in when the screen was opened. It asks first — a confirm names how many
fields it will restore and says the restore cannot itself be undone.

The starting point lives in the browser, so it survives a reload or an
accidental tab close, and it does not follow the coach to another device: open
the same match on a laptop the next morning and you get the saved plan with no
revert offered, because the sitting ended. Every read and write of the store is
wrapped — a private window, a cleared browser or a record too large to snapshot
simply means no revert offered, with autosave, undo and the rest of the screen
unchanged.

Two boundaries worth knowing. Captain and set-piece picks write on their own
endpoint, which a revert could not put back, so choosing one retires the offer
for the rest of the sitting rather than restoring part of the screen and
leaving those standing. And the grids stay explicit-Save: a control that offers
to restore a change the coach has not committed yet would not mean anything.

Match prep is the only surface wired to it today; the behaviour lives in the
shared `TT.Autosave` component, so the writing surfaces moving to autosave in
the rest of epic #2881 inherit it.
