# TileRegistry declares the shape it actually accepts (#2816)

Bump: patch

No user-facing change. `TileRegistry::register()`'s declared array shape
described an older API — it required `slug` and `url`, which no caller passes,
and omitted `view_slug`, `label`, `module_class` and five more keys that nearly
all of them do. Every tile registration in the product therefore failed the
static-analysis gate and sat in the baseline, where each entry embeds the
literal array of its call site: editing any field of any tile produced a fresh
unbaselined error.

The shape now follows the method's own defaults, and 490 baseline lines went
with it.
