# Trial inputs are frozen once the trial is decided (#3238)

A staff input is the evidence behind a decision about a child — whether the academy wanted them, and why. It could be rewritten through the API after that decision had been made, with no earlier version kept and nothing on any screen saying it had changed.

Inputs now freeze when the case leaves **Open** or **Extended**. Up to that point an assigned coach can still correct their own wording, including after submitting it — re-reading what you wrote an hour later and fixing a sentence is normal and should not need a manager. After it, nothing can change them, and an attempt is refused with a message saying why rather than quietly doing nothing.

The rule already existed on the Staff inputs tab and now lives in one place that both the screen and the API read, so the two cannot disagree again. Freezing an input does not close the case to the coach who wrote it — they can still open a decided case and read what they said.
