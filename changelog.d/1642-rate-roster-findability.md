# Evaluation rating: find players faster on a big roster (#1642)

Bump: patch

The **Rate players** step of the new-evaluation wizard gains a **search box** (filter the roster by name as you type) and an **Only not-yet-rated** toggle (hide everyone already rated or skipped, so you see who's left at a glance). The toggle reads the same live per-player status as the existing *"N of M players rated"* progress line, so a player drops out of the not-yet-rated view the moment you rate them. Both are instant on-device filters and never change what gets submitted — directly addressing the "players are hard to find / which still need rating" pain in #1642. (The rating control itself was already rebuilt as a 5-star input in #1641, and behaviour is already an optional collapsed step, so this slice focuses on findability; collapsing the activity-picker + attendance steps stays a separate, riskier change since attendance writes real rows.)
