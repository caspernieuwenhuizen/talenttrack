# A parent's dashboard now shows every surface they are allowed to open (#3472)

A parent can read their child's tests and measurements, and has an inbox of
their own. Neither had a tile on the parent dashboard, and on the default
chrome that dashboard is a parent's only navigation — so both were granted and
unreachable at the same time.

The parent dashboard framed each tile as "Luuk's measurements", and the list of
nouns it uses to do that was quietly also deciding which tiles existed at all.
Anything not in the list was dropped rather than shown unframed.

Measurements now appears on the child rail with the rest of their record, and
the parent's own surfaces — their messages, their account settings — appear in
a second group beneath it, under their own names rather than the child's. The
two lists together cover everything the parent can see, so a surface added
later cannot go missing from this screen by omission.
