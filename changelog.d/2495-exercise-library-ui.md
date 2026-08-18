# The exercise library gets a screen (#2495)

Bump: minor

The merged exercise library is now browsable. Open the **Training** tile and
choose **Exercises**: search by name, code or description, filter by category,
intensity, visibility or status, and open any drill to see its setup, group
size and diagram. VCT's conditioning exercises sit alongside your own, labelled
so you can tell them apart.

**Coaches can add their own drills.** A new exercise belongs to your team and
is usable in your plans immediately — nothing waits on approval. Whether the
rest of the club gets it is a separate call: the head of development sees an
"Added by teams" panel listing what coaches have written, with how many plans
already use each one, and makes the good ones club-wide.

Editing an exercise creates a new version, so plans and trainings that used the
old one keep showing it exactly as it was.

**For administrators.** The VCT permission that used to cover the exercise
catalogue, the age profiles and the macro-blocks has been split. The library
moved to the exercises permission coaches already hold; the age profiles and
macro-blocks kept a head-of-development-only permission, renamed
`tt_vct_admin_config`. Nobody gained or lost access — in particular the age
profiles, which set the age-safe intensity ceilings for U10–U14 players, remain
restricted.
