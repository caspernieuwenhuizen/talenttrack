# Access checks tightened on tasks, consent requests, goals and the player profile (#4469)

Bump: patch

A task opens only for the people it is for: its assignee, the academy admin who may complete it on someone's behalf, an academy-wide task reader, and, for a task about a prospect, whoever sees that prospect on the onboarding pipeline. Anyone else sees "Task not found". Consent requests follow the same prospect scope. A player's goals, their goal conversations and the sections of the player profile (evaluations, goals, measurements, PDP, training, tournaments) now follow the family visibility switches the player sets, the same way the matching lists already did; the team's coaches keep seeing every section. A conversation message is sent only to participants who may read that conversation.
