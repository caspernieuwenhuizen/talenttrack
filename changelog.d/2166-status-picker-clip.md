# Metingen vastleggen: status picker no longer clipped by the roster (#2166)

Bump: patch

On the record-measurements roster, the coloured status picker's option list
was cut off — on a short roster only the skip option was visible — because
the roster used `overflow: hidden` to clip its rounded corners, which also
clipped the absolutely-positioned dropdown. The roster now uses
`overflow: visible` and the rounded-corner look is preserved by rounding the
first and last rows, so the full level list opens above the following rows
with its shadow intact. CSS-only.
