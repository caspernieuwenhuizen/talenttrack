<!-- audience: admin -->

# Cohortbeslissingsbord

Het **Cohortbeslissingsbord** is één alleen-lezen scherm voor
eindeseizoensbeslissingen. Kies een team of leeftijdsgroep en je krijgt één rij
per actieve speler, met alles wat je nodig hebt om een behouden / bevorderen /
afscheid-nemen-beslissing naast elkaar te zien. Het staat onder **Analyse** en
is beschikbaar voor iedereen met de analyse-rechten; coaches zien alleen de
teams die ze coachen, terwijl academiebrede rollen (Hoofd Ontwikkeling,
beheerders) elk team zien.

## Welke spelervraag beantwoordt dit?

*Waar gaat deze speler volgend seizoen naartoe?* Het bord haalt de recente vorm,
aanwezigheid en ontwikkelgesprekken van elke speler op één plek bij elkaar,
zodat het Hoofd Ontwikkeling kan beslissen wie blijft, doorstroomt of vertrekt —
zonder een dozijn profielen te openen.

## Wat staat er in elke rij

- **Speler** — linkt naar het profiel van de speler.
- **Status** — de huidige status van de speler, getoond als een gekleurde stip
  plus het label.
- **Beoordeling** — het voortschrijdend gemiddelde van de algehele beoordeling
  uit de evaluaties van de speler. Een streepje betekent dat er nog geen
  beoordeelde evaluaties zijn.
- **Trend** — een pijl die de recente beoordelingen van de speler vergelijkt met
  de eerdere: omhoog, omlaag of stabiel. Spelers met weinig beoordelingshistorie
  tonen een stabiele pijl.
- **Aanwezigheid** — het aanwezigheidspercentage van de speler over het huidige
  seizoen. Onder 70% wordt gemarkeerd. Een **(laag)**-markering betekent dat het
  cijfer op slechts enkele activiteiten is gebaseerd, dus wees voorzichtig.
- **POP-gesprekken** — hoeveel ontwikkelgesprekken er daadwerkelijk met de
  speler zijn gevoerd.
- **Eindoordeel** — het huidige eindoordeel in het POP-dossier van de speler, of
  **In behandeling** als er nog geen is ingesteld.
- **POP-dossier** — een directe link naar het POP-dossier van de speler (of om
  er een te starten als er dit seizoen nog geen is).

## Bewust alleen-lezen

Dit bord stelt nooit een eindoordeel in. Eindoordelen blijven waar ze horen — in
het POP-dossier van elke speler. Het bord is een lens om de beslissing te maken;
je legt de beslissing vast in het POP-dossier zelf, en die verschijnt hier.

## Sorteren en exporteren

Elke kolomkop is een sorteerknop: klik één keer om oplopend te sorteren, klik
nogmaals om naar aflopend te wisselen. De huidige sortering werkt zonder
JavaScript, dus het is betrouwbaar op elk apparaat.

De knop **CSV exporteren** downloadt het bord van het huidige team als
spreadsheet — speler, status, beoordeling, trend, aanwezigheid, POP-gesprekken
en eindoordeel — om te delen of te archiveren naast de seizoensevaluatie.

## Wat je eerst nodig hebt

Er moet een huidig seizoen zijn geconfigureerd (onder **Configuratie →
Seizoenen**) om het bord te laten werken, omdat aanwezigheid en POP-eindoordelen
aan het huidige seizoen zijn gekoppeld.

## Uitschakelen

Het cohortbeslissingsbord is een functie per tegel, die **standaard uit**
staat. Een academiebeheerder zet hem aan (of uit) onder **Modules → Analyse →
Cohortbeslissingsbord**. Zolang hij uit staat is de tegel verborgen en geeft
de URL `?tt_view=cohort-board` de gebruikelijke melding "niet beschikbaar".
Het centrale Analyse-scherm en de analyse-engine blijven werken.
