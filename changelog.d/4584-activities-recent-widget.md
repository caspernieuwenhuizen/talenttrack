# Recent activities widget lists activities again (#4584)

A custom widget built on the Recent activities source showed nothing, because its query read date and type columns the activities table does not have. It now lists the activities with their date and their type under its translated name, and the date filter works.
