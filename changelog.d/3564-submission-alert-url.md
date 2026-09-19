# Assignment-review alerts open the review queue again (#3564)

The "Assignment waiting for review" alert linked to `wp-cron.php` instead of
the review queue. The alert sweep runs under wp-cron, where there is no
current page, so the link was built on the cron request's own URL. It is now
built on the dashboard page, like every other alert. Existing alerts pick up
the corrected link on the next sweep; nothing needs migrating.
