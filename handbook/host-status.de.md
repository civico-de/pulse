---
title: Host-Status-Datei
order: 30
---

# Host-Status-Datei

Manche Signale zum Zustand einer Site entstehen außerhalb von CiviCRM, etwa der Datenbank-Dump der letzten Nacht, das Offsite-Backup oder der Health-Check des Hosts selbst. CiviCRM sieht sie nicht und soll auch keinen Zugriff auf die Systeme bekommen, die sie erzeugen. Die Host-Status-Datei gibt ihre Ergebnisse nur in eine Richtung weiter. Ein Prozess auf dem Host schreibt eine kleine JSON-Datei mit den Ergebnissen der Jobs. Die Datei wird schreibgeschützt in den CiviCRM-Container eingebunden, und der Check [pulse_host_status](monitor.md#pulsehoststatus) liest sie.

Jeder Host kann die Datei schreiben. Diese Seite beschreibt das Format, das pulse erwartet.

## Einrichtung

1. Lassen Sie den Host die Datei nach jedem Joblauf schreiben, wie unter [Die Datei schreiben](#die-datei-schreiben) beschrieben.
2. Binden Sie das Verzeichnis, das die Datei enthält, schreibgeschützt in den CiviCRM-Container ein. Binden Sie das Verzeichnis ein, nicht die Datei selbst. Eine Datei, die durch Umbenennen ersetzt wird, erhält einen neuen Inode, und ein Bind-Mount der Datei zeigt weiter den alten.
3. Speichern Sie den Pfad so, wie der Container ihn sieht:

   ```bash
   cv api4 Setting.set +v pulse_host_status_file=/var/lib/host-status/status.json
   ```

4. Führt die Datei mehrere Environments, teilen Sie pulse mit, welches davon diese Instanz ist:

   ```bash
   cv api4 Setting.set +v pulse_host_status_environment=production
   ```

## Format

Die Datei ist ein einzelnes JSON-Objekt:

```json
{
  "updated": "2026-10-13T03:12:40Z",
  "jobs": {
    "dump": {"status": "ok", "finished": "2026-10-13T03:12:40Z", "duration_seconds": 41, "expected_interval_hours": 24},
    "backup": {"status": "error", "finished": "2026-10-13T03:40:02Z", "duration_seconds": 12, "detail": "repository locked", "expected_interval_hours": 24}
  },
  "environments": {
    "production": {
      "jobs": {
        "dump": {"status": "ok", "finished": "2026-10-13T03:12:40Z", "duration_seconds": 41, "expected_interval_hours": 24}
      }
    },
    "staging": {
      "jobs": {
        "backup": {"status": "error", "finished": "2026-10-13T03:40:02Z", "duration_seconds": 12, "detail": "repository locked", "expected_interval_hours": 24}
      }
    }
  }
}
```

### Oberste Ebene

| Schlüssel | Typ | Pflicht | Bedeutung |
|---|---|---|---|
| `updated` | Zeitstempel | ja | Wann die Datei zuletzt geschrieben wurde. Ein fehlender oder unlesbarer Wert gilt als veraltet. |
| `jobs` | Objekt aus Jobs | ja | Die Ergebnisse der Jobs für die ganze Datei. Es darf leer sein. Eine Datei ohne dieses Objekt wird als ungültig abgelehnt. |
| `environments` | Objekt | nein | Ein Eintrag pro Environment, mit dem Namen des Environments als Schlüssel. Jeder Eintrag ist ein Objekt mit einem eigenen Objekt `jobs`. |

### Job

Jeder Schlüssel in einem Objekt `jobs` ist ein Jobname, den der erzeugende Prozess wählt, etwa dump oder backup. Der Name erscheint in den Meldungen. Sein Wert ist ein Objekt:

| Schlüssel | Typ | Pflicht | Bedeutung |
|---|---|---|---|
| `status` | String | nein | `ok` oder `error`, das Ergebnis des letzten Laufs. Ein `error` lässt den Check fehlschlagen. Jeder andere Wert oder gar keiner wird allein nach `finished` bewertet. |
| `finished` | Zeitstempel | ja | Wann der letzte Lauf endete. Bei einem Job mit `ok` ist das der letzte Erfolg. Ein fehlender oder unlesbarer Wert gilt als kein Erfolg. |
| `duration_seconds` | Zahl | nein | Wie lange der letzte Lauf gedauert hat. pulse reicht den Wert nur an Poller weiter. |
| `expected_interval_hours` | Zahl | nein | Wie oft der Job laufen soll. Ein positiver Wert erweitert das Fenster des Jobs, siehe [Regeln](#regeln). |
| `detail` | String | nein | Eine kurze Erklärung eines Fehlers. Sie wird Teil des Details des Checks, das die Meldung und den Endpunkt erreicht. Halten Sie sie frei von Geheimnissen, Pfaden und personenbezogenen Daten. |

Einträge, die keine Objekte sind, werden ignoriert, ebenso alle anderen Schlüssel.

pulse liest Zeitstempel mit der PHP-Funktion `strtotime()`. Schreiben Sie ISO 8601 mit expliziter Zeitzonenangabe, etwa `2026-10-13T03:12:40Z`. Ohne Angabe wird die Zeit in der PHP-Zeitzone des Servers gelesen.

## Welche Jobs bewertet werden

- Ohne ein Objekt `environments` bewertet pulse die `jobs` der obersten Ebene.
- Mit einem Objekt `environments` bewertet pulse nur die Jobs des Environments, das in `pulse_host_status_environment` steht. Ist diese Einstellung leer, schlägt der Check fehl, statt zu raten. Er schlägt auch fehl, wenn das genannte Environment fehlt oder keine Jobs hat.

Ein Job namens `health` wird nie bewertet. Er steht für den Health-Lauf des Hosts, der diesen Check selbst liest; ihn zu bewerten, hielte einen einzigen fehlgeschlagenen Lauf dauerhaft im Fehler.

Ein Host mit einer einzigen Instanz kann nur `jobs` schreiben. Ein Host, der mehrere Instanzen aus einer Datei bedient, schreibt `environments` und gibt jeder Instanz ihren Namen. Die `jobs` der obersten Ebene müssen auch dann vorhanden sein, notfalls leer.

## Regeln

Das Grundfenster ist `pulse_host_status_max_age` Stunden lang, standardmäßig 26. Ein Wert von 0 oder weniger fällt auf 26 zurück.

- Ein Job mit einem positiven `expected_interval_hours` erhält ein Fenster vom Anderthalbfachen dieses Intervalls, aufgerundet auf volle Stunden. Dieses Fenster ist nie kürzer als das Grundfenster. Jeder andere Job erhält das Grundfenster.
- Ein Job, dessen `status` gleich `error` ist, lässt den Check fehlschlagen, mit seinem `detail` in der Meldung. Sein Alter wird dann nicht zusätzlich bewertet.
- Jeder andere Job lässt den Check fehlschlagen, wenn sein Zeitpunkt `finished` weiter zurückliegt als sein Fenster.
- Die Datei lässt den Check fehlschlagen, wenn `updated` weiter zurückliegt als das kleinste Fenster unter den bewerteten Jobs. Ohne bewertete Jobs gilt das Grundfenster. Der Host muss die Datei also mindestens so oft neu schreiben, wie sein häufigster Job läuft.

pulse meldet alle Probleme zusammen in einem Detail, getrennt durch Semikolons.

## Die Datei schreiben

- Schreiben Sie bei jedem Joblauf die ganze Datei. Ersetzen Sie sie atomar, indem Sie eine temporäre Datei schreiben und umbenennen. Eine halb geschriebene Datei gilt als ungültig und lässt den Check fehlschlagen.
- Halten Sie das letzte Ergebnis jedes Jobs fest, keinen Verlauf. Ein späterer Erfolg ersetzt einen früheren Fehler.
- Beschränken Sie die Datei auf Metadaten. Sie ist im CiviCRM-Container lesbar und darf daher keine Pfade zu Backups, keine Repository-Adressen und keine Zugangsdaten enthalten.
