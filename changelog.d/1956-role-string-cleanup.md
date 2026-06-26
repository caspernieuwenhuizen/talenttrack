# Player-notes access no longer gated by WP role name (#1956)

The player-notes thread adapter no longer denies access based on the
player or parent WP role name. Its decision now rests solely on the
player-notes capability plus the existing team-ownership scope check —
pure players and parents, who hold no player-notes capability, stay
denied exactly as before. (A follow-up, #1982, tracks how dual-role
staff-and-parent accounts resolve that capability.)

Also removed an unused duplicate role-lookup helper from the
authorization service — pure cleanup, no behaviour change; the canonical
role-lookup chokepoint is untouched.
