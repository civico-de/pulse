<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the check helpers — no Civi headless needed.
 * @group unit
 */
class CRM_Pulse_Check_ChecksTest extends TestCase {

  public function testCmsVerdictSecurityAlwaysFails(): void {
    $r = CRM_Pulse_Check_CmsUpdates::verdict('security', ['drupal'], ['token']);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString('security: drupal', $r['detail']);
    $this->assertStringContainsString('updates: token', $r['detail']);
  }

  public function testCmsVerdictOrdinaryUpdatesDependOnMode(): void {
    $this->assertSame('ok', CRM_Pulse_Check_CmsUpdates::verdict('security', [], ['token'])['status']);
    $this->assertSame('fail', CRM_Pulse_Check_CmsUpdates::verdict('all', [], ['token'])['status']);
    $this->assertSame('ok', CRM_Pulse_Check_CmsUpdates::verdict('all', [], [])['status']);
  }

  public function testUpdateIgnoreListByKeyAndPinnedVersion(): void {
    $updates = [
      'org.example.a' => ['from' => '1.0', 'to' => '1.1'],
      'org.example.b' => ['from' => '2.0', 'to' => '2.5'],
      'org.example.c' => ['from' => '3.0', 'to' => '3.1'],
    ];
    $left = CRM_Pulse_Check_Updates::filterIgnored($updates, ['org.example.a', 'org.example.b@2.5']);
    $this->assertSame(['org.example.c'], array_keys($left));
    // A snoozed version stops matching once a newer release appears.
    $updates['org.example.b']['to'] = '2.6';
    $left = CRM_Pulse_Check_Updates::filterIgnored($updates, ['org.example.a', 'org.example.b@2.5']);
    $this->assertArrayHasKey('org.example.b', $left);
  }

  public function testHostStatusEvaluation(): void {
    $now = strtotime('2026-07-23 12:00:00');
    $fresh = [
      'updated' => '2026-07-23 11:00:00',
      'jobs' => [
        'dump' => ['status' => 'ok', 'finished' => '2026-07-23 03:00:00'],
        'backup' => ['status' => 'ok', 'finished' => '2026-07-23 04:00:00'],
      ],
    ];
    $this->assertSame('ok', CRM_Pulse_Check_HostStatus::evaluate($fresh, $now, 26)['status']);

    $failed = $fresh;
    $failed['jobs']['backup'] = [
      'status' => 'error',
      'finished' => '2026-07-23 04:00:00',
      'detail' => 'restic exited 1',
    ];
    $r = CRM_Pulse_Check_HostStatus::evaluate($failed, $now, 26);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString('backup failed: restic exited 1', $r['detail']);

    $stale = $fresh;
    $stale['jobs']['dump']['finished'] = '2026-07-20 03:00:00';
    $r = CRM_Pulse_Check_HostStatus::evaluate($stale, $now, 26);
    $this->assertStringContainsString('dump: no success in 26h', $r['detail']);

    $dead = $fresh;
    $dead['updated'] = '2026-07-20 00:00:00';
    $this->assertStringContainsString(
      'status file stale',
      CRM_Pulse_Check_HostStatus::evaluate($dead, $now, 26)['detail'],
    );
  }

  public function testHostStatusPerJobIntervalWindow(): void {
    $now = strtotime('2026-07-23 12:00:00');
    // A weekly backup finished 4 days ago: stale under the global 26h window,
    // fine under its own expected_interval_hours (168 → window 252h).
    $data = [
      'updated' => '2026-07-23 11:00:00',
      'jobs' => [
        'weekly_backup' => [
          'status' => 'ok',
          'finished' => '2026-07-19 03:00:00',
          'expected_interval_hours' => 168,
        ],
      ],
    ];
    $this->assertSame('ok', CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26)['status']);

    // Past even 1.5× its own interval → fail, and the window in the message is
    // the per-job one, not the global.
    $data['jobs']['weekly_backup']['finished'] = '2026-07-01 03:00:00';
    $r = CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString('weekly_backup: no success in 252h', $r['detail']);

