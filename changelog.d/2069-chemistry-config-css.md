# Chemistry settings: dark-mode legibility + compact number inputs (#2069)

The Chemistry settings page (`?tt_view=chemistry-config`) is now legible in
dark OS/browser modes again. The partial `prefers-color-scheme: dark` block
darkened the block background but never lightened the text, leaving dark-on-dark
legends, labels and hints — the surface has no real dark variant, so that block
is removed and the page stays on its light design system. The numeric weight
inputs also no longer blow out to full row width: the selector now wins over the
global `.tt-input { width: 100% }` rule, restoring compact ~5rem right-aligned
boxes with the label-left / input-right flex row intact. CSS only.
