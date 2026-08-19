**Fixed:** the app shell's sidebar now carries the academy crest and name at
the top and the signed-in user at the foot, so which academy you are in is
answerable without looking away from the navigation. Both shrink to their mark
alone when the sidebar is collapsed to icons.

**Fixed:** icon chips ignored the active visual theme. Dashboard tiles kept
their per-module colours and Configuration tiles rendered in the old green
even under a navy theme. While a theme is active, every chip now takes the
theme's colour; under the default theme the per-module colours are unchanged.

**Fixed:** with a theme active, the collapsible navigation groups still used
light-surface colours on the dark sidebar — hovering a group painted a
near-white block behind near-black text, and the hairline between groups
rendered as a bright bar. Both now follow the theme.
