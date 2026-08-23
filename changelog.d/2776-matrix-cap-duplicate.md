# The academy admin can open the authorization matrix again (#2776)

Bump: patch

The frontend matrix editor shipped refusing the very role it was built for. An
academy admin opening it was told they did not have permission, and the same
refusal came back through the API.

The permission it needed had been described twice in the same list, once as
"may edit" and once, further down, as "may reset". The second description won
silently, so the editor asked for a privilege deliberately reserved for a
WordPress administrator. Resetting the matrix is unaffected and stays where it
was — it is checked separately, and was never part of this.
