# Blueprint editor: a bad assignment ref no longer breaks formation + slot picking (#1619)

Bump: patch

On an editable (draft) blueprint, the formation dropdown and slot player-picker could both be dead even though the user had the cap and the blueprint wasn't locked. Cause: an exception during the editor's setup (e.g. a malformed assignment ref) aborted the script before its wiring ran, leaving the server-rendered pitch visible but inert. The editor now runs each setup/wiring step in isolation, so one bad ref can't cascade and disable the rest — and any offender is logged to the console for diagnosis. (Defensive hardening; if a specific payload still triggers it, the console now points at the exact step.)
