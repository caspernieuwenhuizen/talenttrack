# TalentTrack pitch materials

This folder holds the talking-to-clubs collateral.

- `pitch-deck.md` — ~14 slides, reveal.js / pandoc ready
- `onepager.md` — A4 leave-behind, ~400 words

## Render the deck

### Pandoc to PowerPoint (most-used path)

```sh
pandoc pitch-deck.md -o talenttrack-pitch.pptx
```

### Pandoc to standalone HTML (reveal.js)

```sh
pandoc pitch-deck.md -t revealjs -s -o talenttrack-pitch.html \
  -V revealjs-url=https://unpkg.com/reveal.js@5/dist/ \
  -V theme=league
```

Open `talenttrack-pitch.html` in any browser. Press `S` for speaker notes, `F` for fullscreen.

### reveal-md (alternative, live-reload while editing)

```sh
npx reveal-md pitch-deck.md --theme league
```

### Pandoc to PDF (handout)

```sh
pandoc pitch-deck.md -o talenttrack-pitch.pdf \
  --pdf-engine=xelatex -V geometry:margin=1in
```

## Render the one-pager

```sh
pandoc onepager.md -o talenttrack-onepager.pdf \
  --pdf-engine=xelatex -V geometry:margin=1.5cm \
  -V mainfont="Helvetica" -V fontsize=11pt
```

Or print straight from a markdown preview to save-as-PDF (A4 portrait, 1.5cm margins).

## Editorial notes

- Pricing placeholders use `{{pricing_tbd}}` — search-and-replace before sending.
- Dutch translation is a follow-up — copy is currently English, deliberately so the pitch can be tuned per audience before localising.
- The deck deliberately under-promises on roadmap dates. Direction yes; commitments no.
