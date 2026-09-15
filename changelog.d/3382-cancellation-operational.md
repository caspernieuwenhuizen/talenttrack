# A cancellation can no longer be switched off (#3382)

TalentTrack already treated *"a training is cancelled"* as urgent in one
direction: it ignores quiet hours, so it will reach a family at 23:00 rather
than wait for morning. At the same time it sat on the opt-out list, so the
same family could switch it off entirely.

One of those two judgements had to be wrong, and it was not the first. A
cancellation nobody received means a child dropped at a pitch where nothing
is happening. It is now unmutable, alongside safeguarding messages and
account-recovery email.

Anyone who had already muted it will start receiving cancellations again,
and their stored preference is cleared so the setting matches what the screen
shows. **My settings** now lists all three unmutable kinds explicitly —
ticked, greyed out, with the reason — rather than leaving them off the page:
a preferences screen that quietly omits what you cannot refuse tells you less
than one that names it.

Nothing in the message log was rewritten. Sends that were suppressed by an
opt-out that was valid at the time stay recorded as they happened.
