# Six tiles were rendering without an icon (#3027)

The Injuries, Team development, PDP cycle, Trials and Notes surfaces declared
icon keys that had no SVG in either icon set, so their icon chip painted blank.
The Media tile fell back to a line icon among duotone neighbours. All six now
have a duotone glyph: a first-aid kit for Injuries, so a medical record does not
read as an error state.
