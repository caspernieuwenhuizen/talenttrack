# The in-app Setup flow no longer dead-ends (#3140)

Four steps had been added to the setup wizard over time and only ever built
for the WordPress admin. Reaching any of them from **Configuration → Setup**
showed "Unknown step." — a dead end whose only exit was **Start over**,
which put you back at step 1 to walk into the same wall again.

**What we send** now works there. It is the step that asks which messages
TalentTrack may send on your behalf, and it matters most of the four: a new
academy starts with everything switched off, so an operator who never met
this step ended up with an install that quietly told nobody anything.

The other three — How much product, Import your squad, Add your staff — are
still admin-only for now, but they say so. Each names itself, tells you your
progress is saved, and offers to carry on in the WordPress admin or leave
setup, instead of pretending to be a bug.

The progress bar also listed five of the flow's ten steps, so it showed a
run that looked nearly finished right up to the point it stopped. It now
lists them all.
