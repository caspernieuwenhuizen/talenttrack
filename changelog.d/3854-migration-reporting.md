# Permission top-ups say what they did, and say when they did nothing (#3854)

A migration whose job is to add permission rows to an existing install could previously come to nothing without saying so — a missing table or an unreadable seed file produced no rows, no error and no trace, and the migration was still recorded as having run. A clean run and a failed one looked identical afterwards.

Those migrations now report what they wrote, and warn when they wrote nothing where rows were expected. There is also a test asserting that the coach's workload permission is really present, so it can be checked by running the test suite rather than by inspecting a database.

The coaching permission this was filed against turned out to be in place already; the report behind the issue predated the release that added it.
