# Demo data: the youngest squads' measurements read like children's (#4036)

Bump: patch

A generated academy's U7 and U8 players were given a juggling result of -9
reps against a target band opening at -15, and body measurements that
contradicted their own profile: 15 kg measured where the record said 24, a
117.5-121 cm band where the record said 114.

Two causes. The test battery's age model was a straight line anchored on a
twelve-year-old, so it ran off the bottom of the age-group ladder; and the
Height and Weight tests ran on a second body model that never consulted the
player record the same run had written.

Now there is one body model (`DemoAnthropometry`) behind the player record,
the Height and Weight readings and their target bands, and each player's
readings are their own recorded height and weight walked back to the age they
were at each earlier round — so a progression series shows growth rather than
a disagreement. A test whose age line falls below what the youngest age group
can do curves towards that value instead, and every reading and band edge is
held inside what the test can physically read, so nothing reads negative.
