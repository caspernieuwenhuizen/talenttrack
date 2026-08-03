# Hide "Complete activity" when the user can't complete it (#2325)

Bump: patch

The "Complete activity" button on the activity detail page (and the quick-action on planned activity cards) is now hidden when the current user can't reach the completion flow, instead of rendering a dead button that silently reloaded the page. Completing a training or paper-match routes through the evaluation wizard (which needs evaluation rights); completing a match with a running match-execution routes through its finalize view (which needs activity-edit rights). The gate now mirrors whichever destination applies, via a domain-layer `ActivityCompletionResolver::canComplete()` used by both buttons. Head coaches and evaluators are unaffected; assistant coaches who can't evaluate no longer see a button that does nothing.
