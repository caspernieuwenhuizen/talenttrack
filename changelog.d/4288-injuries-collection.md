# REST: GET /player-injuries for the squad injury overview (#4288)

The squad injury overview ("who is out right now") can now be read over the
API as `GET /wp-json/talenttrack/v1/player-injuries`, with the screen's team
and status filters, paginated. Each row carries the derived status and days
out. Access follows the screen exactly, through one shared scope class: a head
coach gets their own teams, a physio the team they are the physio of, and an
assistant coach or parent is refused. A team outside the caller's scope
answers an empty list. Every read that returns rows is audit-logged with the
player ids it returned.
