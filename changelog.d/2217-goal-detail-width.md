# Goal detail: widen the goal pane, reduce wasted horizontal space (#2217)

Bump: patch

On the goal detail page the left goal pane no longer sits in a narrow
column beside a large empty gutter. The `max-width: 640px` clamp on the
goal card is lifted and the desktop split is rebalanced to `1.3fr 0.7fr`,
so the goal fills its column while the conversation pane stays readable.
CSS only; mobile stays a single column.
