# An academy can now edit its own methodology without entering wp-admin (#2976)

Bump: minor

The methodology is the one thing this product is most opinionated about — it
is the vocabulary every learning goal, exercise and evaluation is written in.
It was also the one thing an academy could not change without being dropped
into WordPress: nine separate wp-admin pages maintained the vision, the
principles, the phases, the influence factors, the positions, the learning
goals, the set pieces, the primer and the football actions.

All nine now live on one screen — **Methodology vocabulary**, under
Configuration → Methodology. A picker across the top switches which
vocabulary you are looking at; underneath it are that vocabulary's entries,
with a form to add, edit or remove one. Vision and Primer are single records
for the whole academy, so they offer editing only, and shipped reference
content stays marked read-only exactly as it was.

Every field a coach reads is written twice, once per language, side by side.
Filling in only one is fine and shows you plainly which one you filled in,
rather than leaving the gap to surface later in somebody else's locale.

Positions keep their shape: they belong to a formation, so you pick the
formation first and then work through its shirt numbers.

The wp-admin pages are untouched and still work. This adds a route; it removes
none.
