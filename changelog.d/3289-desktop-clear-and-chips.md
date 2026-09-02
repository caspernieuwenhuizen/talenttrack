# Clear and the applied-filter chips now show on desktop too (#3289)

On a wide screen the filter bar offered no way to clear filters and no
readback of what was applied. Set a team, a position and an age group and the
only way back was to walk through every control by hand — and for the period
pills and the archive menu there is often no "none" option to walk back to.

Both were rendered by every surface all along; they were only ever visible
below 1024px.

The bar now ends with the active-filter chips and a **Clear** button, on every
screen size. Clicking Clear returns the unfiltered list, exactly as it does on
a phone. The **Filters** button and its count stay mobile-only, since desktop
shows the controls inline; the sheet keeps its own Clear where a phone user
expects it.
