---
title: Status-Checks
order: 20
---

# Status-Checks

Manche Probleme sind keine Ausfälle, brauchen aber trotzdem Aufmerksamkeit, etwa ein ausstehendes Sicherheitsupdate, eine hängende Queue oder ein Backup, das nicht gelaufen ist. pulse meldet sie als Meldungen auf der Seite Systemstatus von CiviCRM. Jeder Check hat einen eigenen Meldungsnamen, etwa `pulse_jobs`, und ein gesunder Check zeigt keine Meldung. Wie jede Core-Meldung lässt sich eine Meldung auf der Seite Systemstatus ausblenden.

Ist die Extension herald installiert, liest ihr [Check-Monitor](https://civico-de.github.io/herald/de/monitor/) dieselben Meldungen aus dem Systemstatus. herald meldet, wenn eine Meldung erscheint und wenn sie wieder verschwindet.

Die Checks gehören nicht zum [Endpunkt](endpoint.md), denn ein ausstehendes Update ist kein Ausfall. Der Endpunkt bleibt das Signal für einen externen Poller, denn eine Site, die nicht erreichbar ist, kann ihren eigenen Ausfall nicht melden. Ob Cron läuft, fragt aus demselben Grund der Endpunkt: Ein Check, der per Cron läuft, kann nicht melden, dass Cron steht.

Jede Schwellwert-Einstellung unten versteht 0 als abgeschaltet, sofern ihr Abschnitt nichts anderes sagt. Die [Referenz](reference.md#einstellungen) listet alle Einstellungen mit ihren Standardwerten auf.

Ein Check, der nicht prüfen kann, meldet das mit dem Schweregrad error und nennt, was fehlschlug. Er bleibt nicht stumm.

| Meldung | Schweregrad |
|---|---|
| `pulse_database` | critical |
| `pulse_updates` | aus `pulse_updates_min_severity`, standardmäßig `warning` |
| `pulse_updates_feed` | error |
| `pulse_cms_updates` | error bei Sicherheitsreleases, sonst warning |
| `pulse_jobs`, `pulse_queues`, `pulse_disk`, `pulse_duplicates` | warning |
| `pulse_payments`, `pulse_host_status`, `pulse_fatal_errors`, `pulse_civirules` | error |

## pulse_database

Die Meldung erscheint, wenn eine einfache Abfrage an die Datenbank von CiviCRM fehlschlägt.

## pulse_updates

Die Meldung listet installierte Extensions mit einem neueren Release. pulse vergleicht jede installierte Extension mit dem Extension-Verzeichnis, das CiviCRM für seine Extension-Seite liest. `pulse_updates_min_severity` legt den Schweregrad der Meldung fest, standardmäßig `warning`. Der Wert `off` schaltet den Check ab.

Solange der Check läuft, blendet pulse die Core-Meldung zu Extension-Updates aus, `checkExtensionsUpdates`. Diese Meldung fasst alle Updates in einem Block zusammen, ihr Ausblenden blendet also auch jedes künftige Update aus. pulse hält dafür eine Statuseinstellung, die sie bis zum Schweregrad warning ausblendet. Die Einstellung besteht nur, solange der Check läuft: Mit `off` oder wenn pulse deaktiviert oder deinstalliert wird, erscheint die Core-Meldung wieder. Die Core-Meldungen zur Version von CiviCRM selbst bleiben unverändert.

Um einzelne Updates stummzuschalten, tragen Sie sie in `pulse_updates_ignore` ein:

| Eintrag | Wirkung |
|---|---|
| `org.example.foo` | Ignoriert diese Extension vollständig. |
| `org.example.foo@1.2.3` | Schaltet genau dieses Release stumm. Das nächste Release meldet sich wieder. |
| `extensions` | Ignoriert alle Updates von Extensions. |

Das Lesen des Extension-Verzeichnisses wird sechs Stunden lang zwischengespeichert. Schlägt es fehl, meldet `pulse_updates_feed` das mit dem Schweregrad error, und `pulse_updates` meldet weiter die Versionen des letzten erfolgreichen Lesens, damit ein Ausfall des Verzeichnisses nicht wie eine Erholung aussieht. Ein fehlgeschlagenes Lesen wird nach einer Stunde wiederholt.

## pulse_cms_updates

Der Check schlägt bei ausstehenden Updates des CMS fehl, in dem CiviCRM läuft. `pulse_cms_updates_mode` legt fest, was zählt:

| Modus | Wirkung |
|---|---|
| `security` | Schlägt nur bei Sicherheitsreleases fehl. Das ist der Standard. |
| `all` | Schlägt bei jedem ausstehenden Update fehl. |
| `off` | Schaltet den Check ab. |

Unter Drupal liest der Check die Projektdaten des Moduls update, die der Cron von Drupal selbst sammelt. Er wartet nie auf updates.drupal.org. Ohne das Modul update oder seine Daten wird der Check übersprungen.

Unter WordPress liest er die Transients update_core, update_plugins und update_themes. WordPress kennzeichnet keine Sicherheitsreleases, daher zählt pulse jedes ausstehende Core-Update als Sicherheitsrelease. Updates von Plugins und Themes zählen nur im Modus `all`.

Unter Standalone wird der Check übersprungen und zeigt keine Meldung. Das Ergebnis wird sechs Stunden lang zwischengespeichert.

## pulse_jobs

Der Check schlägt fehl, wenn ein aktiver geplanter Job nicht innerhalb seiner Ausführungshäufigkeit mal `pulse_jobs_grace_factor` gelaufen ist, auch wenn Cron insgesamt weiterläuft. Der Standardfaktor ist 3.

Ein zweiter Teil erkennt aktive Jobs, die noch nie gelaufen sind. Die Job-Tabelle hat kein Erstellungsdatum, daher hält pulse in `pulse_jobs_first_seen` fest, wann es jeden solchen Job zum ersten Mal gesehen hat. Der Check schlägt fehl, wenn ein Job `pulse_jobs_never_ran_grace_hours` später immer noch nicht gelaufen ist, standardmäßig 48. Jobs, die laufen oder gelöscht werden, fallen aus dieser Liste heraus. Jede der beiden Einstellungen schaltet nur ihren eigenen Teil ab.

## pulse_queues

Der Check schlägt bei Elementen fehl, die in den persistenten Queues von CiviCRM hängen. Ein Element gilt als hängend, wenn es länger als `pulse_queue_max_age_hours` in einer aktiven Queue gewartet hat, standardmäßig 6. Der Check schlägt außerdem fehl, wenn eine Queue im Status aborted ist. Das bedeutet, dass eine Aufgabe abgestürzt ist und die Verarbeitung angehalten hat.

0 schaltet nur den Teil zum Alter ab. Zurückgestellte Wiederholungen mit einer Freigabezeit in der Zukunft und Vorlagen-Queues bleiben außen vor. Unter einem CiviCRM ohne die Queue-Tabellen wird der Check übersprungen.

## pulse_payments

Der Check schlägt bei hängenden oder fehlgeschlagenen Webhooks von Zahlungsanbietern fehl. Er liest die Webhook-Tabelle der Extension mjwshared. Fehlt diese Tabelle, wird er übersprungen. Fehlen die Spalten, die der Check braucht, kann er nicht prüfen und meldet einen Fehler.

Er schlägt fehl, wenn ein Webhook länger als `pulse_payment_webhook_max_age_hours` im Status new oder processing bleibt, standardmäßig 2. Er schlägt auch fehl, wenn ein Webhook innerhalb der letzten 24 Stunden in den Status error gewechselt ist. 0 schaltet nur den Teil zum Alter ab.

## pulse_disk

Der Check schlägt fehl, wenn auf dem Dateisystem mit dem Verzeichnis ConfigAndLog von CiviCRM weniger Platz frei ist als `pulse_min_disk_free_percent`, standardmäßig 10 Prozent.

## pulse_host_status

Der Check meldet Jobs auf dem Host wie Datenbank-Dumps, Backups und Health-Checks. Der Host schreibt ihre Ergebnisse in eine Statusdatei, die schreibgeschützt in den CiviCRM-Container eingebunden ist. `pulse_host_status_file` enthält den Pfad dieser Datei. Solange die Einstellung leer ist, wird der Check übersprungen.

Der Check schlägt fehl, wenn

- die Datei nicht lesbar oder keine Statusdatei ist,
- die Datei selbst veraltet ist,
- der letzte Lauf eines Jobs fehlgeschlagen ist oder
- ein Job innerhalb seines Fensters keinen Erfolg hatte.

Deckt eine Datei mehrere CiviCRM-Instanzen ab, setzen Sie `pulse_host_status_environment` auf den Namen dieser Instanz. Bis dahin schlägt der Check fehl, statt für eine andere Instanz zu antworten. [Host-Status-Datei](host-status.md) beschreibt die Einrichtung, das Format und die Regeln für jedes Fenster.

## pulse_fatal_errors

Der Check schlägt bei fatalen PHP-Fehlern innerhalb der letzten `pulse_error_window` Minuten fehl, standardmäßig 60. Unter Drupal mit dem Modul dblog zählt er Watchdog-Einträge vom Typ php mit dem Schweregrad error oder schlimmer und liest sie über die Datenbankverbindung von Drupal selbst. Überall sonst durchsucht er das Ende kürzlich geänderter CiviCRM-Logdateien in ConfigAndLog nach Markierungen fataler Fehler.

Die Meldung verschwindet, sobald ein ganzes Fenster ohne neuen fatalen Fehler vergangen ist.

## pulse_civirules

Der Check schlägt bei einer CiviRules-Konfiguration fehl, die die Auswertung einer Regel abbrechen würde. Ist CiviRules nicht installiert, wird er übersprungen. In jeder aktiven Regel sucht er nach diesen Problemen:

- Eine aktive Bedingung nach der ersten hat keine Verknüpfung AND oder OR.
- Ein Trigger, eine Bedingung oder eine Aktion, auf die sich die Regel bezieht, fehlt, oder ihre Klasse lässt sich nicht laden.
- Der Trigger ist nicht aktiv.
- Gespeicherte Parameter lassen sich nicht lesen.
- Die Regel hat keine aktive Aktion.

Ein zweiter Teil zählt Logzeilen der Stufen error und critical, die CiviRules erwähnen, innerhalb der letzten `pulse_error_window` Minuten. Er erkennt Fehler zur Laufzeit, die die Prüfung der Konfiguration nicht sieht. Das Fenster teilt er sich mit pulse_fatal_errors, und 0 schaltet diesen Teil ab.

Ein Konfigurationsproblem verschwindet, sobald die Regel repariert ist, ein Logproblem, sobald das Fenster vergangen ist.

## pulse_duplicates

Der Check schlägt bei einer Häufung möglicher doppelter Kontakte fehl. Er zählt Kontakte, die innerhalb der letzten `pulse_duplicates_window_days` angelegt wurden, standardmäßig 7, und eine E-Mail-Adresse mit einem bestehenden Kontakt teilen. Ein Fenster von 0 oder weniger fällt auf 7 Tage zurück.

Was eine Häufung ist, hängt von der Seite ab. Der Check vergleicht die Zahl mit dem Normalwert pro Fenster, dem Median der zwölf vorherigen Fenster, und schlägt beim `pulse_duplicates_factor`-fachen dieses Normalwerts fehl, standardmäßig dem Dreifachen. Unter `pulse_duplicates_threshold`, standardmäßig 5, schlägt er nie fehl, damit eine neue Seite ohne Vorgeschichte nicht beim ersten Duplikat meldet. `pulse_duplicates_baseline` legt den Normalwert von Hand fest, statt ihn zu messen. Faktor 0 lässt nur den Schwellwert übrig. Inzwischen zusammengeführte Duplikate fallen aus der Vorgeschichte heraus, der gemessene Normalwert zählt also die, die niemand zusammengeführt hat.

Einige sind normal, denn die Dublettenprüfung legt zu Recht einen neuen Kontakt an, wenn die Namen nicht zusammenpassen. Eine Häufung bedeutet, dass ein Formular oder ein Import die Prüfung umgeht. Das Detail nennt bis zu fünf Paare von Kontakt-IDs, nie E-Mail-Adressen. Das sind personenbezogene Daten, und Statusmeldungen laufen über Monitoring-Kanäle.
