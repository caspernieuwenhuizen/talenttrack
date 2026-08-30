# You can now publish a training plan, and the coaches get told (#3220)

Bump: minor

A plan you are still working on and a plan your coaches should read looked the
same in the list, and telling them it was ready happened in a group chat.

A plan's page now says whether it has been published, with a **Publish and tell
the coaches** button. Publishing sends the head coaches the plan is for a
message with its title, its focus and a link to it — the one team's if the plan
names a team, every team's if it is club-wide.

Publishing announces; it does not lock anything. The plan stays fully editable
afterwards, and fixing a typo sends nothing. Coaches are told once: pressing
Publish on a plan that is already published does nothing at all. Unpublish is
there to correct a mistaken publish — it clears the mark, sends nothing, and
cannot unsend a message that has already gone.

Templates cannot be published; there is no squad they belong to.

This also gives the **Methodology / activity plan delivered** message something
to fire from. It has shipped since v3.110.18 with nothing behind it, because the
product had no idea what publishing a plan meant. Now it does. Whether an
install actually sends it is the messaging switch's business, as with every
other message.
