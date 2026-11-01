---
title: Coachdashboard (frontend)
group: frontend
summary: Wat coaches zien in het tegelraster van de frontend.
audience: [user]
order: 20
---

# Coach-dashboard

Wanneer je inlogt als coach kom je op een tegelraster. Elke tegel opent één onderdeel van de app. Gebruik bovenaan elke pagina de **← Terug**-link om hier terug te keren.

## Indeling op desktop

Op een breed scherm (1024px en breder) splitst de sectie **Werk van vandaag** zich in twee kolommen. De werkgroepen lopen links in een vaste volgorde van boven naar beneden — **Prestaties → Personen → Planning & tactiek → Ontwikkeling → Naslag**, gevolgd door eventuele overige groepen (zoals Taken of Analytics). Je persoonlijke snelkoppelingen staan rechts in een vastgezet **Mijn werk**-paneel: compacte rijen met een icoon en een titel, één per persoonlijke tegel, die in beeld blijven terwijl je de linkerkolom scrolt. Op smallere schermen valt alles terug naar één kolom en zakt het **Mijn werk**-paneel naar onderen. De ingeklapte sectie **Setup & beheer** blijft eronder over de volle breedte staan. Daar staan alleen inrichtingsschermen: een groep met allebei — Proeftrainingen bijvoorbeeld, met de dossierlijst én de trajecten — splitst nu, zodat de dossierlijst in Werk van vandaag blijft en de trajecten naar beneden verhuizen.

## Deze week

Bovenaan **Werk van vandaag** staat het paneel **Deze week**: elke activiteit van de lopende week van de teams die je coacht, op datum. De week begint op de eerste dag van de week die je academie in de Algemene instellingen heeft gekozen (maandag, tenzij daar zondag staat), dezelfde week die de teamplanner toont. Voorbije dagen blijven in de lijst staan, zodat wat nog moet gebeuren op woensdag niet verdwijnt. Coach je meer dan één team, dan staat bij elke rij ook het team. Op een breed scherm (1024px en breder) staat het paneel bovenaan de rechterkolom, boven **Mijn werk**; op een telefoon of tablet komt het als eerste, vóór de tegelgroepen.

Elke rij toont de dag, het type, de activiteit met tijd en plaats, en daaronder wat er nog nodig is:

- **Een training die voorbij is** toont *Aanwezigheid geregistreerd* of *Aanwezigheid niet geregistreerd*. Is die niet geregistreerd, dan opent **Aanwezigheid registreren** meteen de aanwezigheid van die training.
- **Een komende training** toont hoeveel spelers van de selectie verwacht worden (als er een geplande selectie is vastgelegd), en of er een trainingsplan aan hangt: *Trainingsplan klaar* of *Nog geen trainingsplan*. Tik op de rij om de activiteit te openen.
- **De training van vandaag** toont hetzelfde, met de datum van vandaag gemarkeerd en een knop **Aanwezigheid registreren**.
- **Een wedstrijd** heeft een gekleurde rand en toont de tegenstander, thuis of uit, de aanwezigheidstijd, de aftrap en de plaats. Daaronder: hoeveel spelers beschikbaar zijn en hoeveel niet beschikbaar, en hoe ver de opstelling is (*Opstelling 9 van 11*, of *Opstelling compleet*). **Wedstrijdvoorbereiding** opent de voorbereiding; op de wedstrijddag, zodra er een voorbereiding is, heet de knop **Wedstrijd starten**. **Activiteit** opent de activiteit zelf. Een wedstrijd die al op voltooid staat, toont zich als een voorbije dag: of de aanwezigheid is vastgelegd, **Aanwezigheid registreren** als dat nog niet zo is (tenzij de wedstrijd na vandaag valt), en **Activiteit**, zonder voorbereiding, zonder starten en zonder beschikbaarheid of opstelling.

*Niet beschikbaar* is alleen een aantal. Het paneel zegt nooit waarom een speler niet kan spelen.

Een week zonder wedstrijd meldt *Geen wedstrijd deze week.* onder de lijst. Een week zonder activiteiten voor je teams toont in plaats van rijen één regel die dat zegt.

### Spelers die aandacht nodig hebben

Onder de lijst kan een blok wijzen op afzonderlijke spelers van je teams:

