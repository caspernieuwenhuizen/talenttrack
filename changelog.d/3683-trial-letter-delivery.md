# Trial letters: record that the family has it (#3683)

Bump: minor

Generating a trial letter has never sent it — printing, emailing or
handing it over is a human step — but nothing wrote that step down, so a
letter read "Active" whether it had gone to the family that afternoon or
was still sitting unprinted three weeks later. The head of development
reopening a case had no way to tell a family still waiting from one
already told.

The Letter tab now carries a **Delivery** card under the letter: pick how
the family got it (printed and posted, emailed, handed over in person)
and press Record delivery, and the card reads back the date, who recorded
it and the method. Clear delivery record undoes a mistake. The letter
history table gains a Delivered column, and a line next to Print view
says outright that generating does not send. TalentTrack still sends
nothing itself.

Over the API each letter row carries `delivered`, `delivered_at`,
`delivered_by` and `delivery_method`, written through
`PUT/DELETE /trial-cases/{id}/letters/{letter_id}/delivery`, with the
allowed methods declared on the route. Letters generated before this read
as not recorded — there was nothing to backfill from.
