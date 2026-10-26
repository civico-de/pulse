---
title: pulse
---

# pulse

pulse tells an uptime monitor whether a CiviCRM site is really alive. It adds a token-protected URL that Uptime Kuma, Nagios, Icinga or any other HTTP monitor can poll. A successful answer proves more than a static page could. The web server, PHP, the CMS and CiviCRM booted, the database answered, and cron ran recently.

pulse also reports problems that are not outages as messages on CiviCRM's system status page. Its status checks cover pending updates, stuck scheduled jobs and queues, failed payment webhooks, low disk space, PHP fatal errors, broken CiviRules configuration, duplicate contacts and host-side backups.

pulse works on Standalone, Drupal and WordPress. It registers its URL through CiviCRM's own menu system, so no CMS needs extra setup.

## Requirements

The status checks need no other extension. To be alerted when a message appears, install [herald](https://civico-de.github.io/herald/), whose check monitor reads the system status. The supported CiviCRM and PHP versions are listed in the extension's `info.xml`.

## Install

Download the release archive from the [releases page](https://github.com/civico-de/pulse/releases), unpack it into your CiviCRM extensions directory and enable it:

```bash
cv ext:enable pulse
```

## First steps

The endpoint refuses every request until a token is set. Generate one and store it:

```bash
cv api4 Setting.set +v pulse_token="$(openssl rand -hex 24)"
```

Point your monitor at `https://example.org/civicrm/pulse`, with your site's address in place of example.org. Send the token in the `X-Pulse-Token` header and expect HTTP 200. Uptime Kuma's HTTP(s) monitor accepts the status codes 200 to 299 by default, so it needs no further setup.

By default, the endpoint fails when cron has not run for 60 minutes. To allow a longer gap, set the limit in minutes:

```bash
cv api4 Setting.set +v pulse_max_cron_age=120
```

Treat the token like any credential. Use HTTPS, and rotate the token if it leaks. pulse compares it in constant time. If the site sits behind a reverse proxy that admits only known addresses, keep that restriction. The token is a second layer, not a replacement.

## Where to go next

- [Endpoint](endpoint.md) describes the request, the status codes and the checks behind the answer.
- [Status checks](monitor.md) lists the messages pulse adds to the system status.
- [Host status file](host-status.md) documents the file through which a host reports backups and other host-side jobs.
- [Reference](reference.md) lists every setting and explains the translations.
