# Inviting a prospect now checks the permission that was always documented (#3869)

Bump: patch

`tt_invite_prospects` was mapped into the authorization matrix, named in the
onboarding-pipeline documentation as the gate on inviting a prospect to a test
training, and checked by nothing. Granting or revoking it changed nothing a
user could see; the only real gate was who the workflow assigned the task to.

It is enforced now, on both pipeline tasks that claimed it — *Invite to test
training* and *Confirm test-training attendance*. Head of Development and
Academy Admin hold the permission and are unaffected. A **head coach** who had
been handed the invite task can no longer complete it: arranging a child's
first visit to the academy is the Head of Development's decision.

Nobody loses the task silently. Somebody who holds it without the permission
still opens it and reads what it says; the form is locked and carries a note
saying which permission is missing and to ask an academy administrator to grant
it or hand the task on. The parent's own confirmation link is untouched — it is
signed, nobody is logged in on it, and it completes the task as before.

Workflow templates declare this for themselves via
`TaskTemplateInterface::requiredCapability()`; every other template keeps
assignment as its only gate.
