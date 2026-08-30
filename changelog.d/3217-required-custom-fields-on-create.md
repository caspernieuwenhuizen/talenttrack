# Required custom fields are now enforced when a record is created (#3217)

Bump: patch

If your academy marked a custom field **required**, that was only enforced on
screens that actually showed the field. Create paths that do not render the
custom-field block — the new-player wizard, and creating a trial player inline
on the trial form — skipped it silently, so you could end up with a player
record missing a field you had made mandatory, with nothing to say it had been
skipped.

Creating a record now refuses when a required custom field is missing, and names
the field so you know which one.

Editing is unchanged, deliberately. A form that does not show a field still
leaves that field's stored value exactly as it was — that is what stops a short
edit screen wiping data it never displayed, and it is the reason the create case
was wrong in the first place: the two look identical in the code and are not the
same question.
