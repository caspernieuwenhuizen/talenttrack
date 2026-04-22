<!-- type: needs-triage -->

# My card — technical errors, not appealing

Raw idea:

The my card has technical errors and does not look at all appealing, needs a proper review.

## ⚠ NEEDS MORE INFO BEFORE SHAPING

This is too vague to spec. Before moving to specs/, need answers to:

- What technical errors exactly? Any browser console errors? PHP notices in debug.log?
- Is data missing (photo, rating, position), or are values wrong?
- Layout issue (overlap, text cutoff, wrong colors)?
- Desktop / mobile / both?
- Screenshot if possible — drop it in this file as ![screenshot](./my-card-issue.png) with the PNG next to the markdown.

## Touches (once scoped)

src/Shared/Frontend/FrontendOverviewView.php (the "My card" tile)
src/Modules/Stats/Admin/PlayerCardView.php (the FIFA card rendering)
