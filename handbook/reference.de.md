---
title: Referenz
order: 40
---

# Referenz

## Einstellungen

Alle Einstellungen gelten pro Domain. Sie setzen sie über die API, zum Beispiel mit `cv api4 Setting.set +v <name>=<value>`.

| Einstellung | Standard | Genutzt von | Wirkung |
|---|---|---|---|
| `pulse_token` | leer | Endpunkt | Das Token, das ein Poller vorweisen muss. Solange es leer ist, antwortet der Endpunkt mit `unconfigured`. |
| `pulse_max_cron_age` | 60 | cron | Minuten seit dem letzten Cron-Lauf, bis der Check fehlschlägt. 0 schaltet ihn ab. |
| `pulse_min_severity` | `critical` | system | Der niedrigste Schweregrad im Systemstatus, der den Check fehlschlagen lässt. `off` schaltet ihn ab. |
| `pulse_updates_min_severity` | `warning` | pulse_updates | Der Schweregrad der Meldung. `off` schaltet den Check ab und zeigt die Core-Meldung zu Extension-Updates wieder an. |
| `pulse_updates_ignore` | leere Liste | pulse_updates | Updates, die nicht gemeldet werden sollen, siehe [pulse_updates](monitor.md#pulseupdates). |
| `pulse_updates_remote_versions` | leer | pulse_updates | Die Versionen des letzten erfolgreichen Lesens des Extension-Verzeichnisses. Wird von pulse verwaltet. Bearbeiten Sie sie nicht. |
| `pulse_cms_updates_mode` | `security` | pulse_cms_updates | `security`, `all` oder `off`. |
| `pulse_jobs_grace_factor` | 3 | pulse_jobs | Ein aktiver Job gilt als verspätet, wenn er so viele Laufintervalle lang nicht gelaufen ist. 0 schaltet diesen Teil ab. |
| `pulse_jobs_never_ran_grace_hours` | 48 | pulse_jobs | Stunden, die ein aktiver Job ohne jeden Lauf bleiben darf. 0 schaltet diesen Teil ab. |
| `pulse_jobs_first_seen` | leer | pulse_jobs | Wird von pulse verwaltet. Bearbeiten Sie sie nicht. |
| `pulse_queue_max_age_hours` | 6 | pulse_queues | Stunden, die ein Element in einer Queue warten darf. 0 schaltet diesen Teil ab. |
| `pulse_payment_webhook_max_age_hours` | 2 | pulse_payments | Stunden, die ein Webhook unverarbeitet bleiben darf. 0 schaltet diesen Teil ab. |
| `pulse_min_disk_free_percent` | 10 | pulse_disk | Mindestens freier Speicherplatz in Prozent. 0 schaltet den Check ab. |
| `pulse_host_status_file` | leer | pulse_host_status | Pfad der [Host-Status-Datei](host-status.md) im Container. Leer schaltet den Check ab. |
| `pulse_host_status_environment` | leer | pulse_host_status | Der Name dieser Instanz in den `environments` der Datei. |
| `pulse_host_status_max_age` | 26 | pulse_host_status | Stunden, die ein Job ohne Erfolg bleiben darf, und die Grundlage für die Aktualität der Datei. 0 oder weniger fällt auf 26 zurück. |
| `pulse_error_window` | 60 | pulse_fatal_errors, pulse_civirules | Minuten, die nach fatalen Fehlern und CiviRules-Fehlern im Log zurückgeschaut wird. 0 schaltet pulse_fatal_errors und den Log-Teil von pulse_civirules ab. |
| `pulse_duplicates_threshold` | 5 | pulse_duplicates | Kleinste Anzahl wahrscheinlicher Duplikate, ab der der Check fehlschlägt. 0 schaltet ihn ab. |
| `pulse_duplicates_factor` | 3 | pulse_duplicates | Vielfaches des Normalwerts pro Fenster, ab dem der Check fehlschlägt. 0 lässt nur den Schwellwert übrig. |
| `pulse_duplicates_baseline` | 0 | pulse_duplicates | Normale Anzahl Duplikate pro Fenster. 0 misst sie als Median der zwölf vorherigen Fenster. |
| `pulse_duplicates_window_days` | 7 | pulse_duplicates | Tage, aus denen neue Kontakte berücksichtigt werden. 0 oder weniger fällt auf 7 zurück. |

## Übersetzungen

pulse bringt eine deutsche Übersetzung der Titel und Details seiner Meldungen mit. Auf einer deutschsprachigen Site lesen sich die Seite Systemstatus und die Meldungen von herald daher vollständig auf Deutsch. Dafür braucht CiviCRM seine eigenen Sprachdateien für diese Locale. Ohne sie bleiben die Meldungen auf Englisch.

Meldungsnamen wie `pulse_duplicates` und die Werte von `status` am Endpunkt sind Bezeichner. Sie werden nie übersetzt.
