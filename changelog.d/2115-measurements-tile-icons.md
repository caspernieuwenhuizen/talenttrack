# Measurements: dashboard tiles show their icon again (#2115)

Bump: patch

The **My measurements**, **Record measurements** and **Testing coverage**
dashboard tiles rendered an empty icon chip — they referenced an `activity`
glyph that does not exist in the icon set. They now use real bundled glyphs
(`trend-up` for My measurements, `track` for the two staff tiles).
