# Demo data: one trial case per trialled player, not two (#3565)

Every procedural demo run wrote the scouting pipeline twice. Each trialled
player got two overlapping trial cases created seconds apart, the first two
roster players each had two open cases, and scouting visits and prospects
were doubled. The trial cases now have a generator of their own, so the
`trials` and `pipeline` steps each write only their own rows, once. A test
now fails if one generator class is ever mapped to two demo categories again.
Existing demo data keeps its duplicates until the demo is wiped and
regenerated.
