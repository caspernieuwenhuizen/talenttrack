# Read-only observer reads the non-sensitive entities; PDP stays closed on every route (#4688)

Bump: minor

The read-only observer reads the non-sensitive entities academy-wide and read-only: each player's tournament record, the exercise library, the VCT library and workload, and the academy's reference data (seasons, methodology, football actions, evaluation categories and weights, the rating scale, holidays, the help pages). Entities that carry a field the sensitive-field registry cannot mask yet follow together with their masking, and measurements, the tournament planner, training plans and load, VCT sessions and media follow after their own review. Injuries, safeguarding notes, player and staff-only notes, message threads, parent accounts, the PDP cycle, the audit log and Report Studio stay closed. The observer holds no create, change or delete right anywhere.

Write rights ask the change level: the VCT configuration and training plan capabilities map to `change`, so a read grant opens no write, and removing a training observation asks for its author or someone who may evaluate that player. On a VCT session's load-restriction banner, the reason shows only to staff for that player.

`GET /players/{id}/pdp` asks read on the player's PDP section, so every reader who may open the record but holds no PDP right gets `403`: the observer, a scout on a linked player, a staff member, and a team manager outside their own team. The role carries no raw `tt_view_pdp` capability. Existing installs get the new rows through migration 0310, which leaves every observer cell an academy already changed as it is.
