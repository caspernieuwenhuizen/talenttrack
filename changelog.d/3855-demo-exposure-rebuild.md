# A generated academy's training tab no longer says "trainings: 7, minutes: 0" (#3855)

On a freshly generated demo academy the training tab of a player file contradicted itself: a non-zero number of completed trainings next to zero minutes, zero sessions and no last-trained date on every single principle. The player attended, and apparently trained nothing.

Minutes per principle are derived rather than authored, and the derivation ran on the app's own "finish this run" path only. The demo generator writes completed runs straight to the repository, so nothing derived from them and the table stayed empty until a nightly job that a demo install typically never reaches.

The training step now finishes by running the same rebuild the nightly job does, over the same source rows, so a player file is right the moment generation ends and a later nightly pass produces identical numbers.
