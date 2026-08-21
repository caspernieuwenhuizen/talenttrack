# Knowledge library: lessons you can work with, not just read (#2643)

Bump: minor

Courses are stored as markdown so they can be reviewed in a pull request and
translated like any other text. That is a storage decision, and it was never
meant to cap a lesson at prose. This ship is the render.

A lesson now carries typed blocks. Three of them are tools a coach uses
rather than reads:

The **zero-point calculator** takes the minutes a squad managed before their
action count visibly dropped and returns the overload step their next twelve
weeks start from. Guessing that step is the difference between overload and
either injury or nothing happening at all.

The **week planner** checks a proposed week against the recovery times as it
is built, and names what breaks: small-sided games on Thursday with a
Saturday match leaves 48 hours where 72 are needed.

The **pitch-size calculator** turns a game format into dimensions, and says
where the rule of thumb stops working — below 7v7 the computed width comes
out narrower than a penalty area, and a pitch that narrow quietly turns an
intensive endurance session into an extensive interval one.

Alongside them: the six-week model as three phases you can open, an action
notation that draws quality and recovery instead of asserting them, a load
matrix that recalculates for a three- or six-week cycle, callouts, and
self-check questions.

Every block renders a usable state on the server, so a reader with
JavaScript blocked still gets the tables, the model and the default matrix.
A lesson made only of prose and callouts loads no JavaScript at all. An
unrecognised block renders as a code sample rather than breaking the page,
so a course written against a newer release degrades on an older one.

Supercompensation times, step tables and pitch sizes now live in one place
that the blocks, and later the session planner, all read — a course that
teaches "72 hours" beside a planner that warns at 48 would be worse than
either alone.

Still not readable in the app: the reader view arrives in #2646.