    // A frequent job never gets a window tighter than the global one.
    $data2 = [
      'updated' => '2026-07-23 11:00:00',
      'jobs' => [
        'hourly' => ['status' => 'ok', 'finished' => '2026-07-22 08:00:00', 'expected_interval_hours' => 1],
      ],
    ];
    $r = CRM_Pulse_Check_HostStatus::evaluate($data2, $now, 26);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString('no success in 26h', $r['detail']);
  }

  public function testHostStatusFileWindowFollowsSlowestOnlyJob(): void {
    // Only a weekly job exists: the file legitimately goes quiet for up to
    // that job's window — file staleness must not fire under the global 26h.
    $now = strtotime('2026-07-23 12:00:00');
    $data = [
      'updated' => '2026-07-19 03:00:00',
      'jobs' => [
        'weekly_backup' => ['status' => 'ok', 'finished' => '2026-07-19 03:00:00', 'expected_interval_hours' => 168],
      ],
    ];
    $this->assertSame('ok', CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26)['status']);
    // An entry that is not a job is ignored and does not tighten the window.
    $data['jobs']['note'] = 'written by hand';
    $this->assertSame('ok', CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26)['status']);
    unset($data['jobs']['note']);
    // With an additional hourly-ish job the file window tightens back to global.
    $data['jobs']['dump'] = ['status' => 'ok', 'finished' => '2026-07-23 11:00:00', 'expected_interval_hours' => 1];
    $r = CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString('status file stale (not updated in 26h)', $r['detail']);
  }

  /**
   * The status file is host-wide: every tenant container mounts the same one,
   * so an instance may only judge the environment it actually is.
   */
  public function testHostStatusJudgesOnlyItsOwnEnvironment(): void {
    $now = strtotime('2026-07-23 12:00:00');
    $data = [
      'updated' => '2026-07-23 11:00:00',
      // Host-wide map: last writer wins, so it says nothing about "alpha".
      'jobs' => ['dump' => ['status' => 'error', 'finished' => '2026-07-23 04:00:00', 'detail' => 'restic exited 1']],
      'environments' => [
        'alpha' => ['jobs' => ['dump' => ['status' => 'ok', 'finished' => '2026-07-23 03:00:00']]],
        'beta' => [
          'jobs' => ['dump' => [
            'status' => 'error',
            'finished' => '2026-07-23 04:00:00',
            'detail' => 'restic exited 1',
          ]],
        ],
      ],
    ];
    // The neighbour's failure is not this instance's outage …
    self::assertSame('ok', CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26, 'alpha')['status']);
    // … and it is still a real finding for the instance it belongs to.
    $beta = CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26, 'beta');
    self::assertSame('fail', $beta['status']);
    self::assertStringContainsString('dump failed: restic exited 1', $beta['detail']);
    // Nor does a neighbour's success cover this instance's stale job.
    $data['environments']['alpha']['jobs']['dump']['finished'] = '2026-07-19 03:00:00';
    $alpha = CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26, 'alpha');
    self::assertSame('fail', $alpha['status']);
    self::assertStringContainsString('dump: no success in 26h', $alpha['detail']);
  }

  public function testHostStatusFailsLoudlyWhenItCannotSayWhichEnvironmentItIs(): void {
    $now = strtotime('2026-07-23 12:00:00');
    $data = [
      'updated' => '2026-07-23 11:00:00',
      'jobs' => ['dump' => ['status' => 'ok', 'finished' => '2026-07-23 03:00:00']],
      'environments' => [
        'alpha' => ['jobs' => ['dump' => ['status' => 'ok', 'finished' => '2026-07-23 03:00:00']]],
        'beta' => ['jobs' => ['dump' => ['status' => 'ok', 'finished' => '2026-07-23 03:00:00']]],
      ],
    ];
    // Every job in the file is healthy — an unset environment must still fail
    // rather than answer for the whole host or pick one at random.
    foreach ([NULL, ''] as $unset) {
      $r = CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26, $unset);
      self::assertSame('fail', $r['status']);
      self::assertStringContainsString('pulse_host_status_environment', $r['detail']);
    }

    // Configured for an environment the host does not record: the setting is
    // wrong or the host never ran a job for it — both are findings.
    $r = CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26, 'gamma');
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString('no jobs recorded for environment "gamma"', $r['detail']);
  }

  /**
   * A file without the environments map
   * is still judged by its host-wide `jobs`, with or without the setting.
   */
  public function testHostStatusFallsBackToTheHostWideJobsMap(): void {
    $now = strtotime('2026-07-23 12:00:00');
    $legacy = [
      'updated' => '2026-07-23 11:00:00',
      'jobs' => ['dump' => ['status' => 'ok', 'finished' => '2026-07-23 03:00:00']],
    ];
    self::assertSame('ok', CRM_Pulse_Check_HostStatus::evaluate($legacy, $now, 26)['status']);
    self::assertSame('ok', CRM_Pulse_Check_HostStatus::evaluate($legacy, $now, 26, 'alpha')['status']);

    $legacy['jobs']['dump']['status'] = 'error';
    self::assertSame('fail', CRM_Pulse_Check_HostStatus::evaluate($legacy, $now, 26, 'alpha')['status']);

    // A garbage 'environments' key is not a per-environment file either.
    $legacy['environments'] = 'nope';
    self::assertSame('fail', CRM_Pulse_Check_HostStatus::evaluate($legacy, $now, 26)['status']);
  }

  public function testHostStatusPerJobWindowsApplyInsideAnEnvironment(): void {
    $now = strtotime('2026-07-23 12:00:00');
    // Only a weekly backup for this tenant: the file's own freshness window
    // follows that job, and the host-wide 'updated' is judged against it.
    $data = [
      'updated' => '2026-07-19 03:00:00',
      'jobs' => ['dump' => ['status' => 'ok', 'finished' => '2026-07-23 11:00:00', 'expected_interval_hours' => 1]],
      'environments' => [
        'alpha' => [
          'jobs' => ['backup' => [
            'status' => 'ok',
            'finished' => '2026-07-19 03:00:00',
            'expected_interval_hours' => 168,
          ]],
        ],
      ],
    ];
    self::assertSame('ok', CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26, 'alpha')['status']);

    $data['environments']['alpha']['jobs']['backup']['finished'] = '2026-07-01 03:00:00';
    $r = CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26, 'alpha');
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString('backup: no success in 252h', $r['detail']);
  }

  public function testHostStatusToleratesAMangledEnvironmentBlock(): void {
    $now = strtotime('2026-07-23 12:00:00');
    $base = ['updated' => '2026-07-23 11:00:00', 'jobs' => []];
    // Scalar environment entry, missing jobs key, empty jobs map: none of them
    // may fatal, and none of them may pass as "this tenant is healthy".
    foreach (['not-a-block', [], ['jobs' => 'broken'], ['jobs' => []]] as $block) {
      $r = CRM_Pulse_Check_HostStatus::evaluate($base + ['environments' => ['alpha' => $block]], $now, 26, 'alpha');
      self::assertSame('fail', $r['status']);
      self::assertStringContainsString('no jobs recorded', $r['detail']);
    }
    // A scalar job entry inside an otherwise valid block is skipped, as in the
    // host-wide map.
    self::assertSame(
      'ok',
      CRM_Pulse_Check_HostStatus::evaluate(
        $base + ['environments' => ['alpha' => ['jobs' => ['dump' => 'not-a-state']]]],
        $now,
        26,
        'alpha',
      )['status'],
    );
  }

  public function testQueuesVerdict(): void {
    $this->assertSame('ok', CRM_Pulse_Check_Queues::verdict([], [], 6)['status']);

    $r = CRM_Pulse_Check_Queues::verdict(['import'], [], 6);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString("queue 'import' aborted", $r['detail']);

    $r = CRM_Pulse_Check_Queues::verdict([], ['mail' => ['count' => 3, 'oldest_hours' => 9]], 6);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString("queue 'mail': 3 item(s) stuck, oldest 9h (max 6h)", $r['detail']);
  }

  public function testPaymentsVerdict(): void {
    $this->assertSame('ok', CRM_Pulse_Check_Payments::verdict(NULL, 0, 2)['status']);
    $this->assertSame('ok', CRM_Pulse_Check_Payments::verdict(['count' => 0, 'oldest_hours' => 0], 0, 2)['status']);

    $r = CRM_Pulse_Check_Payments::verdict(['count' => 4, 'oldest_hours' => 5], 0, 2);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString('4 webhook(s) stuck in new/processing, oldest 5h (max 2h)', $r['detail']);

    $r = CRM_Pulse_Check_Payments::verdict(NULL, 2, 2);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString('2 webhook(s) errored in the last 24h', $r['detail']);
  }

  public function testPaymentsErrorDateColumnSelection(): void {
    $this->assertSame(
      'processed_date',
      CRM_Pulse_Check_Payments::errorDateColumn(['status', 'created_date', 'processed_date']),
    );
    $this->assertSame('created_date', CRM_Pulse_Check_Payments::errorDateColumn(['status', 'created_date']));
  }

  public function testJobsNeverRanBookkeeping(): void {
    $now = strtotime('2026-07-23 12:00:00');
    $grace = 48;

    // Unknown never-run job is registered at now, not yet failing.
    $r = CRM_Pulse_Check_Jobs::evaluateNeverRan(['a'], [], $now, $grace);
    $this->assertSame(['a' => $now], $r['firstSeen']);
    $this->assertSame([], $r['failed']);

    // A job first seen > grace ago fails; and cleanup drops a job no longer
    // in the never-run list (it ran or was deleted).
    $old = $now - (49 * 3600);
    $r = CRM_Pulse_Check_Jobs::evaluateNeverRan(['a'], ['a' => $old, 'gone' => $old], $now, $grace);
    $this->assertSame(['a' => $old], $r['firstSeen']);
    $this->assertSame(['a'], $r['failed']);

    // Within grace: registered but not failing.
    $r = CRM_Pulse_Check_Jobs::evaluateNeverRan(['a'], ['a' => $now - (10 * 3600)], $now, $grace);
    $this->assertSame([], $r['failed']);

    // grace 0 never fails even for an ancient registration.
    $r = CRM_Pulse_Check_Jobs::evaluateNeverRan(['a'], ['a' => $old], $now, 0);
    $this->assertSame([], $r['failed']);
  }

  /**
   * $_SERVER as PHP presents an X-Pulse-Token request header.
   *
   * @return array<string, string>
   */
  private static function serverWithTokenHeader(string $header): array {
    return ['HTTP_X_PULSE_TOKEN' => $header];
  }

  public function testHealthExtractToken(): void {
    // No header → query param used.
    $this->assertSame('q', \Civi\Pulse\HealthEndpoint::extractToken('q', []));
    // Empty query param → NULL.
    $this->assertNull(\Civi\Pulse\HealthEndpoint::extractToken('', []));
    $this->assertNull(\Civi\Pulse\HealthEndpoint::extractToken(NULL, []));

    // X-Pulse-Token header wins over query param, trimmed.
    $this->assertSame('h', \Civi\Pulse\HealthEndpoint::extractToken('q', self::serverWithTokenHeader('h')));
    $this->assertSame('h', \Civi\Pulse\HealthEndpoint::extractToken('q', self::serverWithTokenHeader('  h  ')));
    // Empty header → falls back to query param.
    $this->assertSame('q', \Civi\Pulse\HealthEndpoint::extractToken('q', self::serverWithTokenHeader(' ')));
    // Authorization is authx's header and is deliberately not read.
    $this->assertSame('q', \Civi\Pulse\HealthEndpoint::extractToken('q', ['HTTP_AUTHORIZATION' => 'Bearer h']));
  }

  public function testFatalMarkerCounting(): void {
    $chunk = <<<'LOG'
      Jul 23 [info] routine things
      PHP Fatal error:  Uncaught TypeError in /x.php:1
      Jul 23 [error] handled error, not fatal
      Jul 23 [critical] boom
      Fatal error: Allowed memory size exhausted
      LOG;
    $this->assertSame(3, CRM_Pulse_Check_FatalErrors::countFatalMarkers($chunk));
    $this->assertSame(0, CRM_Pulse_Check_FatalErrors::countFatalMarkers('all quiet [warning] meh'));
  }

  public function testCivirulesErrorMarkerCounting(): void {
    // Der Auslöser vom 23.07.: Regel-Abbruch wegen kaputtem condition_link
    // landet als [error]-Zeile — pulse_fatal_errors ignoriert die, dieser
    // Check nicht. Nicht-CiviRules-[error]-Zeilen bleiben weiter ungezählt.
    $chunk = <<<'LOG'
      2026-07-23 15:23:41+0000  [error] CiviRules: RuleID: 46, ConditionID: 3 has invalid condition_link operator:
      2026-07-23 15:23:46+0000  [info] ExampleBilling: invoice sent
      2026-07-23 15:24:00+0000  [error] some unrelated handled error
      2026-07-23 15:24:05+0000  [critical] CiviRule action failed hard
      2026-07-23 15:24:06+0000  [info] CiviRules: rule executed fine
      LOG;
    $this->assertSame(2, CRM_Pulse_Check_Civirules::countErrorMarkers($chunk));
    $this->assertSame(0, CRM_Pulse_Check_Civirules::countErrorMarkers('all quiet [error] but no rules involved'));
  }

  /**
   * One ConfigAndLog line as CRM_Core_Error's logger writes it:
   * `Y-m-d H:i:sO`, empty ident, `[level] message`.
   */
  private static function logLine(int $timestamp, string $rest): string {
    return date('Y-m-d H:i:sO', $timestamp) . '  ' . $rest;
  }

  /**
   * A file's mtime only proves that SOMETHING was appended recently — the
   * entries around it can be arbitrarily old. Counting the whole tail keeps
   * both log legs failing long after their window passed, and herald alerts
   * on transitions: a check stuck at 'fail' never reports the next incident.
   */
  public function testLogChunkKeepsOnlyEntriesInsideTheWindow(): void {
    $now = strtotime('2026-07-23 15:30:00+0000');
    $chunk = implode("\n", [
      self::logLine($now - 10_800, '[critical] boom, three hours ago'),
      self::logLine($now - 7200, '[error] CiviRules: RuleID 46 has invalid condition_link operator'),
      self::logLine($now - 120, '[critical] boom, two minutes ago'),
      self::logLine($now - 60, '[error] CiviRules: RuleID 47 has invalid condition_link operator'),
    ]);

    // The raw tail carries the old incident as well as the current one …
    $this->assertSame(2, CRM_Pulse_Check_FatalErrors::countFatalMarkers($chunk));
    $this->assertSame(2, CRM_Pulse_Check_Civirules::countErrorMarkers($chunk));

    // … within a one-hour window only the current one may count.
    $recent = CRM_Pulse_Util::logChunkSince($chunk, $now - 3600);
    $this->assertSame(1, CRM_Pulse_Check_FatalErrors::countFatalMarkers($recent));
    $this->assertSame(1, CRM_Pulse_Check_Civirules::countErrorMarkers($recent));

    // Nothing at all once the window has moved past every entry.
    $none = CRM_Pulse_Util::logChunkSince($chunk, $now + 60);
    $this->assertSame(0, CRM_Pulse_Check_FatalErrors::countFatalMarkers($none));
    $this->assertSame(0, CRM_Pulse_Check_Civirules::countErrorMarkers($none));
  }

  public function testLogChunkTreatsUndatedLinesAsPartOfTheEntryAboveThem(): void {
    $now = strtotime('2026-07-23 15:30:00+0000');
    $since = $now - 3600;

    // A backtrace belongs to the entry that opened it — kept when fresh …
    $fresh =
      self::logLine($now - 120, '[critical] boom') . "\n#0 /var/www/x.php(12): PHP Fatal error:  Uncaught TypeError";
    $this->assertSame(2, CRM_Pulse_Check_FatalErrors::countFatalMarkers(
      CRM_Pulse_Util::logChunkSince($fresh, $since),
    ));

    // … and dropped as a whole when the entry is outside the window.
    $old =
      self::logLine($now - 10_800, '[critical] boom') . "\n#0 /var/www/x.php(12): PHP Fatal error:  Uncaught TypeError";
    $this->assertSame(0, CRM_Pulse_Check_FatalErrors::countFatalMarkers(
      CRM_Pulse_Util::logChunkSince($old, $since),
    ));
  }

  public function testLogChunkDropsTheUndatedHeadOfATruncatedTail(): void {
    $now = strtotime('2026-07-23 15:30:00+0000');
    // Reading only the tail can start mid-entry: that fragment has no
    // provable date and is the oldest content in the chunk.
    $chunk =
      "sc(12): PHP Fatal error:  cut off by the tail window\n"
      . self::logLine($now - 60, '[critical] boom, one minute ago');
    $this->assertSame(1, CRM_Pulse_Check_FatalErrors::countFatalMarkers(
      CRM_Pulse_Util::logChunkSince($chunk, $now - 3600),
    ));
  }

  public function testCivirulesParamsDecodable(): void {
    $this->assertTrue(CRM_Pulse_Check_Civirules::paramsDecodable(NULL));
    $this->assertTrue(CRM_Pulse_Check_Civirules::paramsDecodable(''));
    $this->assertTrue(CRM_Pulse_Check_Civirules::paramsDecodable(serialize(['operator' => '>', 'value' => 0])));
    $this->assertTrue(CRM_Pulse_Check_Civirules::paramsDecodable(serialize(value: FALSE)));
    // Abgeschnittener/kaputter Blob — der Klassiker nach Hand-SQL.
    $this->assertFalse(CRM_Pulse_Check_Civirules::paramsDecodable('a:2:{s:8:"operator";s:1:'));
  }

  /**
   * A serialised OBJECT stays "decodable" — the check asks whether CiviRules
   * could read the blob, and must not instantiate foreign DB content to find
   * out (allowed_classes => FALSE turns it into __PHP_Incomplete_Class).
   */
  public function testCivirulesParamsWithObjectPayloadStayDecodableWithoutInstantiating(): void {
    $blob = serialize(new ArrayObject(['a' => 1]));
    $this->assertTrue(CRM_Pulse_Check_Civirules::paramsDecodable($blob));
  }

  public function testHostStatusToleratesAStatusFileWithoutUsableJobs(): void {
    $now = strtotime('2026-07-23 12:00:00');
    // No jobs at all: only the file's own freshness can be judged.
    $this->assertSame(
      'ok',
      CRM_Pulse_Check_HostStatus::evaluate(
        ['updated' => '2026-07-23 11:00:00', 'jobs' => []],
        $now,
        26,
      )['status'],
    );
    // 'jobs' present but not a map — must not fatal, just yield nothing to check.
    $this->assertSame(
      'ok',
      CRM_Pulse_Check_HostStatus::evaluate(
        ['updated' => '2026-07-23 11:00:00', 'jobs' => 'broken'],
        $now,
        26,
      )['status'],
    );
    // Scalar job entries are skipped rather than dereferenced.
    $this->assertSame(
      'ok',
      CRM_Pulse_Check_HostStatus::evaluate(
        ['updated' => '2026-07-23 11:00:00', 'jobs' => ['dump' => 'not-a-state']],
        $now,
        26,
      )['status'],
    );
    // A missing/garbage 'updated' is treated as "never wrote", not as now.
    $r = CRM_Pulse_Check_HostStatus::evaluate(['jobs' => []], $now, 26);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString('status file stale', $r['detail']);
  }

  public function testHostStatusReportsEveryProblemAtOnce(): void {
    $now = strtotime('2026-07-23 12:00:00');
    $data = [
      'updated' => '2026-07-23 11:00:00',
      'jobs' => [
        // Errored AND long overdue — the error is the useful message.
        'dump' => ['status' => 'error', 'finished' => '2026-07-01 03:00:00'],
        'backup' => ['status' => 'ok', 'finished' => '2026-07-01 03:00:00'],
      ],
    ];
    $r = CRM_Pulse_Check_HostStatus::evaluate($data, $now, 26);
    // An errored job is reported by its error, never additionally by its age.
    $this->assertStringContainsString('dump failed', $r['detail']);
    $this->assertStringNotContainsString('dump: no success', $r['detail']);
    $this->assertStringContainsString('backup: no success in 26h', $r['detail']);
  }

  public function testJobsNeverRanIgnoresGarbageInTheFirstSeenMap(): void {
    $now = strtotime('2026-07-23 12:00:00');
    // A non-numeric leftover is re-registered at now instead of being cast to
    // 0 (which would read as "first seen in 1970" and fail immediately).
    $r = CRM_Pulse_Check_Jobs::evaluateNeverRan(['a'], ['a' => 'rubbish'], $now, 48);
    $this->assertSame(['a' => $now], $r['firstSeen']);
    $this->assertSame([], $r['failed']);
  }

  public function testQueuesVerdictReportsAbortedQueuesEvenWithTheAgeLegOff(): void {
    // maxAgeHours 0 disables only the age leg; check() then passes an empty
    // $stale, but an aborted queue must still fail.
    $r = CRM_Pulse_Check_Queues::verdict(['import'], [], 0);
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString("queue 'import' aborted", $r['detail']);
  }

  public function testDrupalStatusesResolveConstantsInsteadOfGuessingNumbers(): void {
    // Outside Drupal neither the D8+ interface constants nor the D7
    // procedural ones exist — and then NOTHING may be flagged, because a
    // baked-in guess would silently mis-classify every project.
    $this->assertSame(
      ['security' => [], 'update' => []],
      CRM_Pulse_Check_CmsUpdates::drupalStatuses(),
    );

    // With the D7-era procedural constants present they are picked up.
    define('UPDATE_NOT_SECURE', 2);
    define('UPDATE_REVOKED', 3);
    define('UPDATE_NOT_CURRENT', 6);
    $this->assertSame(
      ['security' => [2, 3], 'update' => [6]],
      CRM_Pulse_Check_CmsUpdates::drupalStatuses(),
    );
  }

  public function testUtilCmsCallOnlyInvokesFunctionsThatExistHere(): void {
    $this->assertSame('AB', CRM_Pulse_Util::cmsCall('strtoupper', ['ab']));
    // A CMS global that is not present on this stack yields NULL rather than
    // a fatal — that is what lets the CMS checks degrade to 'skipped'.
    $this->assertNull(CRM_Pulse_Util::cmsCall('update_get_available', [FALSE]));
  }

}
