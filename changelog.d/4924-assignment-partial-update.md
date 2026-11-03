# Changing a team assignment keeps a date the request leaves out (#4924)

Updating a staff member's team assignment over the REST API now leaves a start or end date the request does not send as it was. A role change sent on its own no longer clears the dates, so it can no longer reopen an ended assignment or start one early. A date sent empty still clears it, and the team page's form, which always sends both dates, works as before.
