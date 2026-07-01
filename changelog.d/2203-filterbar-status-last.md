# FilterBar: status group always last and right-aligned (#2203)

Bump: patch

On the shared filter bar the status pills now always render as the last
control on the inline (desktop) row and hug the right edge, regardless of
the order the calling view passes its filter groups. Other groups keep
their order and the mobile bottom sheet is unchanged. Component-wide change
— no caller edits needed.
