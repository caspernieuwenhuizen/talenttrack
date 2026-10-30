# The usage dashboard's daily chart keeps every day in a daylight-saving week (#4283)

In the week after a daylight-saving change, the daily active users chart could lose a day at certain hours, because its days were counted in fixed 24-hour blocks while that week has a 23- or 25-hour day. Days are now counted on the calendar in the academy's own timezone, so a seven-day chart always shows seven consecutive days.
