# Notices from the operator now appear inside TalentTrack (#3499)

The operator can now send a short notice to academies, such as a planned maintenance window. It appears as a bar at the top of every TalentTrack screen for everyone who logs in, and as a notice in the WordPress admin, so academies that only use the frontend see it too.

- Warnings look different from ordinary notices.
- Each person can dismiss a notice; a new notice still shows.
- Urgent notices can be marked as not dismissable.
- A notice with an end time disappears at that time, even if the install cannot reach the Admin Center.
- The text is always shown as plain text.

The same notices are available to other front ends through `GET /me/broadcasts` and `POST /me/broadcasts/{id}/dismiss`.
