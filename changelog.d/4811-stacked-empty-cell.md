# Goals by principle widget: no blank line in each row on a phone (#4811)

Since the dashboard's table widgets stack their rows on a phone, every row of
the Goals by principle widget ended in an empty line, from a column that never
holds anything. An empty cell now takes no line in a stacked row. The other
widgets look the same, and from 480px up every widget is still a table, with
the empty column in place.
