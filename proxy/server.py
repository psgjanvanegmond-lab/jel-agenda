"""
JEL Agenda - tussenstation op Render.

GitHub kan Outlook niet bereiken (Microsoft blokkeert dat verkeer). Deze kleine
webservice haalt de Outlook-agenda (ICS) op en geeft die door aan de GitHub-taak.

Instellingen (Environment Variables op Render):
  ICS_URL    de ICS-link uit Outlook
  TOKEN      geheime sleutel; alleen wie die meestuurt krijgt de agenda

Adressen:
  GET /             -> "ok" (controle of de service draait)
  GET /agenda.ics   -> de agenda; vereist de kop  Authorization: Bearer <TOKEN>

Alleen Python-standaardbibliotheek, geen extra pakketten.
"""

import hmac
import os
import sys
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ICS_URL = os.environ.get("ICS_URL", "").strip().replace("webcal://", "https://", 1)
TOKEN = os.environ.get("TOKEN", "").strip()
PORT = int(os.environ.get("PORT", "10000"))
CACHE_SECONDS = 300

_cache = {"time": 0.0, "body": b""}


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
            body = resp.read(10 * 1024 * 1024)
    except urllib.error.HTTPError as e:
        return 502, f"Outlook gaf foutcode {e.code}.".encode()
    except Exception as e:  # netwerkfout, time-out
        return 502, f"Outlook niet bereikbaar ({type(e).__name__}).".encode()
    if b"BEGIN:VCALENDAR" not in body[:2000].upper():
        return 502, b"Outlook gaf geen agenda terug. Is het de ICS-link?"
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
        if not ICS_URL or not TOKEN:
            return self._send(500, b"ICS_URL of TOKEN is niet ingesteld op Render.")
        auth = self.headers.get("Authorization", "")
        given = auth[7:].strip() if auth.lower().startswith("bearer ") else ""
        if not hmac.compare_digest(given.encode(), TOKEN.encode()):
            return self._send(401, b"Geen toegang.")
        status, body = fetch_ics()
        if status == 200:
            return self._send(200, body, "text/calendar; charset=utf-8")
        return self._send(status, body)

    def log_message(self, fmt, *args):
        # Alleen pad en status loggen, nooit kopteksten (daar staat de sleutel in).
        sys.stderr.write("%s %s\n" % (self.command, fmt % args if args else fmt))


if __name__ == "__main__":
    print(f"JEL agenda-tussenstation luistert op poort {PORT}", flush=True)
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
