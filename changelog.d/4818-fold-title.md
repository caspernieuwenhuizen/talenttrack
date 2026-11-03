# Dashboard on a phone: a folded table says what it holds (#4818)

On a phone, the dashboard folds most widgets away to keep the page short. The
fold's label for every table widget read "Data table", so a coach could not
tell Behaviour pending from My recent prospects without opening each one. The
fold now shows the widget's own title, the same as its heading on a larger
screen, and falls back to the generic name only for a widget with no title.
Inside an open fold the title is not shown a second time; the "See all" link
stays.
