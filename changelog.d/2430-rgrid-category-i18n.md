# Ratings grid: category column headers now follow your language (#2430)

The ratings grid showed its evaluation-category column headers in English even
on a Dutch install, while the rest of the screen was translated. The grid's
read model was reading the stored category label straight out of the database
instead of resolving it the way every other evaluation surface does, so the
translation layer never got a look in.

Headers now resolve through the same display-time translator the evaluation
form, the evaluation detail view and the radar-chart legends use, which means
operator-maintained translations show up here too. A category nobody has
translated keeps its stored name, so nothing goes blank. Stored data is
untouched — scores still write against the category, never against its label.
