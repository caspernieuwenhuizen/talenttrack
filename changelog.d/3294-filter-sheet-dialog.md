# The filter sheet behaves like the modal it claims to be (#3294)

On a phone, tabbing through the **Filters** sheet walked straight out of it and
into the list behind — which is covered by the overlay, so the focus ring was
invisible and you ended up operating a page you could not see. A screen reader
could wander into the same covered content, and dragging on the dimmed area
scrolled the list underneath instead of doing nothing.

The sheet now uses the browser's own modal dialog, the same one the confirm
prompts and the saved-views dialog already use. Focus stays inside it until you
close it, the page behind is properly hidden from assistive technology and no
longer scrolls, Escape closes it, and tapping the dimmed area closes it as
before.
