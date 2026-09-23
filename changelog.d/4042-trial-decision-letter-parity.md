# Deciding a trial generates the letter whichever surface records it (#4042)

Recording a trial decision did two different things depending on the door you
came in by. The case screen recorded the decision and generated the letter for
the matching audience; `POST /trial-cases/{id}/decision` recorded the decision
and stopped, so a head of development who decided over the API ended up with a
decided case and nothing to hand the family. The rule saying which of the three
letters a decision warrants lived in a private method on the render class,
which is why the route could not have made the second half of the call.

Both paths now call one domain service, `TrialDecisionService`, which records
the decision and then generates the letter the decision warrants. The decision
response carries the new `letter_id`. Generating stays an explicit step and
stays out of the hook, so a decision written by the trial-group workflow still
produces no letter — and those three decisions now produce none at all, rather
than falling through to the final-denial letter, which is what a case that had
just been offered a team place used to get if anyone pressed Regenerate. The
Letter tab, Regenerate and the delivery record are unchanged, and generating a
letter still does not send it.

`docs/trials.md` described both behaviours in different sections; it now
describes one.
