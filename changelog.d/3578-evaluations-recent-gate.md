# The recent-evaluations feed opens for coaches and the Head of Development again (#3578)

`GET evaluations/recent` is a coach's own feed of the evaluations they wrote
recently, and the way the Head of Development checks a coach's output. It
returned 403 to every account. The access check asked for a self-scoped grant
without saying whose self, so it always refused. Head and assistant coaches
now get their own feed. Anyone with club-wide read on evaluations, such as
the Head of Development, can pass `coach_id` to review a coach. A coach still
cannot read another coach's feed.
