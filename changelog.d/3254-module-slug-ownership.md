# Switching a module off now actually removes its screens (#3254)

Bump: minor

With **Training plans** switched off, the activity page still offered **Execute training** and `?tt_view=training-run` still opened the sideline view. Training was not special — it was the one somebody noticed.

Two causes, both now fixed. A screen's owning module was worked out from the tile the module registers itself, and a switched-off module registers nothing — so the check for "is this module off?" had nothing to read in exactly the situation it exists for. Ownership for 45 screens now lives where it cannot disappear. And the buttons that link between screens only ever asked whether the user had permission; a WordPress administrator passes every permission check by design, so the operator who had just switched the module off was the one person still being offered it. Those buttons now ask whether the screen exists on this install before asking who may open it.

A new CI check walks every screen the dashboard can route to and refuses a module screen whose ownership is not declared, so the gap cannot reopen.
