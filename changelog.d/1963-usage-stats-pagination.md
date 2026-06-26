# Usage detail: paginate the login and user-timeline event lists (#1963)

The usage-statistics drill-downs for **Logins** and a user's **Timeline** no
longer pull up to 500 rows into memory on every page view. Each list now
fetches a bounded 50-row window with a `COUNT(*)` for the total, and a
prev / next pager (with a "Page X of Y" indicator) lets you walk through the
full history a page at a time. The total event count shown above the table is
still the real total, not just the rows on the current page. Performance only;
no change to which events are recorded or who can see them.
