#!/usr/bin/env python3
"""Zet de afspraken die Power Automate meestuurt om naar een ICS-agenda voor build.php.

Gebruik:  python3 build/outlook-naar-ics.py <afspraken.json> <agenda.ics>

Power Automate leest de Outlook-agenda "JEL agenda website" (Get calendar view of events V3)
en stuurt per afspraak deze velden mee (korte namen, zodat het bericht klein blijft):
  s = onderwerp, b = begin, e = einde, h = hele dag (true/false), l = locatie,
  n = notitie (body, HTML), c = categorieën, p = vertrouwelijkheid (sensitivity),
  x = geannuleerd, m = reeks (leeg bij een losse afspraak, anders het onderwerp)

Tijden zonder tijdzone zijn UTC (zo levert Power Automate ze). Afspraken uit dezelfde reeks
op opeenvolgende dagen worden weer als herhaling geschreven, zodat build.php ze net als
vroeger samenvoegt tot één regel (bijv. POP-gesprekken ma t/m do).
"""

import html
import json
import re
import sys
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

NL = ZoneInfo("Europe/Amsterdam")


def lees_tijd(waarde):
    """Tekst uit Outlook -> datetime in Nederlandse tijd."""
    tekst = (waarde or "").strip().replace("Z", "+00:00")
    if "." in tekst:  # fracties van seconden (".0000000") weglaten
        hoofd, rest = tekst.split(".", 1)
        tz = ""
        for teken in ("+", "-"):
            if teken in rest:
                tz = teken + rest.split(teken, 1)[1]
        tekst = hoofd + tz
    dt = datetime.fromisoformat(tekst)
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(NL)


def hele_dag(waarde):
    """Datum van een hele-dagafspraak. Staat de tijd niet op middernacht, dan is het UTC."""
    tekst = (waarde or "").strip()
    if len(tekst) >= 19 and tekst[11:19] == "00:00:00" and "+" not in tekst[19:] and "Z" not in tekst:
        return datetime.fromisoformat(tekst[:10]).date()
    return lees_tijd(tekst).date()


def html_naar_tekst(waarde):
    """De notitie komt uit Outlook als HTML; alleen de tekst blijft over."""
    tekst = str(waarde or "")
    if "<" not in tekst:
        return tekst.strip()
    tekst = re.sub(r"(?is)<(style|script|head)\b.*?</\1>", " ", tekst)
    tekst = re.sub(r"(?i)<br\s*/?>|</(p|div|li|tr|h\d)>", "\n", tekst)
    tekst = html.unescape(re.sub(r"<[^>]+>", " ", tekst)).replace("\xa0", " ")
    regels = [re.sub(r"[ \t]+", " ", r).strip() for r in tekst.split("\n")]
    return "\n".join(r for r in regels if r).strip()


def ics_tekst(waarde):
    tekst = str(waarde or "").replace("\r\n", "\n").replace("\r", "\n").strip()
    for oud, nieuw in (("\\", "\\\\"), (";", "\\;"), (",", "\\,"), ("\n", "\\n")):
        tekst = tekst.replace(oud, nieuw)
    return tekst


def vouw(regel):
    """ICS-regels van hooguit 75 tekens."""
    uit, deel = [], regel
    while len(deel.encode("utf-8")) > 75:
        knip = 74
        while len(deel[:knip].encode("utf-8")) > 74:
            knip -= 1
        uit.append(deel[:knip])
        deel = " " + deel[knip:]
    uit.append(deel)
    return "\r\n".join(uit)


def lees_afspraken(pad):
    with open(pad, encoding="utf-8") as f:
        data = json.load(f)
    if isinstance(data, dict):
        data = data.get("events", data.get("afspraken", []))
    if isinstance(data, str):  # Power Automate stuurt soms een tekst met JSON erin
        data = json.loads(data)
    if not isinstance(data, list):
        raise ValueError("geen lijst met afspraken")
    return data


