---
title: Endpunkt
order: 10
---

# Endpunkt

Ein Uptime-Monitor fragt eine URL ab und liest den HTTP-Statuscode. Eine 200 beweist, dass Webserver, PHP, das CMS und der Start von CiviCRM funktionieren. Sie beweist außerdem, dass die Datenbank antwortet und Cron vor Kurzem gelaufen ist, sofern der Cron-Check nicht abgeschaltet ist.

## Anfrage

```text
GET /civicrm/pulse
X-Pulse-Token: <token>
```

Senden Sie das Token im Header `X-Pulse-Token`. Ein Monitor, der keine Header setzen kann, übergibt es stattdessen als Query-Parameter `?token=<token>`. Der Header ist sicherer, weil Query-Strings in den Zugriffsprotokollen von Proxys und Webservern landen. Sind beide vorhanden, gewinnt der Header.

Das Token kann nicht in einem Header `Authorization: Bearer` reisen. Die CiviCRM-Extension authx wertet diesen Header bei jeder Anfrage aus und antwortet auf ein unbekanntes Token mit 401, bevor pulse überhaupt läuft.

## Antwort

Der Body ist JSON. Sein Feld `status` stimmt immer mit dem HTTP-Statuscode überein:

| HTTP | `status` | Bedeutung |
|---|---|---|
| 200 | `ok` | Alle Checks waren erfolgreich. |
| 503 | `fail` | Mindestens ein Check ist fehlgeschlagen. |
| 503 | `unconfigured` | Die Einstellung `pulse_token` ist leer, daher lehnt der Endpunkt jede Anfrage ab. |
| 403 | `forbidden` | Das Token ist falsch oder fehlt. |

Eine Beispielantwort:

```json
{"status":"ok","checks":{"database":{"status":"ok"},"cron":{"status":"ok","last_run":"2026-07-14 21:40:02","age_minutes":3,"max_age_minutes":60},"system":{"status":"ok","min_severity":"critical"},"host_status":{"status":"skipped"}}}
```

## Checks

Drei Checks entscheiden über den HTTP-Status. Ein vierter berichtet nur.

### database

pulse schickt eine triviale Abfrage an die CiviCRM-Datenbank. Der Check schlägt fehl, wenn die Abfrage fehlschlägt.

### cron

Der Check schlägt fehl, wenn der neueste Eintrag im Protokoll der geplanten Jobs von CiviCRM älter ist als `pulse_max_cron_age` Minuten. Der Standard ist 60, und 0 schaltet den Check ab. Jeder Cron-Lauf schreibt einen Eintrag, auch wenn kein Job fällig ist. Der Check zeigt also, ob Cron überhaupt läuft. Ob jeder einzelne Job pünktlich läuft, prüft der Status-Check [pulse_jobs](monitor.md#pulsejobs).

### system

Der Check schlägt fehl, wenn der Systemstatus von CiviCRM, also die Liste auf der Seite „System Status“ (Systemstatus), eine sichtbare Meldung mit dem Schweregrad `pulse_min_severity` oder höher zeigt. Der Standard ist `critical`. Der Wert `off` schaltet den Check ab, ein leerer Wert fällt auf den Standard zurück.

Einige der zugrunde liegenden Prüfungen sind aufwendig. pulse speichert ein erfolgreiches Ergebnis daher fünf Minuten lang zwischen und ein fehlgeschlagenes eine Minute lang. Ein neues Problem zeigt sich deshalb mit einigen Minuten Verzögerung.

### host_status

Dieser Check ändert nie den HTTP-Status, denn ein verspätetes Backup ist kein Ausfall. Er enthält das Ergebnis des Status-Checks [pulse_host_status](monitor.md#pulsehoststatus). So kann ein Poller das Alter des letzten Backups anzeigen, ohne Zugriff auf den Host zu haben. Solange `pulse_host_status_file` leer ist, meldet er `skipped`.

Findet pulse die Jobs, die es bewertet, enthält das Ergebnis außerdem den Zeitstempel `updated` der Datei und eine Zuordnung `jobs`. Für jeden Job enthält sie `status`, `finished`, `duration_seconds` und `expected_interval_hours`. Bei einem fehlgeschlagenen Ergebnis nennt das Feld `detail` die fehlgeschlagenen Jobs zusammen mit ihrem eigenen `detail`-Text.
