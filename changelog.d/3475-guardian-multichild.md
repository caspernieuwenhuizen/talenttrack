# Demo academies now always include a parent with two children (#3475)

The guardian generator handed its single parent account one to three children
at random. Two runs in three that was two or three and the multi-child paths
worked; the other one left them unreachable — no child picker, no dashboard
child switcher, nothing to demonstrate to a club and nothing to test against.

Demo data now ships two parent accounts with fixed family sizes. **Demo
Parent** has one child, the guardian who lands straight on that child's record.
**Demo Parent Of Two** has two, which is what the picker and the switcher need.
Further parent accounts, on installs that have more, still vary in size.

Which players get a guardian is still varied — that part is meant to look like
a real academy, where not every parent has registered. What is no longer left
to chance is whether a code path exists in the generated data at all.
