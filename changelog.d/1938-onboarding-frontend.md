# First-run Setup moves to a frontend flow (#1938)

Bump: minor

The first-run onboarding wizard now lives on the frontend at
**Configuration → Setup** (`?tt_view=setup`) instead of bouncing to
wp-admin. The full flow ported across: a stepper through academy basics →
first team → first admin → dashboard page → done, with skip on the optional
steps, Cancel on every step, and a "Run again" / "Start over" affordance
that re-enters the flow without deleting the teams, staff, or pages you
already created. Progress is saved automatically, so you can stop and resume
from the step you left off on.

New REST endpoints back every step — `POST /onboarding/advance`,
`/onboarding/academy`, `/onboarding/first-team`, `/onboarding/first-admin`,
`/onboarding/dashboard-page`, and `/onboarding/reset` — all gated on
`tt_edit_settings`. The controller is thin: every side effect (team / staff
creation, the Club Admin grant, dashboard-page creation, state advance)
reuses the same `OnboardingHandlers` / `OnboardingState` domain layer the
wp-admin wizard uses, so the two surfaces never drift. The wp-admin Setup
wizard stays as the power-user fallback.
