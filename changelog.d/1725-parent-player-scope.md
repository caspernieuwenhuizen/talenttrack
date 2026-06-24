# Security: parents can no longer open another family's child profile (#1725)

Bump: patch

The player detail view only checked the coarse `tt_view_players` capability, never that the viewer was actually linked to *that* player — so a parent could open any child's profile by id and the "Parents · Guardians" card would expose every co-guardian's name, email, and phone (a safeguarding leak for minors). The view now enforces the canonical per-player scope (`AuthorizationService::canViewPlayer`: own record / global / player's team / parent-of-this-player), and the guardians card renders for staff only (admin/HoD or the team's coach) — never for a parent viewing their own child. Also fixes an adjacent bug where the activities REST endpoint queried `tt_player_parents` with a non-existent `wp_user_id` column (correct: `parent_user_id`), which had wrongly blocked parents from their own child's activities.
