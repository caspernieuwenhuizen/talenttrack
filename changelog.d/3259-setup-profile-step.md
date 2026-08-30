# Setup asks how much product you are running, in the app (#3259)

Bump: minor

**How much product** — the step that decides the shape of the whole install — was the one step in the in-app Setup flow that still sent you to the WordPress admin. It now runs where the rest of Setup does.

It goes further than the admin version in one way. Each profile shows **What would change** before you pick it: which modules and features would be switched on, which off, and which your plan does not carry. The admin screen makes you choose and then tells you.

Nothing about how a profile is applied has changed — it is the same code behind both screens, so you can still start the step in one and finish it in the other. An install that has already been configured by hand is still sent to Modules → Install profile, where changes can be picked over row by row.

Two steps are still admin-only: **Import your squad** and **Add your staff**.
