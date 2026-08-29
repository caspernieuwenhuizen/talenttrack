# Recording a trial decision now moves the player (#3116)

Bump: minor

Recording a decision updated the trial case and nothing else. An admitted player
stayed on **Trial** status indefinitely — the academy said yes to a child and the
record did not show it — unless somebody separately ran the team-offer workflow.

Recording a decision now settles the player's status:

- **Admit** → Active.
- **Decline (final)** → Released, and the record is archived into the recycle bin,
  where it stays restorable.
- **Decline (with encouragement)** → Inactive, and **not** archived. That decision
  means "not now, come back", so the player stays on the books and eligible for a
  future trial. Archiving them would tell your own system the opposite of what you
  just told the family.

Only a player still on Trial status moves, so recording a decision twice, or
deciding on a player who was already promoted another way, changes nothing.

The behaviour is identical whether the decision is recorded on the Decision tab
or through the API — it hangs off the decision itself rather than off either
screen.

The documentation claimed the letter is generated automatically. It never was,
and it should not be: someone ought to read a letter to a family before it
exists. The Letter tab button stays the way to produce it, and the docs now say
so. The docs also claimed both declines archive the player, which is the mistake
this release fixes.
