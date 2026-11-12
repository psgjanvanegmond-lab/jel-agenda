"""
JEL Agenda - tussenstation op Render.

GitHub kan Outlook niet bereiken (Microsoft blokkeert dat verkeer). Deze kleine
webservice haalt de Outlook-agenda (ICS) op en geeft een opgeschoonde versie door:
privé-, vertrouwelijke en geannuleerde afspraken worden eruit gehaald. Wat overblijft
is precies wat ook openbaar op de website komt; de Outlook-link zelf blijft geheim.

Instelling (Environment Variable op Render):
  ICS_URL    de ICS-link uit Outlook

Adressen:
  GET /health       -> "ok" (controle of de service draait)
  GET /agenda.ics   -> de opgeschoonde agenda

Alleen Python-standaardbibliotheek, geen extra pakketten.
"""

import os
import re
import sys
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ICS_URL = os.environ.get("ICS_URL", "").strip().replace("webcal://", "https://", 1)
PORT = int(os.environ.get("PORT", "10000"))
CACHE_SECONDS = 300

_cache = {"time": 0.0, "body": b""}

# Velden die nooit worden doorgegeven (deelnemers, organisator e.d.).
DROP_PROPS = ("ATTENDEE", "ORGANIZER", "X-MS-OLK-", "X-MICROSOFT-CDO-OWNERAPPTID")


def clean_ics(text):
    """Verwijdert privé/vertrouwelijke/geannuleerde afspraken en persoonsgegevens."""
    # Doorlopende regels samenvoegen, zodat elke eigenschap op één regel staat.
    text = re.sub(r"\r?\n[ \t]", "", text.replace("\r\n", "\n"))
    out, event = [], None
    for line in text.split("\n"):
        upper = line.upper()
        if upper == "BEGIN:VEVENT":
            event = [line]
            continue
        if event is not None:
            event.append(line)
            if upper == "END:VEVENT":
                block = "\n".join(event).upper()
                skip = re.search(r"\n(CLASS:(PRIVATE|CONFIDENTIAL)|STATUS:CANCELLED)\s*\n", block)
                if not skip:
                    out.extend(l for l in event if not l.upper().startswith(DROP_PROPS))
                event = None
            continue
        out.append(line)
    return "\r\n".join(out).strip() + "\r\n"


def fetch_ics():
    """Haalt de agenda op (met korte cache). Geeft (status, body) terug."""
    now = time.time()
    if _cache["body"] and now - _cache["time"] < CACHE_SECONDS:
        return 200, _cache["body"]
    req = urllib.request.Request(
        ICS_URL,
        headers={"Accept": "text/calendar", "User-Agent": "Mozilla/5.0 (JEL agenda)"},
    )
    try:
        with urllib.request.urlopen(req, timeout=40) as resp:
            raw = resp.read(10 * 1024 * 1024)
    except urllib.error.HTTPError as e:
        return 502, f"Outlook gaf foutcode {e.code}.".encode()
    except Exception as e:  # netwerkfout, time-out
        return 502, f"Outlook niet bereikbaar ({type(e).__name__}).".encode()
    text = raw.decode("utf-8", errors="replace")
    if "BEGIN:VCALENDAR" not in text[:2000].upper():
        return 502, b"Outlook gaf geen agenda terug. Is het de ICS-link?"
    body = clean_ics(text).encode("utf-8")
    _cache.update(time=now, body=body)
    return 200, body


class Handler(BaseHTTPRequestHandler):
    server_version = "jel-agenda"
    sys_version = ""

    def _send(self, status, body, ctype="text/plain; charset=utf-8"):
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Robots-Tag", "noindex")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        path = self.path.split("?", 1)[0]
        if path in ("/", "/health"):
            return self._send(200, b"ok")
        if path != "/agenda.ics":
            return self._send(404, b"Niet gevonden.")
        if not ICS_URL:
            return self._send(500, b"ICS_URL is niet ingesteld op Render.")
        status, body = fetch_ics()
        if status == 200:
            return self._send(200, body, "text/calendar; charset=utf-8")
        return self._send(status, body)

    def log_message(self, fmt, *args):
        # Alleen methode, pad en status; nooit de Outlook-link.
        sys.stderr.write("%s %s\n" % (self.command, fmt % args if args else fmt))


if __name__ == "__main__":
    print(f"JEL agenda-tussenstation luistert op poort {PORT}", flush=True)
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
