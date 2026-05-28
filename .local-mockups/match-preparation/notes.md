# Match preparation — mockup notes

Design-of-record for the head-coach **Match preparation** surface — the
3-column spreadsheet layout shipped in #965. Mirrors the pilot's
working spreadsheet.

## Demo states

The state picker at the top of `index.html` toggles the page through
three fill states (handy when iterating on the layout in a browser):

| Button | What it shows |
| --- | --- |
| **Empty** | Roster present in availability state but nothing assigned yet — exercises the empty-state copy and the "open availability" link. |
| **1e gevuld** | 1st half filled with 11 players; tactical goals populated; two analyst flags; some `!` flags; sample role assignments (Aanvoerder / hoekschoppen / penalty). The mid-development state a coach sees right after the matchday wizard. |
| **Beide helften gevuld** | Both halves filled, including two subs in the 2nd half (Cataleya for Jason at #8; Nine for Samuel at #11). Models the "ready to play" state. |

## Surfaces this mockup informed

- `src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php` —
  3-column layout, slot positions per formation, role pane.
- `assets/js/frontend-match-prep.js` — slot picker, role picker,
  drag-drop, copy 1e → 2e, availability drawer, live save.
- `assets/css/frontend-match-prep.css` — mobile-first port of the
  mockup's tokens. The mockup itself stayed desktop-first; the ported
  CSS folds the 3 columns down to a stack below 1100px so phones at
  least render the page.

## Known mockup-only details

The mockup is illustrative and does NOT correspond 1:1 to the
production surface in two places:

- **Save bar**: the mockup has a sticky footer save bar; the production
  surface live-saves every edit over REST, so there's no save bar —
  the toolbar's right side shows the save state instead.
- **Demo player roster**: the mockup ships with 19 hard-coded names;
  production loads the actual team roster via REST and applies the
  same name-formatting as the rest of the app.

The interactions (slot click → picker, drag-drop, copy button,
camera-icon toggle, role pane click → picker, availability drawer
chips) all match the production behaviour.
