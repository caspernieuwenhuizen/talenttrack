<!-- audience: user, admin -->

# Methodology

The Methodology library is where your academy's coaching framework lives in TalentTrack: the framework primer, principles, formations and position cards, set pieces, the vision and the football actions catalogue. Coaches use it during session planning and player conversations.

## How to find it

Open the **Methodology** tile in the **Reference** group of the dashboard.

## The tabs

The tabs lead with **Vision** — the club's playing vision opens by default, so you see where the academy is going before the framework reference.

1. **Vision** — the club's umbrella record: chosen formation, style of play, way of playing and important player traits.
2. **Framework** — your academy's primer: introduction, the football model, football actions, the four phases of attacking and defending, learning goals, factors of influence, and reflection. Each section can carry illustrations. The sections are **collapsible** — the first opens automatically and the rest are tucked away; expand the ones you need. Your open/closed choices are remembered the next time you return.
3. **Formations & positions** — the formation visual plus a card for each shirt number. Position cards list attacking and defending tasks and an optional diagram.
4. **Principles** — coded principles like AO-01 (build-up) or VS-02 (disrupting). Each one has an explanation, team-level guidance, per-line guidance for forwards / midfielders / defenders / goalkeeper, a formation diagram and a primary illustration.
5. **Football actions** — the catalogue of football actions (receiving, passing, dribbling, shooting, heading, plus running free, marking, pressing, and supporting actions like game insight and communication).
6. **Set pieces** — corners, free kicks, penalties and throw-ins, illustrated, with attacking and defending variants.

When the VCT module is switched on, a seventh tab appears — **Periodisation** (see below).

## Periodisation

The **Periodisation** tab combines the methodology with the VCT conditioning cycle. It reads the club-default macro-block calendar for the current season and shows, per week, three things side by side:

- the **speelwijze theme** — what to work on tactically that week (build-up, defending, possession, and so on), drawn from the same theme vocabulary the VCT exercise library uses;
- the **conditioning phase** — the VCT intensity phase for that week (introduction, build, peak, deload, …);
- the **intensity multiplier** — how hard the week is relative to a normal week.

The tab is read-only. The weekly cycle itself — the macro-blocks and, per week, the theme — is authored on the VCT configuration tile (**Configuration → VCT → Macro-blocks**). If no club-default cycle exists yet for the current season, the tab shows a short prompt and (for admins) a link to set it up. The tab only appears while the VCT module is enabled; with VCT off there is no cycle to show.

## Two kinds of content

- **Shipped** content is curated by TalentTrack and read-only by default — you can't accidentally break it.
- **Club-authored** content is added by your admins and lives alongside the shipped content.

To start from a shipped entry without touching the original, click **Clone & edit** — you get a copy of your own to shape, and the shipped entry stays unchanged.

## How it links to the rest of TalentTrack

- **Goals** — a goal can link to one principle and one football action, to make the development target concrete.
- **Activities** — an activity can list the principles being practised, so coaches can see at a glance which principles their week covers.

## Diagrams and images

Every entry can carry diagrams and images. Each entry's edit page has a section for adding pictures — pick from the media library or upload new ones. The first image becomes the hero image; you can add captions and switch which image is primary.

## Selectable methodologies

TalentTrack now ships **two** methodologies. Alongside the default **JO14-1 Hedel** (1-4-2-3-1), a second shipped methodology **JO13-1 Hedel** (1-4-3-3) is available. It carries its own vision, formation and position cards, principles, framework primer, phases and learning goals. A team can be pointed at whichever set fits its playing style; the Methodology tabs then show that set's content. Like all shipped content it is read-only — clone an entry to edit your own copy.

## Tactical scenes (Speelwijze animations)

The **Speelwijze** tab shows animated per-phase tactical scenes for the active methodology set. Each scene is a game-phase snapshot — an SVG pitch with your players (filled circles), the opponents (outlined circles) and the ball — that animates the movement for that phase when you press **Play**.

- Scenes are grouped by phase side: **Aanvallend**, **Verdedigend** and **Omschakelen**.
- Every scene carries **coaching points** beside the pitch — the key sub-principes for that phase.
- **Play / Pause / Restart** controls sit under each pitch. Nothing moves until you press Play, so the page never surprises you with motion.
- If your device asks for reduced motion, scenes do not autoplay: the final frame is shown statically and you can still press Play to watch it.

Movement is drawn with coloured arrows: a run, a pass, a press, or a dribble. The player and ball positions use the same pitch layout as the formation diagrams, so the two read consistently.

Scenes are shipped content for the JO13-1 Hedel set today and are read-only in the app. They can be created and edited over the REST API (see the authoring guide); a drag-and-draw scene editor is a planned follow-up.
