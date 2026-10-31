---
title: Minuten + statistieken
group: match-day
summary: "Leg per wedstrijd minuten, doelpunten en assists per speler vast, in één raster."
audience: [user]
views: [minutes-grid]
module: TT\Modules\Activities\ActivitiesModule
feature: minutes_grid
order: 30
---

# Minuten + statistieken

**Minuten + statistieken** legt vast hoeveel minuten elke speler per wedstrijd
kreeg én wat hij opleverde, over een hele periode — de desktop-tegenhanger in
spreadsheetvorm van het [aanwezigheidsraster](attendance-grid.md), beperkt tot
wedstrijden.

Open het via de schakelaar **Aanwezigheid / Minuten + stats** boven het
raster, of rechtstreeks vanuit **Activiteiten** (je hebt rechten nodig om
activiteiten te bewerken). Net als het aanwezigheidsraster is het gemaakt voor
een desktop of laptop.

## Wat je ziet

- **Rijen zijn je spelers** — de actieve selectie van het gekozen team.
- **Kolommen zijn wedstrijden** — de wedstrijden van het team in de gekozen
 periode, oudste links, elk opgesplitst in drie velden: **Min**, **G** en
 **A**.
- **Elke bewerkbare cel is een veld om in te typen.** Minuten, doelpunten,
 assists. Met Tab loop je Min → G → A → volgende wedstrijd, zoals in een
 spreadsheet. De **Totaal**-kolommen rechts tellen minuten, doelpunten en
 assists van elke speler over de getoonde periode op.
- **Gearceerde cellen** betekenen dat de speler niet in de selectie van die
 wedstrijd zat, dus er is niets vast te leggen. De selectie is hier de
 aanwezigheid die je hebt **vastgelegd**, niet de selectie die je vooraf
 had gepland — een speler die je wel had opgesteld maar nooit aanwezig hebt
 gemeld, blijft gearceerd. Leg eerst zijn aanwezigheid vast als dat niet
 klopt.
- **"Aanwezigheid nog niet vastgelegd"** over een hele kolom betekent dat voor
 die wedstrijd van niemand de aanwezigheid is vastgelegd, dus er zit nog
 niemand in de selectie, ook als je in de wedstrijdvoorbereiding een
 opstelling hebt opgeslagen. Tik in die kolom op **Aanwezigheid registreren**
 om de presentielijst in te vullen en kom daarna terug om de minuten in te
 voeren. Een toernooiwedstrijd krijgt zijn presentielijst als je hem afsluit
 in de toernooiplanner, dus die kolom heeft geen knop.
- Een **"live"**-label op een kolom betekent dat die minuten uit een via het
 wedstrijdformulier gespeelde wedstrijd komen. Je mag hier toch een waarde
 corrigeren — je invoer wordt bewaard als correctie die een herberekening
 overleeft.

## Minuten, doelpunten en assists vastleggen

1. Kies het **team** en de **periode** (een snelknop of een eigen datumbereik).
2. Vul de velden in elke selectiecel. Laat een veld leeg om het te wissen.
3. Klik op **Opslaan**. De teller laat zien hoeveel wijzigingen klaarstaan;
 bewerkte velden krijgen een rand tot je opslaat. Met **Annuleren** verlaat
 je het scherm zonder op te slaan.

De minuten die je hier invoert zijn dezelfde cijfers die de minuten-audit en
het rapport Minuten per team gebruiken, zodat alles blijft kloppen.

### Doelpunten en assists zonder live wedstrijdformulier

Tot nu toe konden doelpunten en assists alleen worden vastgelegd door een
wedstrijd via het **live wedstrijdformulier** te spelen. Een club die de
administratie op zondagavond doet, had daardoor spelers met complete minuten
en permanent lege doelpunten — op hun profiel, in de rapportages, overal.

Je kunt ze nu gewoon intypen. Wat je vastlegt telt precies zo mee als een live
gelogd doelpunt: het bereikt het spelersdossier en elk rapport dat daarop
gebouwd is.

Eén ding wordt bewust **niet** vastgelegd als je een doelpunt achteraf intypt:

