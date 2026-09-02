# The period pill stops contradicting the report (#3293)

On the grids and reports that offer both a period pill and a From/To range,
setting your own dates re-ran the report on them — but the pill went on
showing "This month". The chrome said one thing and the numbers were another,
with nothing to tell you which.

Set your own window now and no pill claims to describe it.

Related: on seven of those surfaces a manual range was not counted at all, so
setting only From/To left **Filters** with no badge and no chip — the bar
reporting "nothing filtered" over a filtered report. The window now shows as a
chip like any other filter.

Clearing your dates returns to the season default with the season pill active,
as before.