- **Niet beschikbaar en terug deze week**: spelers die vandaag door een blessure niet kunnen, als *Morris Adriaanse · Niet beschikbaar*, hetzelfde neutrale woord als op de planningsschermen. De regel gaat over de teams waarvan je de blessures mag inzien en de teams waarvan je de activiteiten mag wijzigen. Mag je de blessures van je team inzien, dan staat er ook wanneer ze terug worden verwacht (*terug ~ 8 nov*), of *komt terug · deze week* als dat in de periode valt. Het tonen van die datum wordt vastgelegd in het auditlog, net als het inzien van het blessure-overzicht. De blessure zelf, het lichaamsdeel en eventuele notities staan hier nooit. Scouts en alleen-lezen waarnemers krijgen deze regel alleen als je academie hem voor hen aanzet.
- **Meerdere trainingen gemist**: spelers die elk van de laatste drie vastgelegde trainingen van hun team hebben gemist. Gemist telt zoals het aanwezigheidsrapport het telt: afwezig, afgemeld, geblesseerd of geschorst. (De aanwezigheidsscore van de spelerstatus laat geblesseerd en geschorst buiten beschouwing; deze regel telt ze, net als het rapport, als gemist.)
- **Proefspelers deze week**: prospects die bij je team op proef zijn, met voornaam en eerste letter van de achternaam en de dagen waarop het team een activiteit heeft (*Thomas K. · di, do*). Alleen wie prospects mag zien krijgt deze regel.
- **Verjaardagen**: spelers die deze week jarig zijn, met voornaam en dag (*Duco · vr 30*). Nooit de leeftijd of het jaar.

Een speler wordt alleen bij naam genoemd als je zijn profiel mag openen, en de naam linkt daarheen. Ieder ander op de regel wordt geteld (*2 spelers niet beschikbaar*), behalve bij Verjaardagen: die laat hen helemaal weg, want een aantal zou verraden dat een kind dat je niet mag zien deze week jarig is. Het blok verdwijnt als er niets te melden is.

Standaard ziet de hoofdtrainer alle vier op een desktop en de regel niet beschikbaar op een telefoon, de assistent-trainer de regel niet beschikbaar, gemiste trainingen en verjaardagen op een desktop, de teammanager verjaardagen, en het hoofd opleiding gemiste trainingen en proefspelers. Je academie kan dat per rol en apparaat aanpassen onder **Spelers die aandacht nodig hebben** in de instellingen van het paneel.

### Het paneel instellen

Je academie kiest wat het paneel toont, per rol en per apparaat, onder **Configuratie → Standaard dashboard → Paneel Deze week**. Kies bovenaan een rol, stel die in, kies de volgende rol, en druk één keer op **Opslaan** voor allemaal. De wijziging is zichtbaar zodra het dashboard opnieuw wordt geopend.

- **Toon het paneel aan deze persona** zet het paneel aan of uit voor die rol. Standaard zien de hoofdtrainer en de assistent-trainer het. Wie meerdere rollen heeft, ziet het paneel zolang die werkt in een rol die het heeft, en niet in een andere rol.
- **Ook op telefoons** uit houdt het paneel bij tablets en desktops (768px en breder). Op een telefoon beginnen de tegels dan bovenaan.
- **Welke week, welke teams**:
  - **Periode** is de kalenderweek, of vandaag en de zes dagen erna.
  - **Teams** is *Eigen teams* (de teams waarmee die gebruiker werkt) of *Alle teams van de academie*. Alle teams van de academie geldt alleen voor wie de activiteiten van elk team mag zien, zoals het hoofd opleiding. Ieder ander ziet nog steeds de eigen teams.
  - **Dagen die al voorbij zijn** kun je tonen, samenvouwen tot één regel, of verbergen. Een samengevouwen regel leest als *di, do · 2 activiteiten · aanwezigheid vastgelegd* en opent met een tik of met Enter.
  - **Teamnaam op elke regel** noemt het team als de week meer dan één team beslaat.
  - **Vooruitblik op volgende week** zet de eerste twee activiteiten van de zeven dagen daarna onder de lijst.
- **Weekoverzicht**:
  - **Weeksamenvatting** zet drie getallen boven de lijst, voor de periode en de teams van het paneel: het aantal trainingen, het aantal wedstrijden, en het aanwezigheidspercentage over de activiteiten waarvan de aanwezigheid is vastgelegd. Aanwezig en te laat tellen allebei als aanwezig, precies zoals in het aanwezigheidsrapport per team, dus die twee tonen hetzelfde getal voor dezelfde datums en teams. Is er nog niets vastgelegd, dan staat er *—*. Standaard staat hij aan voor de hoofdtrainer op een desktop, en voor het hoofd opleiding en de academiebeheerder op allebei; voor elke andere rol zet je hem hier aan.
