# Authorization: bridge six act-caps to the matrix + two approved access changes (#1941)

Six legacy `tt_*` act-capabilities now resolve through the authorization
matrix instead of native WordPress capabilities, so the frontend renders
and REST endpoints that gate on each cap can no longer answer differently:
`tt_manage_teams`, `tt_manage_staff_development`, `tt_manage_modules`,
`tt_view_scout_assignments`, `tt_manage_invitations`, and
`tt_rate_player_behaviour`. Four bridges are access-preserving. Two carry
an approved effective-access change: the Head of Development now sees the
all-teams exports picker (`tt_manage_teams` → `team:create_delete`, the
HoD oversees the whole academy), and assistant coaches can no longer author
behaviour ratings (`tt_rate_player_behaviour` → `player_behaviour_ratings:change`;
the matrix treats behaviour-rating as a development judgment, not an
operational one). The stale behaviour-rating grant on the assistant-coach
role is revoked on upgrade so installs whose matrix is still dormant
converge on the same answer. Invitation management stays admin-only
(`tt_manage_invitations` bridges to the admin-level `settings` entity, not
the broad `invitations` entity that coaches and parents hold to send invites).
