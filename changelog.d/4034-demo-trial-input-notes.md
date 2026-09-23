# Demo trial assessments no longer all say the same sentence (#4034)

Every staff input on every seeded trial case carried one identical note, word
for word, while the ratings differed. On the screen whose whole job is to show
three independent views of a child before a decision about them, that reads as
a bulk write that overwrote three individual assessments — a data-corruption
alarm on a product that had written nothing of the kind.

The generator now deals from a pool of eight assessment notes per language,
shuffled once per case and handed out in order, so no two panellists on a case
agree by accident. Dutch and English pools both ship. Existing demo data is not
rewritten; regenerate the demo academy to pick it up.
