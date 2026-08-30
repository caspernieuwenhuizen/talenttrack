# Sorting or searching a list no longer serves the blog index (#3256)

On an install whose front page is the TalentTrack dashboard page — which is
every install created through onboarding — clicking a column header or
running a search on any list view served the theme's blog index instead of
the list. WordPress only substitutes the static front page when the URL
carries no core public query var, and `order`, `orderby` and `search` are
all core public query vars, so the substitution was skipped and the
dashboard shortcode never ran.

The dashboard now claims those requests back. Sorting and searching work on
every list view regardless of what the site's front page is set to. Sites
that serve posts on the front page, front pages belonging to some other
page, and wp-admin are all untouched.
