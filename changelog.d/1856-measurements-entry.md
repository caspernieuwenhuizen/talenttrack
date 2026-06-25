# Measurements & Testing — staff result entry (#1856)

Bump: minor

Adds the staff-facing **Record measurements** surface for the Measurements module (epic #1854). A coach picks a team, a test, and a date, then enters one value per player and saves the whole roster in one shot — saving creates a completed testing session and one result per filled-in player against it (blank rows are skipped). The input adapts to the test's value type (numeric/scale → a numeric keypad with the unit shown; pass/fail → a dropdown). Matrix-gated on `measurements` change (a coach only reaches their own teams; head-of-development / admin see all); bulk entry is a wizard exemption under §3(b). Mobile-first, Save + Cancel, server-rendered (nonce-protected POST, no extra client JS). The "+ New test" wizard for creating the tests themselves follows.
