---
title: Status checks
order: 20
---

# Status checks

Some problems are not outages but still need attention, such as a pending security update, a stuck queue or a backup that did not run. pulse reports them as messages on CiviCRM's system status page. Each check has its own message name, such as `pulse_jobs`, and a healthy check shows no message. An admin can hide a message on the status page like any core message.

With the herald extension installed, its [check monitor](https://civico-de.github.io/herald/monitor/) reads the same messages from the system status. herald alerts when a message appears and when it clears again.

The checks are not part of the [endpoint](endpoint.md), because a pending update is not an outage. The endpoint stays the signal for an external poller, because a site that is down cannot report its own failure. Whether cron runs is the endpoint's question for the same reason: a check that runs on cron cannot report that cron stopped.

Every threshold setting below treats 0 as off, unless its section says otherwise. [Reference](reference.md#settings) lists all settings with their defaults.

A check that cannot check reports a message at severity error, saying what failed. It does not stay silent.

| Message | Severity |
|---|---|
| `pulse_database` | critical |
| `pulse_updates` | set by `pulse_updates_min_severity`, `warning` by default |
| `pulse_updates_feed` | error |
| `pulse_cms_updates` | error for security releases, warning otherwise |
| `pulse_jobs`, `pulse_queues`, `pulse_disk`, `pulse_duplicates` | warning |
| `pulse_payments`, `pulse_host_status`, `pulse_fatal_errors`, `pulse_civirules` | error |

## pulse_database

The message appears when a simple query against CiviCRM's database fails.

## pulse_updates

The message lists installed extensions with a newer release. pulse compares each installed extension with the extension directory that CiviCRM reads for its extension page. `pulse_updates_min_severity` sets the severity of the message, `warning` by default. The value `off` turns the check off.

While the check is on, pulse hides core's own message about extension updates, `checkExtensionsUpdates`. That message lists every update in one block, so hiding it hides every future update too. pulse keeps a status preference for it that hides it at severity warning. The preference exists only while the check is on: with `off`, or when pulse is disabled or uninstalled, core's message shows again. Core's messages about CiviCRM's own version stay as they are.

To silence single updates, add entries to `pulse_updates_ignore`:

| Entry | Effect |
|---|---|
| `org.example.foo` | Ignores that extension entirely. |
| `org.example.foo@1.2.3` | Snoozes exactly that release. The next release alerts again. |
| `extensions` | Ignores all extension updates. |

The read of the extension directory is cached for six hours. When it fails, `pulse_updates_feed` reports the failure at severity error, and `pulse_updates` keeps reporting the versions of the last successful read, so an outage of the directory does not look like a recovery. A failed read is retried after an hour.

## pulse_cms_updates

The message appears on pending updates of the CMS that runs CiviCRM. `pulse_cms_updates_mode` decides what counts:

| Mode | Effect |
|---|---|
| `security` | Fails only on security releases. This is the default. |
| `all` | Fails on any pending update. |
| `off` | Turns the check off. |

On Drupal, the check reads the project data of the update module, which Drupal's own cron collects. It never waits for updates.drupal.org. Without the update module or its data, the check is skipped.

On WordPress, it reads the update_core, update_plugins and update_themes transients. WordPress does not mark security releases, so pulse counts every pending core update as one. Plugin and theme updates count only in mode `all`.

On Standalone, the check is skipped and shows no message. The result is cached for six hours.

## pulse_jobs

The message appears when an active scheduled job has not run within its run frequency times `pulse_jobs_grace_factor`, even while cron as a whole keeps running. The default factor is 3.

A second part catches active jobs that have never run. The job table has no creation date, so pulse records in `pulse_jobs_first_seen` when it first saw each such job. The message appears when a job has still not run `pulse_jobs_never_ran_grace_hours` later, 48 by default. Jobs that run or are deleted drop out of that record. Each of the two settings turns off only its own part.

Outside the Production environment, CiviCRM runs only jobs with the parameter `runInNonProductionEnvironment` and refuses the rest. pulse judges neither part for a refused job, so a staging copy reports no late jobs.

## pulse_queues

The message appears on items stuck in CiviCRM's persistent queues. An item counts as stuck when it has waited in an active queue longer than `pulse_queue_max_age_hours`, 6 by default. The check also fails when a queue is in status aborted, which means a task died and processing stopped.

0 turns off only the age part. Deferred retries with a future release time and template queues are left out. On a CiviCRM without the queue tables, the check is skipped.

## pulse_payments

The message appears on stuck or failed payment processor webhooks. It reads the webhook table of the mjwshared extension. Without that table, it is skipped. Without the columns the check needs, it cannot check and reports an error.

It fails when a webhook stays in new or processing longer than `pulse_payment_webhook_max_age_hours`, 2 by default. It also fails when a webhook moved to error within the last 24 hours. 0 turns off only the age part.

## pulse_disk

The message appears when the filesystem that holds CiviCRM's ConfigAndLog directory has less free space than `pulse_min_disk_free_percent`, 10 percent by default.

## pulse_host_status

The check reports host-side jobs such as database dumps, backups and health runs. The host writes their outcomes to a status file, which is mounted read-only into the CiviCRM container. `pulse_host_status_file` holds the path of that file. While the setting is empty, the check is skipped.

The message appears when

- the file cannot be read or is not a status file,
- the file itself is stale,
- a job's last run failed, or
- a job has no success within its window.

When one file covers several CiviCRM instances, set `pulse_host_status_environment` to this instance's name. Until then, the check fails rather than answer for another instance. [Host status file](host-status.md) describes the setup, the format and the rules for each window.

## pulse_fatal_errors

The message appears on PHP fatal errors within the last `pulse_error_window` minutes, 60 by default. On Drupal with the dblog module, it counts watchdog entries of type php at severity error or worse, read through Drupal's own database connection. Everywhere else, it scans the end of recently changed CiviCRM log files in ConfigAndLog for fatal error markers.

The message clears once a whole window has passed without a new fatal error.

## pulse_civirules

The message appears on CiviRules configuration that would abort the evaluation of a rule. It is skipped when CiviRules is not installed. For every active rule, it looks for these problems:

- An active condition after the first has no AND or OR link.
- A trigger, condition or action the rule refers to is missing, or its class does not load.
- The trigger is not active.
- Stored parameters cannot be read.
- The rule has no active action.

A second part counts error and critical log lines that mention CiviRules within the last `pulse_error_window` minutes. It catches failures at runtime that the inspection cannot see. The window is shared with pulse_fatal_errors, and 0 turns off this part.

A configuration problem clears once the rule is fixed, a log problem once the window has passed.

## pulse_duplicates

The message appears on a spike of possible duplicate contacts. It counts contacts created within the last `pulse_duplicates_window_days`, 7 by default, that share an email address with an existing contact. A window of 0 or less falls back to 7 days.

What counts as a spike depends on the site. The check compares the count with the normal per window, the median of the previous twelve windows, and fails at `pulse_duplicates_factor` times that normal, 3 by default. It never fails below `pulse_duplicates_threshold`, 5 by default, so a new site without history does not alert on its first duplicate. `pulse_duplicates_baseline` sets the normal by hand instead of measuring it. Factor 0 leaves only the threshold. Duplicates that were merged since drop out of the history, so the measured normal counts the ones nobody merged.

A few are normal, because duplicate matching rightly creates a new contact when names conflict. A spike means that a form or an import bypasses the matching. The detail names up to five pairs of contact IDs, never email addresses. Those are personal data, and status messages travel through monitoring channels.
