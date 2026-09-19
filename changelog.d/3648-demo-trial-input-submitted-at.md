# Demo trial assessments are no longer submitted in the future (#3648)

The demo generator stamped every trial panel assessment with the case's end
date. On an open trial that date has not arrived yet, so a demo academy showed
assessments submitted next week: the case read as already assessed, a panel
member's own screen reported a submission time that had not happened, and the
trial-input reminder never fired because it skips an input that already carries
a submission time. A seeded assessment is now dated inside its trial and never
later than the moment the demo was generated — a decided case keeps its
end-date stamp, and an open one gets a submission from the last few days.
