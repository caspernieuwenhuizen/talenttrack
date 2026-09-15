# Adding your staff now works on the frontend too (#3261)

The setup flow's **Add your staff** step is available from the frontend
(`?tt_view=setup`), not only in wp-admin. It was the last step still showing
a not-yet-available notice, so an operator who set the academy up from the
frontend reached their coaching staff and had to switch to the admin to
enter them.

It behaves exactly as the admin version does, because it runs the same code:
each person gets a record, anyone with an email address gets an invitation
prepared, and **nothing is sent until you say so**. Leave the step — or
setup entirely — and the prepared invitations stay ready and waiting under
**Configuration → Invitations**. The number waiting is written on the button
rather than left for you to work out, because an academy wondering weeks
later why nobody received a login is the outcome this step is shaped to
avoid.

The invitation itself is never shown on screen, on either surface. It is
emailed and only emailed: anyone who can read an invitation link can use it
to claim that person's account, and these accounts reach a database about
children. If somebody does not receive theirs, resend it from
**Configuration → Invitations**.

With this, all ten setup steps work from the frontend.
