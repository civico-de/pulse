# pulse

Token-protected `/civicrm/pulse` health endpoint plus maintenance-grade checks
reported as core status messages `pulse_<check>` via `hook_civicrm_check`
(`CRM_Pulse_StatusCheck`); herald's monitor reads them from `System.check`.

## Contracts to preserve

- Division of labour: the endpoint is the dead-man signal for an external
  poller (database/cron/system only); maintenance checks (updates, stuck
  jobs/queues/webhooks, disk, host status, fatals) are status messages only —
  a pending update is not an outage and must never fail the endpoint, so the
  endpoint's system check ignores `pulse_*` messages.
- Status messages: healthy means no message; a check that cannot check
  reports an error message, never silence. `pulse_updates` reads the extension
  feed directly (never `System.check`) and hides core's
  `checkExtensionsUpdates` through `managed/StatusPreference.mgd.php` exactly
  while `pulse_updates_min_severity` is not `off`. On disable, core only sets
  the preference inactive and visibility ignores `is_active`, so
  `pulse_civicrm_disable` sets its `ignore_severity` to 0.
- The endpoint refuses everything until `pulse_token` is set (503
  `unconfigured`); token comparison stays `hash_equals()`; `X-Pulse-Token`
  wins over the legacy `?token=` query parameter. Never read `Authorization`:
  authx claims that header and 401s before the route runs.
- Status codes are a monitoring API: 200 `ok`, 503 `fail`/`unconfigured`,
  403 `forbidden`. Do not change them.
- Every check's threshold setting treats `0` (or `off`) as "disabled" — keep
  that convention for new checks.
- The host status check reads one host-mounted metadata JSON, read-only; the
  app must never gain a path to backups, credentials, or the host tooling.
  Its format is pulse's own, documented in `handbook/host-status.md` without
  naming any producer; a change to it is a change to that page.
- Route registration goes through CiviCRM's menu system so Standalone,
  Drupal, and WordPress all work without CMS glue.
- Bundled packages (`civico-de/stdlib`) are prefixed into `Civi\Pulse\Vendor\`
  by Strauss as composer's post-install script (`.civikitchen/strauss.php`).
  `vendor/` is not committed; `pulse.php` requires `vendor/autoload.php`, so
  CI, release and deploy all run `composer install`.

## Verify

`composer install` first (vendor/ is not committed), then in the civikitchen
image:

```bash
cd .docker && docker compose up -d
docker compose exec -w /var/www/html/ext/pulse app ck ci   # every CI gate, as CI runs them
```

The test bootstrap fails loudly if `TEST_DB_DSN` (`~/.cv.json`, provisioned
by the civikitchen entrypoint) is missing — without it Civi\Test would wipe
the dev DB. Do not weaken that guard. Check semantics, per-check settings,
and cache windows (endpoint System.check 5 min, extension feed 6 h) are documented in
`handbook/`, which is published as the documentation site.
