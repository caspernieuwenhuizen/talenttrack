# Bulk-invite a team's players (#1770)

Bump: minor

The **Player accounts** view gains a **Bulk invite a team** action: pick a team and generate a player invitation for every player on it who doesn't already have an account or a pending invite, in one click. The result is summarised (new invites vs. already-pending), and the daily invite limit is handled gracefully — if a large team hits the cap, the summary reports how many went out so the rest can be invited the next day. This is the deferred bulk-provisioning piece of the player↔account mapping epic; single link/unlink and per-player invites are unchanged.