- **Geen minuut.** Een live gelogd doelpunt weet dat het in de 34e minuut
 viel; een doelpunt dat je je op zondag herinnert niet, en het raster
 verzint er geen. Zo'n doelpunt verschijnt in de totalen van de speler, maar
 niet met een klok erbij op de wedstrijdtijdlijn.

**Een naam intypen herschrijft nog steeds nooit de uitslag.** De uitslag is wat
er gebeurd is; wie er scoorde is wat we daarvan weten. Je legt ze los van
elkaar vast — de uitslag in een eigen rij boven het raster, hieronder
beschreven.

## De uitslag vastleggen

Boven de spelersrijen heeft elke wedstrijdkolom twee vakjes: **jullie
doelpunten** en die van **de tegenstander**. Vul ze in dezelfde ronde als de
minuten en druk op dezelfde knop Opslaan.

Dit is het getal waar de rij **Toegekend / uitslag** onderaan altijd al tegen
afzette. Tot nu toe stelde het raster die vraag zonder je ergens een antwoord
te laten geven, dus elke wedstrijd die niet live was bijgehouden toonde alleen
een kaal aantal.

Drie dingen gedragen zich zoals elders in het product:

- **Leeg is geen 0–0.** Een vakje leegmaken legt *geen uitslag* vast, geen
 doelpuntloos gelijkspel. Een wedstrijd zonder uitslag telt als gespeeld en
 blijft buiten de balans van gewonnen/gelijk/verloren, en juist dat houdt de
 balans van het team eerlijk.
- **Een wedstrijd die live is bijgehouden toont zijn uitslag als tekst, niet
 als vakje.** Die uitslag volgt het doelpuntenlogboek van het wedstrijdblad,
 dus wijzigen betekent een doelpunt corrigeren in de nabespreking. De minuten
 in diezelfde kolom blijven **wel** bewerkbaar — een minuutcorrectie overleeft
 een hertelling, een uitslagcorrectie niet.
- **Een toernooi heeft geen uitslagvakjes.** Een toernooidag zijn meerdere
 wedstrijden en één uitslag kan dat niet beschrijven, dus die kolommen tonen
 een streepje.

