# The player profile's load flag says what it is, instead of "PHV" (#4033)

A player who is carrying less load than the plan asks for now has a **Load
restriction** on their profile, not a "PHV" flag. The old label named one of
the flag's seven reasons — peak height velocity, a growth spurt — so a player
recovering from a sprained ankle wore a pill saying they were growing, and
the panel below it expanded the same three letters as "Physical / Health /
Vitality" while the API called it a growth spurt. One thing, three names,
none of them true most of the time.

"Growth spurt (PHV)" is now one reason in the list, beside the injuries, the
medical reasons and temporary fatigue. Nothing was migrated: every reason
already stored keeps its key and its meaning. The reason also travels with
the restriction — the hero pill's tooltip names it, and the sideline banner
on the coach view lists it next to each player, so a coach reading the
banner five minutes before a session can tell an ankle from a growth spurt.

Two smaller repairs ride along. Who may set a restriction now has one
answer: `tt_vct_plan` plus VCT change scope on the player's team, which is
what the REST route already required — the on-screen panel had been asking
only for player-edit rights, so it wrote what the API refused. And the
reason list was two hardcoded copies, the picker's and the save handler's,
140 lines apart; they are one list now, so a reason cannot be offered and
then silently discarded on save.

An open injury still does not create a restriction by itself. A member of
staff decides whether the plan has to change, which is deliberate.
