# Applying a date or text filter on a phone now works (#3288)

On a phone, a date range or free-text filter set inside the **Filters** sheet
was thrown away. You picked From and To, tapped **Apply**, the sheet closed and
the list was exactly as before — with nothing to say the filter had not been
applied.

**Apply** now applies. The button was closing the sheet and nothing else.

Dropdowns and toggles were never affected, which is what made this hard to
spot: set a team as well as a date range and the page reloads, so the dates
look like they worked too.

This affected the grids and reports that carry a From/To or a search box —
attendance and minutes grids, the attendance and minutes reports, standard
reports, the audit log, comparison and the message log.
