# Navigation layout is now a setting (#2456)

Bump: minor

TalentTrack can now render its frontend in a persistent **app shell**: a grouped
navigation sidebar at laptop widths, collapsible to a strip of icons, and a
slide-out menu behind a ☰ button on smaller screens. The entries come from the
same registry that builds the tile overview, so everyone sees exactly the
sections their role already had — same names, same order, same permissions, now
always on screen instead of a trip back to the tile overview.

The layout is a choice at two levels. Academy admins set the default under
*Configuration → General → Navigation layout*; anyone can pick their own under
*My settings → Layout*, either following the academy or pinning a layout for
themselves. **Classic remains the default**, so nothing changes until someone
opts in, and switching back restores the previous chrome exactly.
