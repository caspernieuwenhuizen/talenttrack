# Demo data: one generation run, one calendar (#3775)

Generating a demo academy runs in steps across several requests, and every
date it writes is derived from a single instant — but each step re-read the
clock, so a run that carried on past midnight or across the turn of a week
laid its later steps out against a window a day further on than its earlier
ones. A fixture could end up in a different week from the training that
precedes it. The run now writes its clock down when it starts and every
later step reads that, so the whole calendar is one grid however long the
generation takes.

No change to the weekly rhythm, the match times or how far ahead a demo
generates. Activities from an older generator build that are already in the
database are left alone — they are indistinguishable from rows an operator
added on purpose, and a regeneration on current code replaces the tagged ones.
