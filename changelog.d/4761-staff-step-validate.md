# New-team wizard: a staff slot only accepts someone the picker offers (#4761)

The staff step of the new-team wizard accepted any account id for a slot, so a
crafted post could make a player's or a parent's account team staff, with a
staff record and a team scope. The step now accepts only the accounts its staff
picker lists, and otherwise stays on the step with "Choose a staff member from
the list." next to the slot's name. An empty slot is still fine.
