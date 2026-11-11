# Presentation surfaces: a room display of one record, without the app's chrome (#5088)

A surface can now show one record on the screen in the room without the
header, search box, navigation, bottom bar, flash messages, ambient banners or
help that belong to the person holding the laptop. A slug listed in
`config/presentation_surfaces.php` may present; the view declares it on its
success path with `PresentationSurfaces::present()`, which draws the single
close control back to the record's page. Any refusal renders the normal screen
in the shell, with breadcrumbs. The support-access banner moves to a new
`tt_dashboard_safety_banners` action so it stays visible on a room display,
and `TT.Autosave` gains a public `flush()` that `commit()` now uses. No slug is
listed yet; the PDP talk's conversation mode is the first that will be.
Unlisted pages render exactly as before.
