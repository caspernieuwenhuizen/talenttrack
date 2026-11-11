# Goal approval follows one rule on every route (#5112)

A goal a player sets for themselves waits for approval until the player's head coach, or an administrator, decides on it. That rule now holds wherever a goal's status is changed: the status dropdown, the goal edit form (`PUT /goals/{id}`) and the wp-admin goal form. It also applies whichever way the waiting status is stored ("Pending Approval" or `pending_approval`). Editing the other fields of a waiting goal, or saving it with its status unchanged, works as before.
