# Permanent person delete: staff evaluation ratings go too, and only this club's rows are touched (#4605)

Permanently deleting a person removed their staff evaluations but left the ratings on those evaluations behind, pointing at evaluations that no longer existed. The ratings are now deleted first, in the same transaction. Every delete and clear the cascade runs is also limited to the current club, and an id that does not belong to a person of the current club is dropped before anything runs, so on an install with more than one club a delete can never reach another club's rows.
