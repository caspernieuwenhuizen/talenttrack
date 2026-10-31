# Goals and evaluations screens follow the authorization matrix (#4488)

The goals create form (`?tt_view=goals&action=new`) now refuses a user who may not add goals. Before, it showed them a form they could never save. The "Add first goal" and "Record first evaluation" empty-state buttons, and opening an evaluation for editing, now use the same matrix-aware check as the other buttons on those lists. A user who holds the right only through the authorization matrix gets all of them, and two buttons on one list can no longer disagree.