- **Op te volgen deze week** is een onderdeel onder de lijst, met één regel per punt, elk een link naar de plek waar je het afhandelt. Een regel staat er alleen als er iets openstaat en de gebruiker die pagina mag openen, en het onderdeel verdwijnt als alle regels leeg zijn:
  - **POP-gesprekken te voeren**: de POP-gesprekken die nog gevoerd moeten worden in een POP-blok dat deze week sluit, voor de teams van het paneel, met de dag waarop het sluit (*4 spelers · sluit zo*). POP-planning toont dit totaal niet zelf: het wordt uit die pagina afgeleid, als de selectie van elk blok dat sluit, min de gevoerde gesprekken en de gesprekken die als niet gehouden zijn gemarkeerd. Het opent POP-planning.
  - **Evaluaties te doen**: de spelers die in de evaluatieronde die nu loopt nog niet zijn geëvalueerd: de gaten die Evaluatie-dekking in de kolom van die ronde toont, geteld voor de teams van het paneel (de pagina toont dit totaal niet zelf). Het opent Evaluatie-dekking, en staat er dus alleen voor wie dat rapport mag openen (het beslaat de hele academie) en als de functie Evaluatie-dekking aan staat.
  - **Mijn taken deze week**: de eigen openstaande taken van de gebruiker met een einddatum in de periode (*2 te doen*). Het opent Mijn taken.
  - Standaard ziet de hoofdtrainer alle drie op een desktop en de POP-regel op een telefoon; de teammanager en de academiebeheerder zien hun taken op allebei; het hoofd opleiding ziet de POP- en evaluatieregel op een desktop.
- De andere onderdelen zetten elk deel van een regel op een rij (**Op elke regel**, **Trainingen**, **Wedstrijden**) en elke knop (**Knoppen op een regel**). Elk heeft een vakje **Desktop** en een vakje **Telefoon**, zodat een telefoon een korter paneel kan tonen dan een desktop. Er staan hooguit twee knoppen op een regel, en de eerste die van toepassing is, is de hoofdknop.
- **Reset naar standaarden** zet de rol die je bekijkt terug op de begininstellingen. Druk op **Opslaan** om dat te bewaren.

Het paneel beslaat alleen teams die de gebruiker mag zien, en een knop verschijnt alleen op een regel van een team dat de gebruiker mag wijzigen. Een gebruiker in wiens week geen team zit, ziet het paneel nooit, welke rol die ook heeft. Scouts en waarnemers krijgen de aantallen beschikbare spelers alleen als je dat blok voor hen aanzet.

Tot de instellingen voor het eerst worden opgeslagen, houdt elke rol die het paneel al zag het hele paneel op een desktop, teammanagers inbegrepen. Op een telefoon laat het de locatie en het trainingsplan weg, en ziet een teammanager daar tijden, locatie, verwachte spelers en de tegenstander. Een teammanager die pas na het opslaan wordt aangezet, begint op beide apparaten met die kortere set.

## Tegels die je het meeste gebruikt

### Teams
De teams die je coacht. Bij elk team zie je het top-3 podium en de volledige selectie. Tik op een spelerskaart voor de detailpagina.

### Mijn spelers
Spelers van de teams die jij coacht. Beheerders zien deze tegel als **Spelers** met de volledige spelerslijst van de academie. Tik op een kaart voor het spelerdetail. Op de lijstpagina staat ook een knop **Importeer uit CSV**.

### Evaluaties
Formulier om een evaluatie vast te leggen. Kies een speler, een type, een datum, en geef per categorie een score. Opslaan en klaar.

### Activiteiten
Leg een training, wedstrijd of ander evenement vast. Kies het team, de datum, eventueel locatie en notities, en streep de aanwezigheid per speler aan.

### Doelen
Voeg ontwikkelingsdoelen toe voor een speler en volg de voortgang.

## Planning & tactiek

De vooruitkijkende planning- en tactiekhulpmiddelen staan in hun eigen groep **Planning & tactiek**, direct na Prestatie — gescheiden van het dagelijkse werk Evaluaties / Activiteiten / Doelen:

- **Teamplanner** — plan de trainingsweek, principe voor principe.
- **VCT-sessieontwerper** — bouw een training uit de oefeningenbibliotheek.
- **Teamblauwdruk** — sleep opstellingen met live chemie.
- **Teamchemie** — formatiebord met een voorgestelde basiself.

## Analytics

### Podium
De top-3 van elk team dat je coacht, op één scherm.

## Naslag

In de groep **Naslag** staat materiaal dat je leest maar niet invult.

### Methodologie
De principes, formaties, posities en standaardsituaties van je academie.

## Op een telefoon

Op een telefoon stapelen de tegels in één kolom, op een tablet in twee. Lange tabellen scrollen zijwaarts in plaats van te worden samengeperst.
