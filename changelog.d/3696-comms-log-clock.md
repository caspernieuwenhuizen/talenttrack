# The message log records the time the decision was actually made (#3696)

Message-log times came from the database server's clock, while every decision about when to send — quiet hours above all — uses the site's. On an install where the two differ, the log showed a time the decision was not made at: a message correctly held until the morning could appear in the log timestamped inside working hours, which reads as the quiet-hours setting being broken when it is working exactly as configured.

New rows are now stamped from the site's clock. Rows written before this keep the time they were given, because there is no record of what the offset was, so the log page now says older rows may carry the other clock.
