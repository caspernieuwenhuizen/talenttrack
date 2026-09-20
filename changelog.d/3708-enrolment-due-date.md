# A course deadline can be moved without losing anyone's progress (#3708)

An enrolment's deadline was fixed the moment it was created. When the academy
moved a staff course target — say from 7 September to 18 December — every
existing enrolment kept the old date, showed as overdue and fed the overdue
alerts and the learning statistics against a target that had already been
dropped. The only way out was to withdraw the coach and re-assign them, which
threw away everything they had read and handed in.

`PATCH /talenttrack/v1/enrolments/{id}` now moves the deadline, and
`{"due_at": null}` clears it. Status, start date and progress are left exactly
as they were. It asks for the same capability as withdrawing somebody, because
moving their deadline is the same class of decision. Once the new date is in
the future the enrolment drops out of the overdue listing, so the alert
resolves on the next reconcile.
