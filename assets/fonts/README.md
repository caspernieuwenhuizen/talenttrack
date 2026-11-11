# Bundled webfonts

The visual themes and the player card load their fonts from here, never from
a third-party font host. Each theme sheet in `assets/css/theme-*.css` declares
the `@font-face` rules for its own families, with `font-display: swap`, so a
font is fetched only while its theme is active.

Every file is the Latin subset, upright, in the weights a theme actually uses.
The woff2 files come from the Fontsource packages, which repackage the Google
Fonts builds; each family's `OFL.txt` is the licence from the
[google/fonts](https://github.com/google/fonts) repository.

| Family | Folder | Weights | Version | Source | Licence | Used by |
| --- | --- | --- | --- | --- | --- | --- |
| Barlow Condensed | `barlow-condensed/` | 600, 700, 800 | Google Fonts v13 (Fontsource 5.3.0) | https://github.com/jpt/barlow | SIL OFL 1.1 | `clubrood`, `federation` |
| Archivo | `archivo/` | 400, 500, 600, 700, 800 | Google Fonts v25 (Fontsource 5.3.0) | https://github.com/Omnibus-Type/Archivo | SIL OFL 1.1 | `oranje` |
| IBM Plex Sans | `ibm-plex-sans/` | 400, 500, 600, 700 | Google Fonts v23 (Fontsource 5.3.0) | https://github.com/IBM/plex | SIL OFL 1.1 | `tactiekbord` |
| Source Serif 4 | `source-serif-4/` | 600, 700 | Google Fonts v15 (Fontsource 5.3.1) | https://github.com/adobe-fonts/source-serif | SIL OFL 1.1 | `bordeaux` |
| Source Sans 3 | `source-sans-3/` | 400, 500, 600, 700 | Google Fonts v20 (Fontsource 5.3.1) | https://github.com/adobe-fonts/source-sans | SIL OFL 1.1 | `bordeaux` |
| DM Sans | `dm-sans/` | 400, 500, 700, 800 | Google Fonts v17 (Fontsource 5.3.0) | https://github.com/googlefonts/dm-fonts | SIL OFL 1.1 | `polder` |
| Oswald | `oswald/` | 500, 600, 700 | Google Fonts v57 (Fontsource 5.3.0) | https://github.com/googlefonts/OswaldFont | SIL OFL 1.1 | `player-card.css` |
| Manrope | `manrope/` | 400, 500, 600, 700, 800 | Google Fonts v20 (Fontsource 5.3.0) | https://github.com/googlefonts/manrope | SIL OFL 1.1 | `player-card.css` (500-700) |

## Payload

| Family | Files | Bytes |
| --- | --- | --- |
| Barlow Condensed | 3 | 67,216 |
| Archivo | 5 | 72,044 |
| IBM Plex Sans | 4 | 93,856 |
| Source Serif 4 | 2 | 43,248 |
| Source Sans 3 | 4 | 62,612 |
| DM Sans | 4 | 57,256 |
| Oswald | 3 | 38,116 |
| Manrope | 5 | 70,184 |
| **Total** | **30** | **504,532** |

A browser downloads only the faces the rendered page uses, so a page pays for
the weights it draws, not for the whole family.

## Adding a font

Only SIL OFL (or similarly permissive) families. Add the Latin woff2 for the
weights the theme uses, the family's `OFL.txt` next to them, a row in both
tables above, and the `@font-face` rules at the top of the theme's own sheet.
