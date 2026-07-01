# Goals list: status filter defaults to Active, no "All" (#2202)

Bump: patch

The Goals list status filter no longer wraps to a second line. It now offers
three semantic buckets — Active, Achieved and Missed — rendered as pills with
coloured status dots, drops the "All" option, and defaults to Active so the
list opens on the goals a coach is actively working on. The REST endpoint maps
these buckets onto the canonical completed / cancelled status codes and still
honours raw status codes on existing deep links.