Dezelfde uitslag staat op de eigen pagina van de wedstrijd en wordt via
dezelfde weg weggeschreven — zie
[De uitslag vastleggen](activities.md#de-uitslag-vastleggen). Twee ingangen,
één getal.

**Een assist hangt aan een doelpunt.** Er een vastleggen telt geen extra
doelpunt bij het team — het benoemt wie een doelpunt voorbereidde dat er al
is. Is er geen doelpunt vrij om hem aan te hangen, dan legt het raster een
doelpunt **zonder maker** vast, de eerlijke versie van "iemand rondde zijn
pass af en ik weet niet meer wie".

**Een aantal naar beneden bijstellen** wist nooit historie: het doelpunt wordt
als teruggedraaid gemarkeerd in plaats van verwijderd, en getypte invoer wordt
eerder teruggedraaid dan live vastgelegde. Zo kan een correctie nooit iets
vernietigen dat destijds echt gezien is.

Raakt een correctie tóch een live vastgelegd doelpunt — omdat er geen getypte
meer over waren om eerst terug te draaien — dan **volgt de stand op het
wedstrijdformulier mee naar beneden.** Die stand is een aflezing van het
doelpuntenlogboek: een doelpunt dat uit het logboek gaat, gaat ook van het
scorebord. De twee kunnen dus niet over dezelfde wedstrijd van mening
verschillen.

### Hoeveel doelpunten en assists een wedstrijd toelaat

**De uitslag is de grens.** Heeft een wedstrijd een uitslag, dan kan geen speler
meer doelpunten of meer assists hebben dan jullie team scoorde, en samen kunnen
de doelpunten van de spelers, en hun assists, ook niet boven die uitslag
uitkomen. Een wedstrijd die eindigde in 2-1 laat in totaal hoogstens 2
doelpunten en 2 assists toe. Heeft een wedstrijd nog geen uitslag, dan kan een
speler hoogstens 15 doelpunten en 15 assists hebben.

Een veld boven de grens krijgt een rode rand en zegt hoeveel het hoogstens kan
bevatten, en Opslaan wordt voor die wedstrijd geweigerd met een melding die de
wedstrijd en de grens noemt. Niets van de doelpunten en assists van die
wedstrijd wordt opgeslagen tot de getallen passen; pas het veld aan, of leg eerst
de uitslag vast. Typ je de uitslag en de doelpunten in één keer, dan wordt de
uitslag eerst opgeslagen, zodat de nieuwe grens telt.

**Te hoge getallen corrigeren.** Voordat deze grens bestond, werden soms per
ongeluk minuten in de kolom **A** getypt, waardoor een speler 70 assists in één
wedstrijd kreeg. Open het raster voor die periode en zet het veld **A** op het
echte aantal, of maak het leeg. De extra assists verdwijnen, samen met de
doelpunten "zonder maker" die werden aangemaakt om ze te dragen, en de totalen
van de speler in de rapporten dalen mee. Eén speler omlaag zetten mag altijd,
ook als andere spelers in dezelfde wedstrijd nog te hoog staan.

### Minuten tegenover de wedstrijd

De rij **Minuten** onderaan het raster controleert per wedstrijd of alle
minuten van de wedstrijd erin staan. Het is dezelfde controle die het overzicht
minuten-audit doet, met dezelfde woorden en kleuren:

- **Het getal** is het aantal ingevoerde minuten tegenover wat de wedstrijd
 bevat: het aantal spelers per team maal de wedstrijdduur. `795 / 770` bij een
 wedstrijd 11 tegen 11 van 70 minuten betekent 25 minuten te veel, meestal een
 wissel die dubbel is geteld.
- **Het label** zegt **Volledig** als elke speler uit de selectie minuten heeft
 en het totaal optelt tot een hele wedstrijd, **Onvolledig** als een van beide
 controles niet klopt, en **Niet geregistreerd** als er nog helemaal geen
 minuten zijn ingevoerd.
- **De reden** onder een label Onvolledig zegt welke controle niet klopt:
 *Spelers uit de selectie zonder minuten*, *45 minuten te kort voor een hele
 wedstrijd* of *25 minuten meer dan de wedstrijd bevat*.

De rij rekent mee terwijl je typt, zodat je het antwoord ziet voordat je op
Opslaan drukt, en na het opslaan geeft hij hetzelfde antwoord als de audit. De
selectie die hij telt is die van de audit: een speler die je hebt geselecteerd
maar nooit aanwezig hebt gemeld, heeft hier geen veld, maar telt wel als speler
zonder minuten tot je zijn aanwezigheid vastlegt. Een speler die je afwezig
hebt gemeld telt niet mee, tot je hem minuten geeft: vanaf dat moment hoort hij
bij de selectie, net als in de audit.

Het is informatie, geen regel. Niets houdt je tegen om op te slaan: een
wedstrijd kan echt korter uitvallen, na een rode kaart of een blessure zonder
wissel. Een toernooidag toont de minuten van zijn wedstrijden bij elkaar
opgeteld, net als de audit, zonder streefgetal en met het label
**Samenvatting**, omdat zijn minuten bij zijn wedstrijden in de toernooiplanner
horen.

### Toegekend / stand

De onderste rij van het raster laat per wedstrijd zien hoeveel doelpunten een
maker op naam hebben tegenover de vastgelegde stand — `2/3` betekent dat één
doelpunt in die wedstrijd nog van niemand is.

Het is informatie, geen regel: minder makers dan doelpunten houdt je nooit
tegen om op te slaan, en is vaak gewoon waar. Meer makers dan doelpunten is het
enige dat het raster weigert, zie de grens hierboven. Het staat er zodat de uitslag en het
doelpuntenlogboek niet ongemerkt uit elkaar kunnen lopen.

### Minder kolommen tonen

Drie velden per wedstrijd is veel raster. Met de schakelaars **Tonen** boven
de tabel zet je de kolommen Doelpunten en Assists uit, waarmee het terugvalt
op een gewoon minutenraster. Die keuze is alleen van jou en wordt onthouden
als je de pagina de volgende keer opent.

## Uitschakelen

Een beheerder kan het raster verbergen via **Instellingen → Functies →
Minuten + statistieken**. Als het uit staat, verdwijnt de rasterknop en kan de
pagina niet worden geopend. De per-wedstrijd minuten-editor blijft gewoon
werken, en al vastgelegde doelpunten blijven op de spelersdossiers staan.
