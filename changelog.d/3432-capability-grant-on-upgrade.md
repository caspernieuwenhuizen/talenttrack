# A capability a release adds is granted without a trip to wp-admin (#3432)

A capability declared in a release reached the roles that should hold it only
once somebody loaded a WordPress-admin page: the re-assert hung off
`admin_init`, and nothing else triggered it between the update and that visit.
That was a safe assumption while running an academy meant going to wp-admin. It
stopped being one when Setup, the permission matrix and the dashboard all moved
to the frontend — an academy that works entirely in the app can go indefinitely
without an admin page load, so the grant did not arrive late, it did not arrive.
The safeguarding broadcast is the sharpest illustration: the capability that
permits the one message nobody can refuse sat ungranted on such an install while
the preferences screen told every parent the message could not be switched off.

The role and capability shape is now asserted on the plugin version change, the
same trigger the schema already uses, from any surface that loads a page. It
carries its own stamp rather than riding the schema's, so a failed migration
cannot hold a capability grant hostage. The re-assert stays additive: it hands a
role what its definition says it should have and removes nothing, so a grant an
operator withdrew through the authorization matrix — a separate store this never
writes — stays withdrawn.

The wp-admin re-assert is kept as a self-heal for installs that do use it. Tests
cover both directions, and deliberately never fire `admin_init` — the suite's
not firing it is why this shipped.
