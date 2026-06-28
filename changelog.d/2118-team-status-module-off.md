# Team roster: hide the player STATUS column when Player Status is off (#2118)

Bump: patch

The team detail roster gated its STATUS column (the traffic-light dot per
player) on whether the `PlayerStatusRenderer` class existed — but that class
is always autoloaded, so the column showed even when the Player Status module
was switched off. It now checks `ModuleRegistry::isEnabled()` for the module,
matching how the VCT panel on the same page is gated. With the module off the
roster shows only Jersey # and Player, no per-player status is calculated, and
the status styles are no longer enqueued.
