# JEL Agenda: projectnotitie

De agenda van het Jan van Egmond Lyceum op de website, uit de Outlook-agenda "JEL agenda website".
De beheerder werkt in het Nederlands en wil dat alles in beheer van school blijft. Zie ook `README.md`.

## Hoe het werkt (sinds 24 november 2026)

Outlook-agenda → Power Automate → GitHub (Actions + Pages) → website (WordPress)

- **Outlook** (account `janvanegmond@psg.nl`, agenda "JEL agenda website"): de bron.
- **Power Automate** (zelfde account), stroom **"JEL agenda naar website"**: elke dag om 12:00 en 18:00
  (tijdzone Amsterdam). Stappen: Agendaweergave van gebeurtenissen ophalen (V3) → Matrix filteren
  (privé/vertrouwelijk eruit) → Selecteren (korte velden s, b, e, h, l, n, c, p, m) → GitHub
  "Een gebeurtenis voor het verzenden van een opslagplaats maken" (repository dispatch `agenda`,
  nettolading `{"events": [...]}`). Alleen standaardkoppelingen, geen premiumlicentie nodig.
- **GitHub** `psgjanvanegmond-lab/jel-agenda` (openbaar; account op Gmail): `.github/workflows/agenda.yml`
  start op `repository_dispatch` → `build/outlook-naar-ics.py` → `build/build.php` → `docs/agenda.json`
  → GitHub Pages (https://psgjanvanegmond-lab.github.io/jel-agenda/).
- **WordPress** (janvanegmondlyceum.nl): blok *Aangepaste HTML* laadt `agenda.js` en `agenda.css` van Pages.
- **Render** (`jel-agenda-proxy`, map `proxy/`, `render.yaml`): het oude tussenstation, niet meer in gebruik.

Waarom zo: Microsoft weigert het ophalen van de gepubliceerde ICS-link vanaf GitHub-servers, en sinds
ongeveer 17 november 2026 ook vanaf Render (foutcode 417). Daardoor bleef de agenda een week op
17 november staan. Vanuit Power Automate komt de agenda uit Microsoft zelf en wordt niet geblokkeerd.

## Werken aan de code

- Geen afhankelijkheden: Python 3 (standaardbibliotheek) en PHP. Testen:
  `python3 build/outlook-naar-ics.py afspraken.json agenda.ics && php build/build.php agenda.ics docs/agenda.json`.
- Velden van Power Automate (V3-uitvoer): tijden zijn UTC zonder tijdzone; hele dagen staan op middernacht
  (datum direct gebruiken); de notitie (`body`) is HTML; er is geen `seriesMasterId`, dus `m` = onderwerp
  als `recurrence` niet `none` is. Opeenvolgende dagen van zo'n reeks worden weer een RRULE, zodat
  `class-jel-ics.php` ze samenvoegt (bijv. POP-gesprekken ma t/m do).
- De workflow schrijft de afspraken alleen naar een bestand, nooit naar het logboek (openbare opslagplaats).
- `build.php` werkt `updated` alleen bij als de inhoud echt verandert.
- Live zetten ging via de bewerkpagina van GitHub in de browser van de Claude-app (de Claude GitHub App
  staat nog niet op `psgjanvanegmond-lab`). Workflow-wijzigingen: eerst script, dan workflow.

## Direct bijwerken

Power Automate → stroom "JEL agenda naar website" → **Uitvoeren**. "Run workflow" in GitHub haalt géén
nieuwe agenda op, maar zet alleen de bestaande opnieuw online.

## Storing

1. Power Automate → uitvoeringsgeschiedenis. Verlopen koppeling: opnieuw inloggen bij die stap
   (Outlook-koppeling "Outlook JEL agenda"; GitHub-koppeling via OAuth-app "Microsoft Power Platform (Europe)").
2. GitHub → Actions → Agenda bijwerken. Bij een fout blijft de laatst bekende agenda staan.

## Nog open

- Render-dienst `jel-agenda-proxy` pauzeren of verwijderen (de beheerder beslist; niet zelf doen).
- Het publiceren van de agenda als ICS-link is niet meer nodig. De link stond in een chat; overwegen het
  publiceren in Outlook uit te zetten.
- De GitHub-koppeling van Power Automate heeft ruime rechten op het hele account (ook privé-repositories);
  intrekken kan in GitHub → Settings → Applications.
