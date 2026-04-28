<!-- audience: user -->

# Spelersreis

Iedere speler heeft een verhaal bij de academie: het moment waarop hij of zij binnenkwam, de stage die toegang gaf, de evaluaties onderweg, het team waarin de speler zit, de blessure waarvan hij of zij terugkwam, en wat er hierna komt. De **spelersreis** brengt dat verhaal op één plek samen.

## Wat je ziet

Open **Mijn reis** (spelers) of open een speler en kies **Reis** (coaches en hoofd opleidingen) om een chronologische lijst te zien van alles wat er met die speler is gebeurd.

Iedere regel toont:
- Een korte titel — *Evaluatie op 12 maart*, *Naar U15*, *Blessure: enkel*.
- Een gekleurde label voor het type — mijlpalen in groen/oranje, waarschuwingen in rood, info in grijs.
- De datum.

De nieuwste regels staan standaard bovenaan. Gebruik de datum- en typefilters om in te zoomen — bijvoorbeeld alleen gebeurtenissen van dit seizoen, of alleen mijlpalen.

## Twee weergaves

- **Tijdlijn** — alle gebeurtenissen, nieuwste eerst. Hier zie je het hele verhaal.
- **Mijlpalen** — alleen de grote momenten (binnengekomen, vastgelegd, doorgestroomd, afscheid genomen, doorgestroomd, POP-eindbeoordeling). Handig voor oudergesprekken en wanneer een nieuwe coach een team overneemt.

## Wat automatisch op de reis verschijnt

De meeste regels komen er zonder dat iemand ze invoert. De reis kijkt mee met de rest van het systeem en verandert belangrijke acties in regels:

- Een **nieuwe evaluatie** op een speler → *"Evaluatie op 12 maart"*.
- Een **doel** voor een speler → *"Doel gesteld: zwakke voet trainen"*.
- Een **POP-eindbeoordeling getekend** → *"POP-eindbeoordeling: doorstroom"*.
- Een **speler komt in een team of gaat naar een andere leeftijdscategorie** → *"Team: U13 → U14"* of *"Leeftijd: U13 → U14"*.
- Een **stagecasus** wordt geopend → *"Stage gestart"*.
- Een **stagebeslissing** → *"Stage afgerond: aangenomen"* (en *"Vastgelegd"* bij aanname, *"Afscheid genomen"* bij definitief nee).
- De **status** van een speler wijzigt naar actief, afscheid of doorgestroomd → bijbehorende regels.

Alles is **idempotent**: een evaluatie opnieuw opslaan dupliceert de regel niet. Gebeurtenissen leven naast hun bron — de oorspronkelijke evaluatie, het doel of het POP-bestand blijft de plek om te bewerken; de reis is alleen de overzichtsweergave.

## Blessures

Blessures hebben hun eigen scherm bij de speler. Open de speler → **Blessures** om een nieuwe vast te leggen met:
- Lichaamsdeel (enkel, knie, hamstring, ...)
- Ernst (licht, matig, ernstig, seizoensbeperkend)
- Begindatum
- Verwachte terugkeer
- Notities

Wanneer je een blessure vastlegt, gebeuren er twee dingen:
1. Er komt een *Blessure ingetreden*-regel op de spelersreis (standaard alleen zichtbaar voor medische rol / hoofd opleidingen — zie Privacy hieronder).
2. Er wordt een herinneringstaak gepland voor de hoofdcoach van de speler om te bevestigen dat de speler op schema ligt of de verwachte terugkeer bij te werken.

Wanneer de speler terugkomt, vul je **Werkelijke terugkeer** in en verschijnt er een *Blessure hersteld*-regel op de reis.

## Privacy

Niet alle regels zijn voor iedereen zichtbaar. Elke regel heeft een **zichtbaarheidsniveau**:

- **Publiek** — iedereen met toegang tot de speler ziet het. De meeste regels staan hierop.
- **Coachingstaf** — alleen coaches en beheerders. Gebruikt voor zaken als *Afscheid genomen*.
- **Medisch** — alleen rollen met de medische-zicht-permissie. Blessures staan standaard hierop.
- **Veiligheid** — alleen hoofd opleidingen + beheerder. Gereserveerd voor gevoelige regels.

Als de reis een regel bevat die je niet mag zien, lees je *"1 regel verborgen — alleen zichtbaar voor andere rollen."* bovenaan, zodat de chronologie eerlijk blijft. Het detail blijft buiten beeld.

## Cohort-overgangen (hoofd opleidingen)

Wil je weten wie er dit jaar naar U15 is doorgestroomd? Of welke spelers vorig seizoen langdurig geblesseerd waren? Open **Cohort-overgangen** onder Analytics:

1. Kies een gebeurtenistype (bijv. *Naar volgende leeftijdscategorie*).
2. Kies een datumbereik.
3. Beperk eventueel tot één team.
4. Klik **Zoekopdracht uitvoeren**.

Het resultaat toont elke matchende speler + datum + samenvatting. Klik **Reis openen** op een rij om in het volledige verhaal van die speler te duiken.

## Een fout corrigeren

Klopt een regel niet (een evaluatie op de verkeerde speler, een dubbele stagebeslissing), verwijder hem dan niet. Open de bron — de evaluatie, het doel of de stagebeslissing — en corrigeer die. De reis bewaart het auditspoor; de gecorrigeerde regel vervangt de oorspronkelijke standaard op de tijdlijn. Zet **Toon ingetrokken** aan om de oorspronkelijke regels weer te zien.

## Zie ook

- [Evaluaties](evaluations.md) — de bron van *Evaluatie ingevoerd*-regels.
- [Doelen](goals.md) — de bron van *Doel gesteld*-regels.
- [Persoonlijk Ontwikkelingsplan (POP)](pdp-cycle.md) — de bron van *POP-eindbeoordeling vastgelegd*.
- [Stagecasussen](trials.md) — de bron van *Stage gestart* en *Stage afgerond*.
