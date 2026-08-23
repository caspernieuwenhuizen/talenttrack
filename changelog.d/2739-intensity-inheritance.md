# The growth-spurt warning works again (#2739)

Bump: patch

The printed training sheet carries a warning naming players whose growth-spurt
intensity ceiling is below the hardest block in the plan. **It was not appearing
— on any plan that was not built by the generator.**

A block took its intensity from the exercise only when the generator put it
there. Plans built in the plan builder, through the API, or from a photographed
sheet stored no intensity at all, and the check read that as "intensity zero"
rather than "intensity unknown" — so it concluded there was nothing to warn
about and printed nothing.

Nothing was wrong with the plans. What was wrong is that a sheet with no warning
looked exactly like a sheet that had been checked, and a coach had no way to tell
the difference.

Now:

- A block takes its exercise's intensity when it has none of its own, so the
  check has something to work with. This applies to plans you already have — no
  re-saving needed.
- **A plan where no block has any intensity recorded now says so on the sheet**,
  in grey, rather than printing nothing. If you see nothing, the check ran.
- An intensity you set on a block yourself is never overwritten — an adapted or
  walked-through version of a hard drill stays as you rated it.
- The same value now reaches the sideline view's record of the session.

If your exercises have no intensity set, this is worth doing: it is what turns
the growth-spurt warning from silent into useful.
