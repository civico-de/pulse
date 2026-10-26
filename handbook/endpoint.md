---
title: Endpoint
order: 10
---

# Endpoint

An uptime monitor polls one URL and reads the HTTP status code. A 200 proves that the web server, PHP, the CMS and the CiviCRM bootstrap work. It also proves that the database answers and that cron ran recently, unless the cron check is turned off.

## Request

```text
GET /civicrm/pulse
X-Pulse-Token: <token>
```

Send the token in the `X-Pulse-Token` header. A monitor that cannot set headers can pass it as the query parameter `?token=<token>` instead. The header is safer, because query strings end up in the access logs of proxies and web servers. When both are present, the header wins.

The token cannot travel in an `Authorization: Bearer` header. CiviCRM's authx extension reads that header on every request and answers 401 for a token it does not know, before pulse runs.

## Response

The body is JSON. Its `status` field always agrees with the HTTP status code:

| HTTP | `status` | Meaning |
|---|---|---|
| 200 | `ok` | All checks passed. |
| 503 | `fail` | At least one check failed. |
| 503 | `unconfigured` | The `pulse_token` setting is empty, so the endpoint refuses every request. |
| 403 | `forbidden` | The token is wrong or missing. |

An example answer:

```json
{"status":"ok","checks":{"database":{"status":"ok"},"cron":{"status":"ok","last_run":"2026-07-14 21:40:02","age_minutes":3,"max_age_minutes":60},"system":{"status":"ok","min_severity":"critical"},"host_status":{"status":"skipped"}}}
```

## Checks

Three checks decide the HTTP status. A fourth one only reports.

### database

pulse sends a trivial query to the CiviCRM database. The check fails when the query fails.

### cron

The check fails when the newest entry in CiviCRM's scheduled job log is older than `pulse_max_cron_age` minutes. The default is 60, and 0 turns the check off. Every cron run writes an entry, even when no job is due, so the check shows whether cron runs at all. Whether each job runs on time is the task of the [pulse_jobs](monitor.md#pulsejobs) status check.

### system

The check fails when CiviCRM's own system status, the list on the "System Status" screen, shows a visible message at or above `pulse_min_severity`. The default is `critical`. The value `off` turns the check off, and an empty value falls back to the default.

Some of the underlying checks are expensive. pulse therefore caches a passing result for five minutes and a failing one for one minute, so a new problem shows up with a few minutes' delay.

### host_status

This check never changes the HTTP status, because a late backup is not an outage. It carries the result of the [pulse_host_status](monitor.md#pulsehoststatus) status check, so a poller can show the age of the last backup without any access to the host. It reports `skipped` while `pulse_host_status_file` is empty.

When pulse finds the jobs it judges, the result also carries the file's `updated` timestamp and a `jobs` map. For each job, the map holds `status`, `finished`, `duration_seconds` and `expected_interval_hours`. A failing result's `detail` names the failed jobs together with their own `detail` text.
