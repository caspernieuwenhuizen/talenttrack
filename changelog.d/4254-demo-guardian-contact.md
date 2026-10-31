# Demo academy: guardian contact on file for nearly every player (#4254)

A generated demo academy gave a guardian only to the few players linked to the
two parent personas, so most profiles said "No linked parent or guardian yet"
and the no-guardian alert flagged the whole roster. Every other active player
now has a guardian recorded on their record, with a made-up name and an email
on the undeliverable `demo.talenttrack.test` domain; the last player of each
squad is left without one so the alert still has a real sample. The Parents ·
Guardians card on the player profile now also shows a guardian recorded on the
player's own record as "Guardian on file" when no account is linked, which
helps real clubs that fill those fields too. The card stays staff-only.
