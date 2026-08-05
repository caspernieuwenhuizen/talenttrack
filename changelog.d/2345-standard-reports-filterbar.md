# Standard reports: shared filter bar + season-default window (#2345)

Team · Squad evaluation summary, Season summary, Season · Trial funnel and the
Scout report card now carry the shared filter bar — retrospective period pills
(Last week / This month / This season) plus a manual From / To range — with the
same season-default window the attendance and minutes reports use (current
season start → today, 90-day fallback). Each report's query, page sub-line and
Explorer drill now follow the selected window, replacing the hardcoded rolling
6- / 12-month bounds.
