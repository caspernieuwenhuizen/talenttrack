# Measurements follow the functional role, not the Staff seat (#3433)

Bump: patch

The previous release moved injury records off the generic Staff seat and
onto the Physio functional role, and left measurements where they were.
That fixed half the problem: `tt_staff` is one WordPress role covering the
physio, the kit manager and everyone in between, so a Staff account issued
to move shirts still read every player's height, weight and test results.

Measurement *reading* now comes from the functional role a person holds on
a team, the same way injuries do. Physio, Head coach and Assistant coach
read the measurements of the squads they hold that role on. Kit manager
reads none, on any team.

Recording a measurement is unchanged. Entering heights and test results is
part of the Coach, Head coach and Team manager roles, which this does not
touch — nobody who runs a testing session loses the entry form.

**Nothing changes for an existing Staff account until you give that person
a functional role.** An account with no functional role on any team keeps
exactly the access it has today, including recording. The narrower shape
is something an academy opts into, one person at a time, under
**People → Functional roles**. There is no migration and no data change.

This is the persona-level floor and not a replacement for the per-test
visibility level: which tests a reader sees once admitted is still decided
per test under **Manage tests**, and a test marked medical-only stays
medical-only for everybody.

Also fixed alongside it: the team pickers and export scopes on the
measurement surfaces asked "which teams are you attached to?" rather than
"which teams may you read measurements for?". Those were the same question
until access started following the functional role; now they are not, and
the two test reports, the BMI report, the browse and trends REST routes
and the results export all ask the permission question per team.
