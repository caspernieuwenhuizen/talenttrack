<!-- audience: user, admin -->

# Methodology

The Methodology library is where your academy's coaching framework lives in TalentTrack: the per-club framework primer, principles, formations + position cards, set pieces, the vision, and the football actions catalogue. Coaches reference these during session planning and player conversations.

## Where to find it

- **wp-admin**: TalentTrack → Methodology (Performance group). Voetbalhandelingen has its own entry under TalentTrack as well.
- **Frontend**: the Methodology tile under Performance.

## Six tabs

1. **Raamwerk (Framework)** — the per-club methodology primer: an introduction, the football model overview, voetbalhandelingen, the four phases of attacking and defending, learning goals, factors of influence, and reflection / future. Every section can carry illustrations.
2. **Principles** — coded principles like AO-01 (build-up), VS-02 (disrupting). Each carries an explanation, team-level guidance, per-line guidance for Aanvallers / Middenvelders / Verdedigers / Keeper, a formation diagram, and a primary illustration.
3. **Formations & positions** — the formation visual + per-jersey-number role cards. Each position card lists attacking and defending tasks plus an optional diagram.
4. **Set pieces** — corners, free kicks (direct + cross), penalties, throw-ins. Attacking + defending variants, illustrated.
5. **Vision** — the club's umbrella record: chosen formation, style of play, way of playing, and important player traits.
6. **Voetbalhandelingen** — the football actions catalogue (aannemen, passen, dribbelen, schieten, koppen — plus vrijlopen, knijpen, jagen, dekken, and supporting actions like spelinzicht / communicatie).

## Two sources of content

- **Shipped** content is curated by TalentTrack. Read-only by default — clubs can't break or modify it. Plugin updates may add new shipped entries.
- **Club-authored** content is created in your wp-admin by club admins. It lives alongside the shipped content in the library.

To start from a shipped entry without touching the original, click **Clone & edit** — you get a club-authored copy you can shape; the shipped row stays untouched.

## How it links to the rest of TalentTrack

- **Goals**: a goal can optionally link to one principle and one football action. Use either or both to make development goals concrete — "this player works toward AO-02 (build-up via the wings)" or "this player improves dribbling".
- **Sessions**: a session can list multiple principles being practiced. Coaches see at a glance which principles their week is covering.
- **Team plans (#0006, future)**: when team planning ships, it'll consume principles directly.

## Adding diagrams and images

Each catalogue entity (principle, set piece, position, vision, framework primer, phase, learning goal, influence factor, football action) has a "Diagrammen en afbeeldingen" section in its edit page. Click **Afbeelding kiezen…** to open the WordPress media library, pick or upload an image, and on save the picker creates a record in `tt_methodology_assets` linked to the entity. The first image becomes the primary one (rendered as the hero) and additional images can be added, captioned (NL + EN), promoted to primary, or archived.

The plugin ships with diagrams sourced from the original methodology document, attached automatically to the matching shipped entity. To replace a shipped diagram with your own, archive the shipped image and add your own — the form will keep both versions until you archive the one you don't want.

## Multilingual content

Every catalogue field is stored as a per-language JSON value. Today the library ships in Dutch (the source language of the methodology document) with English translations on shipped rows; club-authored entries support both. The library renders in the viewer's locale and falls back NL → EN → empty if a locale-specific translation is missing.
