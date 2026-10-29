# Match prep counts starters against the formation, so a 6v6 prep saves with six players (#4280)

Match prep assumed eleven starters: the 1st-half starters tile read "x/11"
and the availability step refused to save with fewer than eleven players on
Present, so a six-a-side team could never save a prep. The starter target is
now the slot count of the prep's formation layout (six for a 3-2-1, eight for
a 3-3-1, eleven when nothing resolves). The tile, the wizard's minimum and
`GET match-prep/{id}` (new `starter_target` field) all use that one number.
Eleven-a-side behaviour is unchanged.
