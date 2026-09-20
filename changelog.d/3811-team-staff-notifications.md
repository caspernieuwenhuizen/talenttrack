# A team's staff hear about its calendar and its registers (#3811)

Bump: minor

A team manager could be opted in to every kind of message the academy sends and receive none of them, because nothing in the product could name a team's staff. Every staff-directed message resolved its recipients one of three ways — club administrators, the subject of the record, or the head coach — and none of them reached an assistant coach or a team manager.

There is now one answer to "who on this team should hear about this", and three things use it:

- **Calendar changes.** An activity added, moved, re-located or cancelled for a team produces a message to the staff who run it. Changes are rolled up into one message per team per day, so a coach correcting six kick-off times does not send six emails; an activity starting within the next two days sends straight away instead, because a summary tomorrow morning would arrive after the session. This gives the "an activity changes time or place" preference toggle something to govern — it has offered a switch for a message nobody could receive since it shipped.
- **Repeated absence.** The absence flag now reaches the team's own staff and the head of development, as its own code comment always said it should, instead of whoever administers the site.
- **Unmarked activities and unrecorded registers.** The alerts about a past activity still sitting on "planned", or a completed one with no attendance, now reach the team's assistant coaches and team manager too, not the head coach alone.

Physios and kit managers assigned to a team are deliberately left off these: they are team staff, but a notification that reaches somebody who cannot act on it teaches everyone to stop reading the channel. None of these messages carry a player's name, an injury or anything medical.

Also fixed on the way: an activity's "last changed" timestamp was never written, so the detail page's audit footer reported the date the activity was created however many times it had been edited since.
