# A training run is dated by its training, not by the day it was planned (#3766)

Attaching a plan to a training used to stamp the run with the day the coach
did the planning, so a session planned a week ahead landed in the wrong week
and two sessions planned in one sitting shared a date. The run now takes the
date of the activity it hangs off; an explicitly supplied date still wins, and
a training whose date cannot be read still falls back to today.
