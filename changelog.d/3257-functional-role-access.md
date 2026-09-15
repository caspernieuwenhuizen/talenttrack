# Injury access now follows the functional role, not the Staff role (#3257)

Bump: minor

The Staff role is one seat covering a physio, a kit manager and everyone in
between, and it carried access to players' injury records — medical data about
minors — for all of them alike. The authorization matrix could not separate
them: it keys on the persona, and both people hold the same one.

Access to injuries now comes from the **functional role a person holds on a
team**. A Physio reads and records injuries for the squads they are the physio
for, and for no others. A Kit manager reads the squad, the people around it and
the activity calendar, and nothing medical or evaluative — written out as its
own list rather than as "Staff minus injuries", so the next sensitive thing
added to Staff does not land on that seat unnoticed. A new **Kit manager**
functional role ships alongside the existing Physio one.

**Existing Staff accounts are unchanged.** An account that holds no functional
role keeps exactly the access it has today, including injuries. The narrower
shape is what somebody lands in once an academy assigns them a functional role
under People → Functional roles — an act, not a default — so nobody loses a
screen they were using without somebody deciding they should.

Under the hood the functional-role contribution resolves inside `MatrixGate`,
in one place, so every call site and the gate give the same answer; the grant
set lives in the new `config/functional_role_grants.php`. No migration, no new
table.
