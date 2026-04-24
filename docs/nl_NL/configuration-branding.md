# Configuratie & branding

Op de pagina **Configuratie** leven de identiteit van je academie en de operationele knoppen van de plugin.

## Tabbladen

### Algemeen

- **Naam academie** — gebruikt door de hele plugin heen en in printbare rapporten
- **Logo-URL** — getoond in de header van het frontend-dashboard en de printuitvoer
- **Primaire kleur** — tegelaccenten, grafieklijnen, kerncijfers
- **Maximum beoordelingsschaal** — standaard 5; je kunt overschakelen naar 10 als je coaches liever een 1–10-schaal gebruiken

### Lookups

Elk lookup-tabblad (Positie, Leeftijdscategorie, Voet-optie, Doelstatus, Doelprioriteit, Aanwezigheidsstatus) is een eenvoudige lijst die je kunt bewerken, slepen om te herordenen en uitbreiden met nieuwe items.

**Vertalingen** — elk bewerkformulier van een lookup heeft een blok Vertalingen met één rij per geïnstalleerde sitetaal. Vul de vertaalde Naam (en optioneel de omschrijving) in om te bepalen wat je Nederlandse gebruikers zien, zonder een plug-in-update uit te brengen. Laat een regel leeg om terug te vallen op de canonieke Naam en een eventuele vertaling die de plug-in in zijn `.po`-bestand meelevert. Eigen toevoegingen (bijv. een aangepaste positie "Keeper-Libero") kun je nu vertalen zonder de code aan te passen.

### Evaluatietypes

Verschillende smaken evaluatie: Training, Wedstrijd, Toernooi. Wedstrijdtypes kunnen gemarkeerd worden als "Vereist wedstrijddetails", waarna coaches tegenstander, competitie, uitslag, thuis/uit en gespeelde minuten moeten invullen bij het maken van een wedstrijdevaluatie.

### Toggles

Feature-toggles voor zaken als de printmodule, bepaalde frontend-secties, audit trail. Zet ze aan/uit naar behoefte.

### Audit

Een alleen-lezen logboek van configuratiewijzigingen voor verantwoording.

## Slepen om te herordenen

Lookup-lijsten ondersteunen slepen-om-te-herordenen (v2.19.0). Pak de greep ⋮⋮ op een rij en sleep. De volgorde wordt automatisch opgeslagen en meteen overal in de plugin in de dropdowns verwerkt.
