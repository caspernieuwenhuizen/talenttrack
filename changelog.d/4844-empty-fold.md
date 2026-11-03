# Dashboard on a phone: no empty fold for a widget with nothing to show (#4844)

On a phone the dashboard folds most widgets away under their title. A widget
with nothing to show, because there is no data or no right to see it, still
left a titled fold behind that opened onto nothing. Such a widget now leaves no
fold at all; widgets with content are unchanged. Screen readers also stop
reading a stacked table's column name twice: the name drawn in front of each
value is now for the eye only, since the column header already names the cell.
