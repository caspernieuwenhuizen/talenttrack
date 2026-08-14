# "Under development" pill for features (#2387)

Bump: minor

Admins who manage modules can now mark any feature as **under development**
from the module/feature page (`?tt_view=modules`) with a checkbox beside its
on/off switch. When set, every view that feature owns shows a small,
informational amber "Under development" pill at the top, visible to everyone
(coaches, players, parents) so they know the surface is still being built and
may change. The flag is purely cosmetic — it never disables or hides
anything — and is independent of the on/off switch, so a feature can be live
and flagged at once. The flag is stored per club on `tt_feature_state` and is
readable/settable through the `/talenttrack/v1/features` REST endpoint.
