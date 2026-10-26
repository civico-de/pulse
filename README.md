# pulse

pulse is a CiviCRM extension that tells an uptime monitor whether a site is really alive. It adds a token-protected URL for Uptime Kuma, Nagios, Icinga or any other HTTP monitor. A successful answer means the web server, PHP, the CMS and CiviCRM booted, the database answered and cron ran recently.

pulse also reports problems that are not outages as messages on CiviCRM's system status page, which the [herald](https://github.com/civico-de/herald) extension can alert on. Its status checks cover pending updates, stuck jobs and queues, failed payment webhooks, disk space, PHP fatal errors, broken CiviRules configuration and duplicate contacts. A host can report backups and other host-side jobs to pulse through a status file.

pulse works on Standalone, Drupal and WordPress.

Documentation: https://civico-de.github.io/pulse/

## Requirements

The status checks need no other extension; alerts on them need herald. The supported CiviCRM and PHP versions are listed in `info.xml`.

## Install

Download the archive from the [releases page](https://github.com/civico-de/pulse/releases), unpack it into your extensions directory and enable it:

```bash
cv ext:enable pulse
```

A git checkout has no `vendor/` directory. Run `composer install --no-dev` in it first. Without it, every request fails once pulse is enabled.

## Example

The endpoint refuses every request until a token is set. Generate one and store it:

```bash
cv api4 Setting.set +v pulse_token="$(openssl rand -hex 24)"
```

Then poll the endpoint with the token:

```bash
curl -H 'X-Pulse-Token: <token>' https://example.org/civicrm/pulse
```

The answer is HTTP 200 when every check passes and 503 otherwise.

## Documentation

- [Getting started](https://civico-de.github.io/pulse/)
- [Endpoint](https://civico-de.github.io/pulse/endpoint/): the request, the status codes and the checks behind the answer
- [Status checks](https://civico-de.github.io/pulse/monitor/): the messages pulse adds to the system status
- [Host status file](https://civico-de.github.io/pulse/host-status/): the JSON format through which a host reports its jobs
- [Reference](https://civico-de.github.io/pulse/reference/): settings and translations

## Development

The dev stack and every CI gate run in the [civikitchen](https://github.com/civico-de/civikitchen) Docker image. pulse needs its `vendor/` directory first, so run `composer install` (PHP 8.2 or later), then:

```bash
cd .docker
docker compose up -d     # CiviCRM on http://localhost:8080, admin/admin
docker compose exec -w /var/www/html/ext/pulse app ck ci        # every CI gate
docker compose exec -w /var/www/html/ext/pulse app ckphpunit    # the test suite alone
```

With a civikitchen checkout next to this repository, `../civikitchen/scaffold/ckup` does the `composer install` in the image, starts the stack on free host ports and prints its URL. `../civikitchen/scaffold/ckx ck ci` runs a command in it.

The tests install themselves into a separate test database, so the dev site stays untouched. CI runs the same gates on the same image. The coverage floor is `coverage.minimum` in `civikitchen.yaml`.

The German translation of the message titles and details lives in `l10n/de_DE/LC_MESSAGES/pulse.po`. CiviCRM reads only the compiled catalogue, so recompile it in that directory after every edit:

```bash
msgfmt --statistics -o pulse.mo pulse.po
```

To see a failing check on a dev site, run the demo seed:

```bash
cv scr demo/seed.php
```

It creates two marked contacts that share one address and makes the `pulse_duplicates` message appear. To do so, it lowers the duplicate threshold to 1 and widens the window to 3650 days. Running it again reuses both contacts. It prints JSON that names the message and its expected severity.

## License and support

AGPL-3.0-or-later. © civico GmbH. See [LICENSE.txt](LICENSE.txt). Report bugs in the [issue tracker](https://github.com/civico-de/pulse/issues). Report security issues by email to security@civico.de, not in a public issue; see [SECURITY.md](SECURITY.md).
