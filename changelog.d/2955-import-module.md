# Spreadsheet import is its own module (#2955)

Bump: patch

The Excel import machinery moved out of Demo data into a new **Import**
module, which appears as its own toggle under Administration. Nothing about
the demo-data workbook changes — same template, same validation, same
Tools → TalentTrack Demo screen — but an academy that switches Demo data off
in production keeps the importer.

The importer no longer decides for itself how the rows it creates are
recorded. That is now supplied by the caller, which is what lets a future
import bring in a club's real squad without those records being treated as
demo data.
