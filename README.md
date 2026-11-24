# JEL Agenda

Toont de agenda van het Jan van Egmond Lyceum op de website, rechtstreeks uit de Outlook-agenda **"JEL agenda website"**.

```
Outlook-agenda  →  Power Automate  →  GitHub (Actions + Pages)  →  website (WordPress)
```

- **Power Automate** (stroom **"JEL agenda naar website"**, account `janvanegmond@psg.nl`) leest
  **automatisch om 12:00 en 18:00** de Outlook-agenda, laat privé-afspraken weg en stuurt de afspraken
  als seintje `agenda` (repository dispatch) naar deze opslagplaats.
- **GitHub Actions** zet de afspraken om (`build/outlook-naar-ics.py` en `build/build.php`) naar
  `docs/agenda.json` en publiceert die op GitHub Pages.
- Microsoft blokkeert het ophalen van de agenda vanaf GitHub en (sinds november 2026) ook vanaf Render.
  Daarom komt de agenda nu vanuit Microsoft zelf. Het oude tussenstation op Render (map `proxy/`) wordt
  niet meer gebruikt.
- De website laat de agenda zien met een klein stukje code: **1 maand tegelijk met pijltjes, tijden, datumbereik en icoontjes**.
- Werkt GitHub of Outlook een keer niet, dan blijft de laatst bekende agenda staan.

## Agenda bijhouden (in Outlook)

| Situatie | Zo maak je het in Outlook | Op de website |
|---|---|---|
| Activiteit met tijd | Gewone afspraak | "14:45–16:15 uur" |
| Hele dag | "Hele dag" aan | geen tijd |
| Periode (toetsweek, vakantie, CE) | **Eén** afspraak "hele dag", van eerste t/m laatste dag | "vr 14 mei t/m di 1 jun" |
| Meerdere dagen, elke dag zelfde tijd (POP-gesprekken) | Herhalen: elke dag, tot de laatste dag | één regel: "ma 23 t/m do 26 nov · 17:00–20:00 uur" |
| Niet op de website | Afspraak op **Privé** zetten | wordt overgeslagen |

Locatie en notitie uit Outlook worden ook getoond. Het icoon wordt gekozen op basis van de titel. Zelf kiezen kan met een Outlook-categorie met een van deze namen: `vakantie`, `vrij`, `toets`, `uitslag`, `gesprek`, `groep8`, `activiteit`, `rooster`, `agenda`.

## Handmatig bijwerken (na een wijziging in Outlook)

1. Ga naar <https://make.powerautomate.com> (inloggen met `janvanegmond@psg.nl`) → **Mijn stromen**.
2. Open **JEL agenda naar website** en klik op **Uitvoeren**.
3. Na ongeveer een minuut staat er bij GitHub *Actions* een groen vinkje en staat de wijziging op de website
   (eventueel de pagina verversen met Cmd+Shift+R / Ctrl+F5).

Let op: **Run workflow** in GitHub haalt géén nieuwe agenda op; het zet alleen de bestaande opnieuw online.

## Als het niet werkt

1. **Power Automate** → de stroom → *Uitvoeringsgeschiedenis*. Mislukt een stap, dan is meestal een
   koppeling verlopen: open de stroom en log bij die stap opnieuw in (Outlook met `janvanegmond@psg.nl`,
   GitHub met het account `psgjanvanegmond-lab`).
2. **GitHub** → *Actions* → *Agenda bijwerken*: de fout staat bij de mislukte run. GitHub stuurt dan ook een e-mail.

Gaat er iets mis, dan blijft de laatst bekende agenda gewoon op de website staan.

## Eenmalig instellen (al gedaan, voor als het opnieuw moet)

**Power Automate:** geplande cloudstroom "JEL agenda naar website", elke dag om 12 en 18 uur
(tijdzone Amsterdam), met deze stappen:

1. **Agendaweergave van gebeurtenissen ophalen (V3)** (Office 365 Outlook): agenda "JEL agenda website",
   begintijd `addDays(utcNow(), -35)`, eindtijd `addDays(utcNow(), 400)`.
2. **Matrix filteren**: van `body('Agendaweergave_van_gebeurtenissen_ophalen_(V3)')?['value']`, filter
   `@not(contains(createArray('private','confidential'), toLower(string(item()?['sensitivity']))))`.
3. **Selecteren**: van `body('Matrix_filteren')`, toewijzing (tekstmodus) als expressie:
   `setProperty(...)` met de velden `s`, `b`, `e`, `h`, `l`, `n`, `c`, `p`, `m`
   (zie de uitleg bovenin `build/outlook-naar-ics.py`).
4. **Een gebeurtenis voor het verzenden van een opslagplaats maken** (GitHub): eigenaar `psgjanvanegmond-lab`,
   opslagplaats `jel-agenda`, gebeurtenisnaam `agenda`, nettolading `setProperty(json('{}'),'events',body('Selecteren'))`.

**GitHub Pages:** *Settings → Pages → Build and deployment → Source:* **GitHub Actions**.
Controleer: <https://psgjanvanegmond-lab.github.io/jel-agenda/>

## Insluiten op de website (WordPress)

Voeg op de pagina een blok **Aangepaste HTML** toe met:

```html
<div data-jel-agenda-embed data-maanden="1">Agenda laden…</div>
<link rel="stylesheet" href="https://psgjanvanegmond-lab.github.io/jel-agenda/agenda.css">
<script src="https://psgjanvanegmond-lab.github.io/jel-agenda/agenda.js" defer></script>
```

Opties op de eerste regel:

- `data-maanden="2"`: 2 maanden tegelijk (`0` = alles onder elkaar)
- `data-aantal="5"`: alleen de eerstvolgende 5, zonder pijltjes (bijv. voor de homepage)
- `data-verleden="ja"`: ook afgelopen activiteiten

**Terugdraaien:** haal het blok weg, of zet het oude Agenda-blok weer aan.

## Technisch

- `build/outlook-naar-ics.py`: zet de afspraken van Power Automate om naar een ICS-agenda (HTML-notities
  naar tekst, UTC naar Nederlandse tijd, herhalingen weer als reeks).
- `build/class-jel-ics.php`: leest de ICS (tijdzones, hele dagen, herhalingen, uitzonderingen) en voegt aaneengesloten dagen samen.
- `build/build.php`: maakt `docs/agenda.json`. Stopt met een fout als het geen agenda is, of als Outlook alleen "bezet/vrij" doorgeeft.
- `docs/agenda.js` en `docs/agenda.css`: de weergave op de website.
- `.github/workflows/agenda.yml`: de taak die start op het seintje `agenda` van Power Automate.
- `proxy/server.py` en `render.yaml`: het oude tussenstation op Render, **niet meer in gebruik**.

Lokaal testen:

```bash
python3 build/outlook-naar-ics.py afspraken.json agenda.ics
php build/build.php agenda.ics docs/agenda.json
```
