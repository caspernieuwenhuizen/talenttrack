# Date notation: "System default" follows the site language (#3680)

On the **System default** date notation, a WordPress date format that was
never changed is now read as the format WordPress itself writes for the
site language. WordPress stores that format once, at install, in the
install language — so an academy installed in English and later switched
to Dutch kept the English word order and printed Dutch month names inside
it: "di oktober 6, 2026" on an activity, "september 14, 2026" on a
parent's evaluation cards. Those now read "di 6 oktober 2026" and
"14 september 2026". An operator who set the WordPress date format
themselves keeps it exactly as stored, and an English install renders as
before. The settings preview and the rendered dates now resolve the
preset through the same helper, so they can no longer disagree.
