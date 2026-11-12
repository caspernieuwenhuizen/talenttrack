# PDP talk: conversation mode, the talk on the screen in the room (#5082)

Bump: minor

**Conversation mode** on a PDP talk's page shows the talk on a TV or a large screen. The player, the parent and the trainer can look at it together.

- **What it shows:** a clean screen with the club's logo and name, the player and the talk, and no search, help, menu or user name. It works one step at a time: an overview of each section's averages and items to discuss, each section with both scores and **Discuss**, and finally the strongest quality and the development points.
- **Scoring:** the trainer can still score and clear scores on the same talk, with undo. **Close** and **Escape** save first, then return to the talk's page on its conversation tab.
- **What stays off the screen:** the trainer's section notes (Toelichting), the Background tab, sign-off, and the player's own text answers. It opens only for readers of the trainer's part, and anyone refused gets the normal screen with breadcrumbs.

Behind it:

- a new read-only route, `GET /pdp-conversations/{id}/form/display`, which serves the same allowlist;
- RecordSpine tabs can be opened from the URL fragment;
- a presentation surface's close URL may carry one named sub-record key (`conv`);
- the demo marker shows on a room display on a demo install.
