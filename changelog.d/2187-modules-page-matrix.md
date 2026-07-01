# Modules admin page is now matrix-driven (#2187)

Bump: patch

The Modules admin page (wp-admin `tt-modules` and the frontend
`?tt_view=modules`) previously gated access on a WordPress role-name compare
(`current_user_can('administrator')`), which the authorization matrix could
not govern. It now checks the `tt_manage_modules` capability, bridged to a
dedicated `module_management` matrix entity, so the matrix decides who can
enable or disable modules — the same as every other admin surface. A new
migration re-seeds the grant onto existing installs (Academy Admin retains
access; WordPress administrators bypass unconditionally, so no one loses the
page on upgrade).
