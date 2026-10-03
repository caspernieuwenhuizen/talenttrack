# Lists and the player profile no longer show raw or English values (#4212)

Bump: patch

The evaluations list prints its dates in the academy's date format instead of `2026-09-12`. The goals list shows the priority as the same translated label the goal form and the goal page use (Laag / Gemiddeld / Hoog), instead of the stored `medium` / `Medium`. The people list shows the type as a translated label instead of `scout` or `staff`; the form, the filter and the list now share one set of labels. A player's journey shows "Trial ended" with the decision's label instead of its key, for existing entries as well: the label is filled in when the journey is read, so it follows the reader's language and any label the academy edited. The Born and Joined facts on the player profile use the site language for the month (18 dec ’19 in Dutch). `GET /people` rows gain `role_type_localised`; existing fields are unchanged.
