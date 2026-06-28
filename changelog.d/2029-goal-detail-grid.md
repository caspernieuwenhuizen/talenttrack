Goal-detail page: goal left, conversation right two-column layout (#2029)

Bump: patch

The standalone goal-detail pages now place the goal card on the left and the
Gesprek (conversation) on the right in a two-column grid on tablet and wider
screens (>=768px), stacking goal-then-conversation on phones. This applies to
both the coach view (`?tt_view=goals&id=N`) and the player/parent view
(`?tt_view=my-goals&id=N`), matching the existing two-column treatment on the
POP detail. Layout-only change: the grid and spacing moved out of inline
styles into the enqueued `frontend-goals.css` sheet; no data or query changes,
and the conversation pane (bubbles + compose box) is unchanged.
