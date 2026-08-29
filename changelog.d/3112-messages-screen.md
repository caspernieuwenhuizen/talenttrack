# The Messages settings screen says what each message is (#3112)

Bump: minor

**Configuration → Messages** was eighteen checkboxes wrapping across a
paragraph, each labelled with an internal name and trailed by a comma-separated
list of channel keys. Nothing on it said what a message was, who received it,
or what made it send — on the screen where an academy decides what mail reaches
the parents of its players.

It is now grouped by what the message is: *People need to know now*, *Somebody
asked for it*, *Moments in a player's season*, *Reminders and summaries*. Each
message says in one sentence what it does, who gets it, and what triggers it.

**Messages nothing fires yet are labelled as such.** Eleven of them have copy
and settings but no trigger; a checkbox for a message the product cannot send
is a lie on a settings screen. They are marked "Not sent automatically yet"
rather than hidden, so the list still shows what exists.

**Channels are now their own control.** Whether a message goes at all and how it
is allowed to travel are two decisions, and the screen used to show only the
first. Each message with more than one option now lists the ways it may reach
people, and unticking one takes it out of use for that message — an academy with
no SMS credit, or one that would rather not reach school-age players on
WhatsApp, sets that here.

The screen also now explains something it never did: a message reaches a person
on **one** channel, not all of them. TalentTrack works down the list and uses the
first that can actually reach them, so the list is a fallback order rather than
four copies of the same message. Unticking every option is refused — a message
with nowhere to go records as a failure rather than as a decision, so switching
the message off is the way to stop it.

Rebuilt mobile-first: stacked cards, 48px targets, no horizontal scroll at
360px. The screen's inline `<script>` became an enqueued asset, and its styles a
stylesheet reading the design tokens.
