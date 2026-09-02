# PDP file detail: three chrome fixes (#3299)

**One help icon, not two.** The summary card carried its own `?` button next to
the Status pill, opening the same drawer on the same topic as the header's help
icon. It is gone; the header icon is unchanged.

**The Template link looks like a link in this app.** It was a bare anchor, so a
theme's own styling applied — blue and underlined, next to the status badges in
the same row. It now matches the record links in every other table.

**The conversations list reads on a phone.** Below 640px the table becomes
stacked cards, with each value labelled by its column. This table carried no
labels, so a card read "1 / Begin seizoen / 2026-09-30 / Gepland / Nog niet"
with nothing saying which was which. Each value is now named.
