# Age-category filters only offer categories that have teams (#2867)

Bump: patch

The age-category dropdown on the players list offered every category the
academy had ever configured, so a club with two teams scrolled a list where all
but two choices returned nothing.

The filter now offers only categories that actually have teams in them.
Archived and deleted teams do not keep a category on the list, and if you are
already filtering by a category that has just become empty it stays selectable,
so a saved or shared link keeps showing what it showed before.

Forms where you *assign* an age category — creating a team, editing one — still
offer the full list. You have to be able to put the first team into a category
nobody is in yet.
