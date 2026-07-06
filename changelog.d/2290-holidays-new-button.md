# Holidays: "New holiday" button always available on the list (#2290)

Bump: patch

The academy Holidays list now shows a persistent "New holiday" button in
its header, gated on the manage-holidays capability. Previously the only
create affordance was the empty-state card, which disappears once at least
one holiday exists — so a manager with full rights had no visible way to
add another and had to reach the wizard by URL. The empty-state CTA is
unchanged.
