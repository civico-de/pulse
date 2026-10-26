---
title: Reference
order: 40
---

# Reference

## Settings

All settings are per domain. Set them through the API, for example with `cv api4 Setting.set +v <name>=<value>`.

| Setting | Default | Used by | Effect |
|---|---|---|---|
| `pulse_token` | empty | endpoint | The token a poller must present. While it is empty, the endpoint answers `unconfigured`. |
| `pulse_max_cron_age` | 60 | cron | Minutes since the last cron run before the check fails. 0 turns it off. |
| `pulse_min_severity` | `critical` | system | The lowest system status severity that fails the check. `off` turns it off. |
| `pulse_updates_min_severity` | `warning` | pulse_updates | The severity of the message. `off` turns the check off and shows core's own message about extension updates again. |
| `pulse_updates_ignore` | empty list | pulse_updates | Updates not to report, see [pulse_updates](monitor.md#pulseupdates). |
| `pulse_updates_remote_versions` | empty | pulse_updates | The versions of the last successful read of the extension directory. Maintained by pulse. Do not edit it. |
| `pulse_cms_updates_mode` | `security` | pulse_cms_updates | `security`, `all` or `off`. |
| `pulse_jobs_grace_factor` | 3 | pulse_jobs | An active job counts as late after this many run intervals without a run. 0 turns off this part. |
| `pulse_jobs_never_ran_grace_hours` | 48 | pulse_jobs | Hours an active job may stay never-run. 0 turns off this part. |
| `pulse_jobs_first_seen` | empty | pulse_jobs | Maintained by pulse. Do not edit it. |
| `pulse_queue_max_age_hours` | 6 | pulse_queues | Hours a queue item may wait. 0 turns off this part. |
| `pulse_payment_webhook_max_age_hours` | 2 | pulse_payments | Hours a webhook may stay unprocessed. 0 turns off this part. |
| `pulse_min_disk_free_percent` | 10 | pulse_disk | Minimum free space in percent. 0 turns it off. |
| `pulse_host_status_file` | empty | pulse_host_status | Path of the [host status file](host-status.md) inside the container. Empty turns the check off. |
| `pulse_host_status_environment` | empty | pulse_host_status | This instance's name in the file's `environments`. |
| `pulse_host_status_max_age` | 26 | pulse_host_status | Hours a job may go without success, and the base for the file's freshness. 0 or less falls back to 26. |
| `pulse_error_window` | 60 | pulse_fatal_errors, pulse_civirules | Minutes to look back for fatal errors and CiviRules log errors. 0 turns off pulse_fatal_errors and the log part of pulse_civirules. |
| `pulse_duplicates_threshold` | 5 | pulse_duplicates | Lowest number of likely duplicates that fails the check. 0 turns it off. |
| `pulse_duplicates_factor` | 3 | pulse_duplicates | Multiple of the normal per window that fails the check. 0 leaves only the threshold. |
| `pulse_duplicates_baseline` | 0 | pulse_duplicates | Normal number of duplicates per window. 0 measures it as the median of the previous twelve windows. |
| `pulse_duplicates_window_days` | 7 | pulse_duplicates | Days of new contacts to consider. 0 or less falls back to 7. |

## Translations

pulse ships a German translation of its message titles and details, so the system status page and herald alerts on a German site read fully in German. This needs CiviCRM's own language files for that locale. Without them, the messages stay in English.

Message names such as `pulse_duplicates` and the endpoint's `status` values are identifiers. They are never translated.
