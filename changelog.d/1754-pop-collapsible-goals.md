# POP page: collapsible goals with a conversation per goal (#1754)

Bump: minor

The player's POP page now renders its learning goals as **collapsible
cards** (native `<details>`, keyboard-accessible). Each card header shows the
goal title, status, due window, and a 💬 count of that goal's messages.

Expanding a goal reveals two columns: the goal's detail (description, linked
methodology, evidence) on the left and **that goal's own conversation thread
on the right** — every goal has a separate thread, so discussions don't mix.
In-progress goals open by default. Reuses the existing per-goal threads
(`thread_type='goal'`), and makes `FrontendThreadView` multi-instance-safe so
several conversations can live on one page.

Per-goal **progress %** and scored **evidence (Bewijslast)** shown in the deck
mockup are a follow-up — they need the evaluation-evidence schema in #1717.
