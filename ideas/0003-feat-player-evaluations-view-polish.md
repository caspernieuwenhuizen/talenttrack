<!-- type: feat -->

# Player "My evaluations" view polish

Raw idea:

Evaluation page as player is not nice, table has wrapping and also the displayed scores are not nice. I want the main categories to stand out from the underlying ones, they do need to be visible though. Also, the overall score needs to be shown first and foremost, that is what players care about initially.

## Sub-items

1. Overall score per evaluation: must be the first/biggest thing shown per row or card. Large number, primary color.
2. Main categories visually distinct from subcategories. Players should see structure at a glance (Technical: 4.2 with sub-breakdown indented/smaller).
3. Mobile layout: table must not wrap badly. Stack into cards under 640px width?

## Touches

src/Shared/Frontend/FrontendMyEvaluationsView.php
Possibly: src/Shared/Frontend/FrontendOverviewView.php (which also shows rating pills)
