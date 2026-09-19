# Printing the player report works for a parent, and a refusal is no longer a server error (#3594)

A parent who pressed the print icon on their own child's profile got an error
page. The printable report only knew administrators, the player's coaches and
the player themselves. It now follows the same rule as the rest of the
product:

- **A parent can print their own child's report**, unless the child has hidden their evaluations from parents.
- **The print icon only shows** when the viewer can open the report.
- **Someone who isn't allowed gets a proper "no access" response** instead of a server error, and a logged-out visitor is asked to log in.
