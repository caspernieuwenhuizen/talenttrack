# Assistant coaches read their team's formation, and both coaches read its training load (#3706)

An assistant coach could see the line-up on the pitch but not the formation
it came from: the #1060 "assistant coach is operational" decision removed the
`team_chemistry` grant, and that entity also carries the team formation and
the blueprint. The assistant coach now reads it — own teams only, read-only,
with authoring still the head coach's. The chemistry board opens with it,
deliberately: one entity governs both, and the assistant coach already stands
in front of the squad it describes.

The team training load (`vct/teams/{id}/workload`) refused *both* coach
personas, not only the assistant — neither held the `vct_workload` row, while
the VCT documentation already said the head coach did. Both now read the load
for their own teams. Existing installs get the three rows from a top-up
migration, which never overwrites a matrix row an operator has edited.
