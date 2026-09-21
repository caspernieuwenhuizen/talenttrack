<?php
/**
 * Write routes that predate the args rule (#3689). Read by
 * `tools/check-rest-args.php`.
 *
 * **This list is empty, and that is the point.** #3603 emptied it across
 * five slices; every write route under `src/` now declares the fields it
 * takes. The gate is a ratchet at zero: a new write route without `args`
 * fails it, and there is no longer any "the rest of them do it too" to
 * hide behind.
 *
 * Each line would be `file | route path | methods`. **Do not add one.**
 * The gate fails on a line that no longer violates as well as on a
 * violation that is not listed, so a line added here to make a new route
 * pass has to be removed again the moment the route is fixed — it buys a
 * green build and costs the next reader the search for why. Declare the
 * route's args instead; `docs/rest-api.md` § Body contract says how, and
 * why no field is ever declared `required`.
 */

return [
];
