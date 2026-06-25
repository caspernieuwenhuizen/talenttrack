# Self-review nudge when a PDP talk's window opens (#1852)

Bump: minor

When a development talk's planning window opens, the player now gets a **"Prepare for your development talk"** task in *My tasks / Today's work*, due on the talk date, that opens *My PDP* at the self-reflection. It's a nudge, not a gate: saving the reflection completes it, conducting the talk auto-resolves it with no penalty even if it was skipped, and nothing is ever blocked if it's ignored. The sweep that creates these runs on the workflow engine's own scheduler (no ad-hoc cron) and is idempotent — exactly one task per conversation. On the coach side, the PDP conversation list gains a **Self-review: Done / Not yet** column per upcoming talk — visibility only, never a gate on conducting or signing off. Phase 4 of the development-hub epic (#1846).
