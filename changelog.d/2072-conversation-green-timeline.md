# Goal conversation restyled to the green/gold timeline (#2072)

Bump: patch

The conversation ("Gesprek") on a player's goal-detail page used a generic
blue chat style — a right-aligned blue self-bubble and navy author names —
that clashed with the 2026 green/gold design shown to parents and players in
the pilot presentation. It now renders as a single left-aligned timeline:
each message carries a green ring marker, a muted date above a bold green
author name, and a white bubble with a thin border, and the Send button is
green. The change is in `frontend-threads.css` only — markup, REST, polling,
and the edit/delete affordances are untouched, so both initially-rendered and
newly-posted messages share the look. Mobile-first rules are preserved
(360 px single column, 48 px Send, 16 px textarea, focus-visible rings,
reduced-motion).
