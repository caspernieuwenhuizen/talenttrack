# Messages now go to the address you edited (#2961)

Bump: patch

A person's email and phone were stored in two places that nothing kept in
step: the address on the People screen, and the address on their sign-in
account. Different parts of the product read different ones, so editing a
coach's email in TalentTrack could leave their alert digest going to the
old address with nothing to indicate it.

Every message the product sends — digests, alerts, scheduled reports,
trial reminders, workflow tasks, thread notifications, parent
notifications and one-off composed mail — now resolves the address the
same way, and the address shown on the People screen is the one that wins.
People with no sign-in account keep their contact details as before.

Password recovery deliberately still goes to the sign-in account's own
address, so that editing someone's contact details can never redirect
their password reset.
