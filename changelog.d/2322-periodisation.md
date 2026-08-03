# Methodology periodisation combined with VCT week-cycle (#2322)

Bump: minor

The VCT macro-block week schedule now carries an optional per-week speelwijze theme (`tactical_theme`) alongside the existing conditioning phase and intensity multiplier, reusing the canonical `vct_tactical_theme` vocabulary. A new "Periodisering" tab on the methodology library reads the club-default cycle for the current season and shows, per week, the speelwijze theme + conditioning phase + intensity — the single surface that combines the methodology and VCT views. The VCT configuration tile gains a per-week theme picker inside each block's advanced editor, and a JO13-1 5-week speelwijze reference template ships as a seed. Feeding the per-week theme into VCT exercise selection is a deliberate follow-up and is intentionally out of scope here.
