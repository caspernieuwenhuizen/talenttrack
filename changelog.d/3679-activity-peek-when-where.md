# The activity summary over REST says when and where (#3679)

Opening an activity in the peek panel, or reading `GET /activities/{id}/summary`
from an app, showed only the date and the type. A parent looking at Tuesday's
training could not tell what time to be there or which pitch, so she fell back
on the team's WhatsApp group. The summary now carries the time window, the
presence time and the location, alongside the date and the type. An activity
with no times and no location still shows just the date and the type — empty
facts are left out rather than rendered blank.
