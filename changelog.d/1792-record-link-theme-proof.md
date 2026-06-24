# Record-name links are now theme-proof (#1792)

Bump: patch

Player / team / person name links (`.tt-record-link`) rendered
differently across installs — borderless and colour-inherited on a clean
theme, but underlined and blue under a theme whose `a` rule (or an
`a { … !important }`) outranked the low-specificity reset. The canonical
reset now forces `text-decoration: none` and `color: inherit` with
`!important` so a hostile theme can't re-decorate them, and a defensive
rule covers any raw `<a>` inside a TalentTrack list table. The hover/focus
affordances are unchanged (and the hover tint moved from blue to the brand
green). Visual only; no markup or string changes.
