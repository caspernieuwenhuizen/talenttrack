# Animated per-phase tactical scenes (#2323)

Bump: minor

The methodology library gains a **Speelwijze** tab with animated per-phase tactical scenes: an SVG pitch that plays out player and ball movement for each game phase, with coaching points alongside. Scenes are grouped by aanvallen / verdedigen / omschakelen and ship for the JO13-1 Hedel set. Play / Pause / Restart controls are keyboard-accessible and honour reduced-motion (no autoplay — the final frame renders statically). Scenes are authorable over a new REST resource (`/methodology/tactical-scenes`); an in-app drag-and-draw scene editor is a planned follow-up.

Wizard plan: exemption — this ships no in-app record-creation flow. Scenes are shipped seed content, read-only in the app; creation is REST-only for a future editor. No "+ New" affordance, so no wizard applies (the drag-and-draw editor, when built, gets its own wizard decision).
