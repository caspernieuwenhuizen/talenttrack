# PHP tests also run when a stylesheet changes (#5140)

The PHP test suite now runs for a change that only touches a stylesheet. The tests that check stylesheets, such as the rule that status text uses its readable shade, now catch a breaking sheet before it merges instead of after.
