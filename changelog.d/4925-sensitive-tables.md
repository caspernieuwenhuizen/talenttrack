# Data Explorer: every table holding a masked field is a sensitive table (#4925)

The Data Explorer now flags every table that holds a field the screens hide from a reader who may not see it: players (guardian contact and date of birth), the club's own player fields, evaluations, attendance, match-prep availability and people. Opening one shows the sensitive-data warning and writes a `data_browser.view` entry to the audit log, like the player notes. The rows still show.
