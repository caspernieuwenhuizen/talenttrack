# Methodology

The Methodology library is where your academy's coaching framework lives in TalentTrack: principles, formations + position cards, set pieces, and the club vision. Coaches reference these during session planning and player conversations.

## Where to find it

- **wp-admin**: TalentTrack → Methodology (Performance group).
- **Frontend**: the Methodology tile under Performance.

## Four tabs

1. **Principles** — coded principles like AO-01 (build-up), VS-02 (disrupting). Each carries an explanation, team-level guidance, per-line guidance for Aanvallers / Middenvelders / Verdedigers / Keeper, and a formation diagram.
2. **Formations & positions** — the formation visual + per-jersey-number role cards. Each position card lists attacking and defending tasks.
3. **Set pieces** — corners, free kicks (direct + cross), penalties, throw-ins. Attacking + defending variants.
4. **Vision** — the club's umbrella record: chosen formation, style of play, way of playing, and important player traits.

## Two sources of content

- **Shipped** content is curated by TalentTrack. Read-only by default — clubs can't break or modify it. Plugin updates may add new shipped entries.
- **Club-authored** content is created in your wp-admin by club admins. It lives alongside the shipped content in the library.

To start from a shipped entry without touching the original, click **Clone & edit** — you get a club-authored copy you can shape; the shipped row stays untouched.

## How it links to the rest of TalentTrack

- **Goals**: a goal can optionally link to one principle. Use this to make development goals concrete: "this player works toward AO-02 — Verzorgde diepte."
- **Sessions**: a session can list multiple principles being practiced. Coaches see at a glance which principles their week is covering.
- **Team plans (#0006, future)**: when team planning ships, it'll consume principles directly.

## Multilingual content

Every catalogue field is stored as a per-language JSON value. Today the library ships in Dutch (the source language of the methodology document) with English translations on shipped rows; club-authored entries support both. The library renders in the viewer's locale and falls back NL → EN → empty if a locale-specific translation is missing.
