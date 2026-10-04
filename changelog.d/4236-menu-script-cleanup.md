# Trial case: "Archive case" asks in the app's own dialog (#4236)

"Archive case" on a trial case asked for confirmation in a browser pop-up.
It now uses the same dialog as every other archive button, with Cancel
focused first. The question and the result are unchanged.

Behind it, clean-up with no visible effect: one script instead of two
handles the ⋯ menus, and the 48 px touch padding for disclosure headers no
longer applies to a header that is styled as a button.
