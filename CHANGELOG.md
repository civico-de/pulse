# Changelog

## [Unreleased]

## [0.10.2] - 2026-10-26

### Fixed

- The `pulse_jobs` check no longer reports jobs as late or never run outside the Production
  environment when CiviCRM refuses to run them there.

## [0.10.1] - 2026-10-26

### Fixed

- The host status check never judges a job named `health`, the host's own health run, so one
  failed run no longer keeps the check failing.

## [0.10.0] - 2026-10-26

### Added

- A token-protected endpoint at `civicrm/pulse` for HTTP uptime monitors: HTTP 200 when the web
  server, PHP, the CMS, CiviCRM and the database answer and cron ran recently, 503 otherwise.
- Status checks on the system status page for pending updates, stuck jobs and queues, failed
  payment webhooks, disk space, PHP fatal errors, broken CiviRules configuration and duplicate
  contacts.
- A host status file through which a host reports backups and other host-side jobs.
- Support for Standalone, Drupal and WordPress.

[Unreleased]: https://github.com/civico-de/pulse/compare/v0.10.2...HEAD
[0.10.2]: https://github.com/civico-de/pulse/compare/v0.10.1...v0.10.2
[0.10.1]: https://github.com/civico-de/pulse/compare/v0.10.0...v0.10.1
[0.10.0]: https://github.com/civico-de/pulse/releases/tag/v0.10.0
