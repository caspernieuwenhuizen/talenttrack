# Expandable section headers are now a proper tap target (#2801)

Bump: patch

The headers you tap to expand a section — advanced options, permission
groups, the dashboard's own tile groups — were as little as 19 pixels tall,
because nothing in the stylesheets ever gave them a size.

They now meet the intended size on touch devices, and keep their expand
arrow. Desktop is unchanged.