def maak_ics(afspraken):
    items = []
    for a in afspraken:
        if not isinstance(a, dict) or not (a.get("s") or "").strip():
            continue
        item = {
            "titel": a.get("s", ""),
            "locatie": a.get("l") or "",
            "notitie": html_naar_tekst(a.get("n")),
            "categorieen": [c for c in (a.get("c") or []) if c],
            "prive": str(a.get("p") or "").lower() in ("private", "confidential"),
            "geannuleerd": bool(a.get("x")),
            "reeks": a.get("m") or "",
            "heledag": bool(a.get("h")),
        }
        if item["heledag"]:
            item["begin"] = hele_dag(a.get("b"))
            item["einde"] = hele_dag(a.get("e"))  # exclusief, zoals in ICS
            if item["einde"] <= item["begin"]:
                item["einde"] = item["begin"] + timedelta(days=1)
        else:
            item["begin"] = lees_tijd(a.get("b"))
            item["einde"] = lees_tijd(a.get("e"))
        items.append(item)

    # Afspraken uit dezelfde reeks met dezelfde tijden op opeenvolgende dagen: één herhaling.
    groepen = {}
    for i, item in enumerate(items):
        if item["reeks"]:
            if item["heledag"]:
                sleutel_tijd = ("heledag", (item["einde"] - item["begin"]).days)
            else:
                sleutel_tijd = (item["begin"].strftime("%H:%M"), item["einde"] - item["begin"])
            sleutel = (item["reeks"], item["titel"], item["locatie"], item["prive"], item["geannuleerd"], sleutel_tijd)
        else:
            sleutel = ("los", i)
        groepen.setdefault(sleutel, []).append(item)

    regels = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//JEL//Agenda via Power Automate//NL", "X-WR-CALNAME:JEL agenda website"]
    teller = 0
    for groep in groepen.values():
        groep.sort(key=lambda x: x["begin"])
        reeksen = []
        for item in groep:
            dag = item["begin"].date() if not item["heledag"] else item["begin"]
            if reeksen:
                vorige = reeksen[-1]
                vorige_dag = vorige["laatste"]
                if dag == vorige_dag + timedelta(days=1):
                    vorige["laatste"] = dag
                    vorige["aantal"] += 1
                    continue
            reeksen.append({"item": item, "laatste": dag, "aantal": 1})
        for r in reeksen:
            item = r["item"]
            teller += 1
            regels.append("BEGIN:VEVENT")
            regels.append(f"UID:jel-pa-{teller}")
            if item["heledag"]:
                regels.append("DTSTART;VALUE=DATE:" + item["begin"].strftime("%Y%m%d"))
                regels.append("DTEND;VALUE=DATE:" + item["einde"].strftime("%Y%m%d"))
            else:
                regels.append("DTSTART;TZID=Europe/Amsterdam:" + item["begin"].strftime("%Y%m%dT%H%M%S"))
                regels.append("DTEND;TZID=Europe/Amsterdam:" + item["einde"].strftime("%Y%m%dT%H%M%S"))
            if r["aantal"] > 1:
                regels.append(f"RRULE:FREQ=DAILY;COUNT={r['aantal']}")
            regels.append("SUMMARY:" + ics_tekst(item["titel"]))
            if item["locatie"]:
                regels.append("LOCATION:" + ics_tekst(item["locatie"]))
            if item["notitie"]:
                regels.append("DESCRIPTION:" + ics_tekst(item["notitie"]))
            if item["categorieen"]:
                regels.append("CATEGORIES:" + ",".join(ics_tekst(c) for c in item["categorieen"]))
            if item["prive"]:
                regels.append("CLASS:PRIVATE")
            if item["geannuleerd"]:
                regels.append("STATUS:CANCELLED")
            regels.append("END:VEVENT")
    regels.append("END:VCALENDAR")
    return "\r\n".join(vouw(r) for r in regels) + "\r\n", len(items)


def main():
    if len(sys.argv) != 3:
        sys.exit("Gebruik: python3 build/outlook-naar-ics.py <afspraken.json> <agenda.ics>")
    try:
        afspraken = lees_afspraken(sys.argv[1])
        ics, aantal = maak_ics(afspraken)
    except Exception as fout:  # noqa: BLE001
        sys.exit(f"FOUT: kon de afspraken van Power Automate niet lezen ({type(fout).__name__}: {fout})")
    if not aantal:
        sys.exit("FOUT: Power Automate stuurde geen afspraken mee; de oude agenda blijft staan.")
    with open(sys.argv[2], "w", encoding="utf-8", newline="") as f:
        f.write(ics)
    print(f"{aantal} afspraken van Power Automate omgezet naar ICS")


if __name__ == "__main__":
    main()
