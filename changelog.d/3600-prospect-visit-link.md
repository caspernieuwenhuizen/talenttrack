# Scouting: a prospect is linked to the visit they were found at (#3600)

A scouting visit is meant to list the prospects found there, but nothing
outside the demo ever stored that link, so every real visit showed nobody.
Now:

- **"Log scouting find" on a visit links the new prospect to that visit**, and fills in the event from it.
- **The API can set or clear a prospect's visit.** A visit that doesn't exist is refused.
- **The scouting-visit routes list their fields**, so a misnamed field is no longer silently dropped.
- **A scout can no longer edit a prospect they can't open.** That applies to the prospect's contact details and consent too.
