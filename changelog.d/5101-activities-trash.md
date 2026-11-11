# GET /activities leaves out activities in the recycle bin, as the activities list does (#5101)

The REST activities list now reads its archive state through the same clause as
the rendered activities list, so an activity in the recycle bin never appears,
in any archive state and with or without the played filter. An archived
activity still appears under `archived` and `all`. `filter[archived]=trashed`
reads as `active`; the recycle bin keeps its own screen.
