# Match "type of match" now shows translated labels on the activity form (#1861)

The game-subtype dropdown (Friendly / League / Cup) on the frontend activity
manage form rendered the stored English labels even on a Dutch install,
because it read the lookup names without their translations. It now pulls the
full lookup rows and renders the translated label — matching the admin form
and the activity wizard. The stored value is unchanged.
