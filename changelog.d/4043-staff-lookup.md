# Find a staff member by name for the trial panel (#4043)

Assigning a trial panel through the API needed a numeric account id, and
nothing a trial manager could reach produced one. A new staff lookup,
`GET /staff?search=`, finds staff from two or more letters of their name and
returns their People id, account id and name — never a player, a parent or an
e-mail address, at most 20 at a time. Only whoever manages trials or parent
links may search it. `POST trial-cases/{id}/staff` now takes `person_id` and
refuses a person without a login with a readable reason, since they could never
hand in an input; `user_id` is still accepted for one release. The staff
pickers on the trial case and in the new-trial wizard offer the same staff the
lookup finds.
