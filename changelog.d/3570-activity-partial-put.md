# Updating one field on an activity no longer wipes its title, team and date (#3570)

`PUT activities/{id}` rebuilt the whole activity from the request. A coach
who sent only the match fields they meant to change got a 200 and an
activity with no title, team, date, times or location. Its type fell back
to training and its status to planned, so the match dropped off the team's
schedule. The update now keeps the stored value for every field the request
leaves out. A field sent empty is still cleared, and switching a match to
another type still clears its match-only fields. A PUT on an activity that
does not exist now returns 404.
