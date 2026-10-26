---
title: pulse
---

# pulse

pulse sagt einem Uptime-Monitor, ob eine CiviCRM-Site wirklich lebt. Die Extension stellt eine tokengeschützte URL bereit, die Uptime Kuma, Nagios, Icinga oder jeder andere HTTP-Monitor abfragen kann. Eine erfolgreiche Antwort beweist mehr als eine statische Seite. Webserver, PHP, das CMS und CiviCRM sind gestartet, die Datenbank hat geantwortet, und Cron ist vor Kurzem gelaufen.

pulse meldet auch Probleme, die keine Ausfälle sind, als Meldungen auf der Seite Systemstatus von CiviCRM. Seine Status-Checks erfassen ausstehende Updates, hängende geplante Jobs und Queues, fehlgeschlagene Payment-Webhooks, knappen Speicherplatz, fatale PHP-Fehler, eine defekte CiviRules-Konfiguration, doppelte Kontakte und Backups auf dem Host.

pulse läuft unter Standalone, Drupal und WordPress. Die URL wird über das Menüsystem von CiviCRM registriert, daher braucht kein CMS eine zusätzliche Einrichtung.

## Voraussetzungen

Die Status-Checks benötigen keine weitere Extension. Für eine Benachrichtigung, sobald eine Meldung erscheint, installieren Sie [herald](https://civico-de.github.io/herald/de/), dessen Check-Monitor den Systemstatus liest. Die unterstützten Versionen von CiviCRM und PHP stehen in der `info.xml` der Extension.

## Installation

Laden Sie das Release-Archiv von der [Release-Seite](https://github.com/civico-de/pulse/releases) herunter, entpacken Sie es in das Extension-Verzeichnis Ihrer CiviCRM-Installation und aktivieren Sie die Extension:

```bash
cv ext:enable pulse
```

## Erste Schritte

Der Endpunkt lehnt jede Anfrage ab, solange kein Token gesetzt ist. Erzeugen Sie eines und speichern Sie es:

```bash
cv api4 Setting.set +v pulse_token="$(openssl rand -hex 24)"
```

Richten Sie Ihren Monitor auf `https://example.org/civicrm/pulse` und setzen Sie die Adresse Ihrer Site an die Stelle von example.org. Senden Sie das Token im Header `X-Pulse-Token` und erwarten Sie HTTP 200. Der HTTP(s)-Monitor von Uptime Kuma akzeptiert standardmäßig die Statuscodes 200 bis 299 und braucht daher keine weitere Einrichtung.

Standardmäßig schlägt der Endpunkt fehl, wenn Cron 60 Minuten lang nicht gelaufen ist. Für eine längere Pause setzen Sie die Grenze in Minuten:

```bash
cv api4 Setting.set +v pulse_max_cron_age=120
```

Behandeln Sie das Token wie jede andere Zugangsinformation. Verwenden Sie HTTPS und tauschen Sie das Token aus, wenn es bekannt geworden ist. pulse vergleicht es in konstanter Zeit. Steht die Site hinter einem Reverse Proxy, der nur bekannte Adressen zulässt, behalten Sie diese Einschränkung bei. Das Token ist eine zweite Schutzschicht, kein Ersatz.

## Wie es weitergeht

- [Endpunkt](endpoint.md) beschreibt die Anfrage, die Statuscodes und die Checks hinter der Antwort.
- [Status-Checks](monitor.md) listet die Meldungen auf, die pulse zum Systemstatus beisteuert.
- [Host-Status-Datei](host-status.md) dokumentiert die Datei, über die ein Host Backups und andere Jobs auf dem Host meldet.
- [Referenz](reference.md) listet alle Einstellungen auf und erklärt die Übersetzungen.
