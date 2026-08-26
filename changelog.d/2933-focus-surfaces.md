# The navigation bar steps aside on the live match and training screens (#2933)

Bump: minor

Running a live match and running a training session both put their own controls
along the bottom of the phone screen, because that is where your thumb is when
you are holding it one-handed at the side of a pitch. The navigation bar sat
underneath those controls and took roughly 190px of a 640px screen with it —
about half of what you were actually reading.

On those two screens the bar now steps aside. The breadcrumb trail at the top is
still the way out, the slide-out menu is untouched, and tablets and laptops are
unaffected, because the bar only exists on phone-width screens to begin with.

Which screens qualify is written down rather than assumed: each one is listed
with the reason it needs the space, and adding another is a one-line decision
somebody has to make on purpose.
