The import history screen now sends phone visitors to the desktop prompt page
rather than rendering an undo-a-whole-import control on a handset. It was the
one screen that had shipped without a mobile decision recorded against it, and
an unrecorded screen quietly behaves as though a phone is fine.

Behind that, a build check now refuses any new screen that has not said what a
phone should get. The previous list was written once and then went untouched
through roughly twenty new modules, so most screens ended up defaulting rather
than being decided — this stops that happening again without anyone noticing.
