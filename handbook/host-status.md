---
title: Host status file
order: 30
---

# Host status file

Some health signals live outside CiviCRM, such as last night's database dump, the off-site backup or the host's own health run. CiviCRM cannot see them and should not get access to the systems that produce them. The host status file passes their outcomes in one direction only. A process on the host writes a small JSON file of job outcomes. The file is mounted read-only into the CiviCRM container, and the [pulse_host_status](monitor.md#pulsehoststatus) check reads it.

Any host can write the file. This page describes the format pulse expects.

## Setup

1. Have the host write the file after every job run, as described in [Writing the file](#writing-the-file).
2. Mount the directory that holds the file read-only into the CiviCRM container. Mount the directory, not the file itself. A file replaced by renaming gets a new inode, and a bind mount of the file keeps showing the old one.
3. Store the path as the container sees it:

   ```bash
   cv api4 Setting.set +v pulse_host_status_file=/var/lib/host-status/status.json
   ```

4. If the file lists several environments, tell pulse which one this instance is:

   ```bash
   cv api4 Setting.set +v pulse_host_status_environment=production
   ```

## Format

The file is one JSON object:

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

### Top level

| Key | Type | Required | Meaning |
|---|---|---|---|
| `updated` | timestamp | yes | When the file was last written. A missing or unreadable value counts as stale. |
| `jobs` | object of jobs | yes | Job outcomes for the whole file. It may be empty. A file without it is rejected as invalid. |
| `environments` | object | no | One entry per environment, keyed by environment name. Each entry is an object with its own `jobs` object. |

### Job

Each key in a `jobs` object is a job name, chosen by the producer, such as dump or backup. The name appears in alerts. Its value is an object:

| Key | Type | Required | Meaning |
|---|---|---|---|
| `status` | string | no | `ok` or `error`, the outcome of the last run. An `error` fails the check. Any other value, or none, is judged by `finished` alone. |
| `finished` | timestamp | yes | When the last run ended. For an `ok` job this is the last success. A missing or unreadable value counts as no success. |
| `duration_seconds` | number | no | How long the last run took. pulse only passes it on to pollers. |
| `expected_interval_hours` | number | no | How often the job is meant to run. A positive value widens the job's window, see [Rules](#rules). |
| `detail` | string | no | A short explanation of a failure. It becomes part of the check's detail, which reaches the alert and the endpoint. Keep it free of secrets, paths and personal data. |

Entries that are not objects are ignored, and so are other keys.

pulse reads timestamps with PHP's `strtotime()`. Write ISO 8601 with an explicit offset, such as `2026-10-13T03:12:40Z`. Without an offset, the time is read in the server's PHP time zone.

## Which jobs are judged

- Without an `environments` object, pulse judges the top-level `jobs`.
- With an `environments` object, pulse judges only the jobs of the environment named in `pulse_host_status_environment`. If that setting is empty, the check fails instead of guessing. It also fails when the named environment is missing or has no jobs.

A job named `health` is never judged. It stands for the host's own health run, which reads this check, so judging it would keep one failed run failing.

A host that runs a single instance can write `jobs` alone. A host that serves several instances from one file writes `environments` and gives each instance its name. The top-level `jobs` must still be present in that case, even if it is empty.

## Rules

The base window is `pulse_host_status_max_age` hours, 26 by default. A value of 0 or less falls back to 26.

- A job with a positive `expected_interval_hours` gets a window of one and a half times that interval, rounded up to whole hours. That window is never shorter than the base window. Every other job gets the base window.
- A job whose `status` is `error` fails the check, with its `detail` in the alert. Its age is not judged on top.
- Any other job fails the check when its `finished` time lies further back than its window.
- The file fails the check when `updated` lies further back than the smallest window among the judged jobs. Without judged jobs, the base window applies. The host therefore has to rewrite the file at least as often as its most frequent job runs.

pulse reports all problems together in one detail, separated by semicolons.

## Writing the file

- Write the whole file on every job run. Replace it atomically by writing a temporary file and renaming it. A half-written file reads as invalid and fails the check.
- Record each job's last outcome, not a history. A later success replaces an earlier error.
- Keep the file to metadata. It is readable from inside the CiviCRM container, so it must not contain paths to backups, repository addresses or credentials.
