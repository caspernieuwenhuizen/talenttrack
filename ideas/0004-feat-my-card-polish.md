<!-- type: feat -->

# My card — technical errors, not appealing

**This idea has been shaped into a feat spec.** See `specs/0004-feat-my-card-polish.md`.

The type flipped from `needs-triage` to `feat` during shaping — rather than wait for specific bug reports, the spec treats this as a visual polish pass adjacent to #0003 (player evaluations view polish). Any real technical issues get caught during rebuild.

The original idea is preserved below as historical record.

---

Raw idea:

The my card has technical errors and does not look at all appealing, needs a proper review.

## Original triage questions (no longer required)

- What technical errors exactly? Any browser console errors? PHP notices in debug.log?
- Is data missing (photo, rating, position), or are values wrong?
- Layout issue (overlap, text cutoff, wrong colors)?
- Desktop / mobile / both?
- Screenshot if possible.

## Shaping decision

Rather than wait for these questions to be answered, the spec treats the card as needing visual polish regardless (#0019 Sprint 1's CSS scaffold + #0003's rating pills make rebuild cheap). The rebuild process also catches any real PHP/JS errors in passing. If specific bugs survive and are reported later, they become new bug reports.

## Touches (as specced)

- `src/Shared/Frontend/FrontendOverviewView.php` (the My card tile)
- `src/Modules/Stats/Admin/PlayerCardView.php` (the FIFA card rendering — reused, not rewritten)
