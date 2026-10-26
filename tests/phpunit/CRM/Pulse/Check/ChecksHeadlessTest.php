<?php

declare(strict_types = 1);

use Civi\Api4\System;
use Civi\API\Event\PrepareEvent;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * The checks that read real CiviCRM state — settings, `civicrm_job`, the queue
 * registry, the filesystem — exercised end to end.
 *
 * ChecksTest covers the pure verdict helpers; what is tested HERE is
 * everything between the database and those helpers: the SQL, the column
 * names, the settings keys and the "0 disables this leg" convention. That
 * layer is exactly what rots silently when a schema or a setting is renamed,
 * and a monitoring extension that stops noticing is worse than none.
 *
 * @group headless
 */
class CRM_Pulse_Check_ChecksHeadlessTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * Every name pulse's status messages can carry.
   */
  private const NAMES = [
    'pulse_database',
    'pulse_updates',
    'pulse_updates_feed',
    'pulse_cms_updates',
    'pulse_fatal_errors',
    'pulse_jobs',
    'pulse_queues',
    'pulse_payments',
    'pulse_disk',
    'pulse_host_status',
    'pulse_civirules',
    'pulse_duplicates',
  ];

  /**
   * Temporary files created by a test, removed in tearDown.
   *
   * @var list<string>
   */
  private array $tempFiles = [];

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    // Both update checks memoise for hours; every test starts cold.
    \Civi::cache('long')->delete(CRM_Pulse_Check_UpdateFeed::CACHE_KEY);
    \Civi::cache('long')->delete('pulse_cms_updates_check');
  }

  protected function tearDown(): void {
    foreach ($this->tempFiles as $file) {
      if (!file_exists($file)) {
        continue;
      }

      unlink($file);
    }
    $this->tempFiles = [];
    parent::tearDown();
    // The rollback restores the rows, not the settings cache (environment, mail).
    \Civi::service('settings_manager')->flush();
  }

  private function tempFile(string $contents): string {
    $file = (string) tempnam(sys_get_temp_dir(), 'pulse-test-');
    $this->tempFiles[] = $file;
    file_put_contents($file, $contents);
    return $file;
  }

  /**
   * Reduce civicrm_job to a single active job under our control, so the check
   * sees a deterministic fleet.
   */
  private function soleActiveJob(string $frequency, ?string $lastRun): string {
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_job SET is_active = 0');
    $id = (int) \CRM_Core_DAO::singleValueQuery('SELECT MIN(id) FROM civicrm_job');
    // last_run is set separately: NULL is a state of its own here (never ran),
    // and a NULL bound as 'String' would land as an empty string.
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_job SET is_active = 1, run_frequency = %1, last_run = NULL WHERE id = %2',
      [1 => [$frequency, 'String'], 2 => [$id, 'Integer']],
    );
    if ($lastRun !== NULL) {
      \CRM_Core_DAO::executeQuery(
        'UPDATE civicrm_job SET last_run = %1 WHERE id = %2',
        [1 => [$lastRun, 'String'], 2 => [$id, 'Integer']],
      );
    }
    return (string) \CRM_Core_DAO::singleValueQuery(
      'SELECT name FROM civicrm_job WHERE id = %1',
      [1 => [$id, 'Integer']],
    );
  }

  public function testDiskThresholdAndDisableConvention(): void {
    \Civi::settings()->set('pulse_min_disk_free_percent', 0);
    self::assertSame('skipped', CRM_Pulse_Check_Disk::check()['status']);

    // No real filesystem has 100% free, so this pins the failure path
    // (including the human-readable detail) without faking anything.
    \Civi::settings()->set('pulse_min_disk_free_percent', 100);
    $fail = CRM_Pulse_Check_Disk::check();
    self::assertSame('fail', $fail['status']);
    self::assertStringContainsString('threshold 100%', $fail['detail']);

    // At (or below) the actual free percentage the check must pass.
    $dir = CRM_Pulse_Util::logDir();
    $freePercent = ((float) disk_free_space($dir) / (float) disk_total_space($dir)) * 100;
    \Civi::settings()->set('pulse_min_disk_free_percent', max(1, (int) floor($freePercent)));
    self::assertSame(
      $freePercent >= 1.0 ? 'ok' : 'fail',
      CRM_Pulse_Check_Disk::check()['status'],
    );
  }

  public function testHostStatusReadsTheMountedStatusFile(): void {
    // Unset setting = feature off, never a failure.
    \Civi::settings()->set('pulse_host_status_file', NULL);
    self::assertSame('skipped', CRM_Pulse_Check_HostStatus::check()['status']);

    // Configured but absent: the mount is missing or the host never wrote it — that
    // IS a finding, not a skip.
    \Civi::settings()->set('pulse_host_status_file', '/nonexistent/pulse/host-status.json');
    $missing = CRM_Pulse_Check_HostStatus::check();
    self::assertSame('fail', $missing['status']);
    self::assertStringContainsString('not readable', $missing['detail']);

    $file = $this->tempFile('this is not JSON');
    \Civi::settings()->set('pulse_host_status_file', $file);
    $garbage = CRM_Pulse_Check_HostStatus::check();
    self::assertSame('fail', $garbage['status']);
    self::assertStringContainsString('not valid host status JSON', $garbage['detail']);

    file_put_contents(
      $file,
      (string) json_encode([
        'updated' => date('c'),
        'jobs' => ['dump' => ['status' => 'ok', 'finished' => date('c')]],
      ]),
    );
    $fresh = CRM_Pulse_Check_HostStatus::check();
    self::assertSame('ok', $fresh['status']);
    // Job metadata rides along for pollers: outcome and timing only.
    self::assertSame(['status', 'finished'], array_keys($fresh['jobs']['dump']));
    self::assertNotEmpty($fresh['updated']);

    file_put_contents(
      $file,
      (string) json_encode([
        'updated' => date('c'),
        'jobs' => ['dump' => ['status' => 'error', 'finished' => date('c'), 'detail' => 'restic exited 1']],
      ]),
    );
    $failed = CRM_Pulse_Check_HostStatus::check();
    self::assertSame('fail', $failed['status']);
    self::assertStringContainsString('dump failed: restic exited 1', $failed['detail']);
    // A job's own detail may name paths — it stays out of the published map.
    self::assertArrayNotHasKey('detail', $failed['jobs']['dump']);
  }

  public function testHostStatusScopesAPerEnvironmentStatusFileToThisInstance(): void {
    // The same host-wide file every tenant container sees: "beta" is broken,
    // "alpha" is fine.
    $file = $this->tempFile((string) json_encode([
      'updated' => date('c'),
      'jobs' => ['dump' => ['status' => 'error', 'finished' => date('c'), 'detail' => 'restic exited 1']],
      'environments' => [
        'alpha' => ['jobs' => ['dump' => ['status' => 'ok', 'finished' => date('c')]]],
        'beta' => ['jobs' => ['dump' => ['status' => 'error', 'finished' => date('c'), 'detail' => 'restic exited 1']]],
      ],
    ]));
    \Civi::settings()->set('pulse_host_status_file', $file);

    // Default (unset): no honest answer, so no answer.
    \Civi::settings()->set('pulse_host_status_environment', NULL);
    $unset = CRM_Pulse_Check_HostStatus::check();
    self::assertSame('fail', $unset['status']);
    self::assertStringContainsString('pulse_host_status_environment', $unset['detail']);

    \Civi::settings()->set('pulse_host_status_environment', 'alpha');
    self::assertSame('ok', CRM_Pulse_Check_HostStatus::check()['status']);

    // Whitespace is not an environment name.
    \Civi::settings()->set('pulse_host_status_environment', '  ');
    self::assertSame('fail', CRM_Pulse_Check_HostStatus::check()['status']);

    \Civi::settings()->set('pulse_host_status_environment', 'beta');
    $beta = CRM_Pulse_Check_HostStatus::check();
    self::assertSame('fail', $beta['status']);
    self::assertStringContainsString('dump failed: restic exited 1', $beta['detail']);

    // A pre-environments file keeps being judged by its host-wide jobs, even
    // with the setting left over from an upgrade.
    file_put_contents(
      $file,
      (string) json_encode([
        'updated' => date('c'),
        'jobs' => ['dump' => ['status' => 'ok', 'finished' => date('c')]],
      ]),
    );
    self::assertSame('ok', CRM_Pulse_Check_HostStatus::check()['status']);
  }

  public function testHostStatusHonoursItsOwnMaxAgeSetting(): void {
    $file = $this->tempFile((string) json_encode([
      'updated' => date('c'),
      'jobs' => ['dump' => ['status' => 'ok', 'finished' => date('c', time() - (10 * 3600))]],
    ]));
    \Civi::settings()->set('pulse_host_status_file', $file);

    \Civi::settings()->set('pulse_host_status_max_age', 26);
    self::assertSame('ok', CRM_Pulse_Check_HostStatus::check()['status']);

    \Civi::settings()->set('pulse_host_status_max_age', 4);
    $r = CRM_Pulse_Check_HostStatus::check();
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString('dump: no success in 4h', $r['detail']);
  }

  public function testJobsStalenessIsMeasuredAgainstEachJobsOwnFrequency(): void {
    \Civi::settings()->set('pulse_jobs_never_ran_grace_hours', 0);

    // Both legs off is the documented "disabled" state.
    \Civi::settings()->set('pulse_jobs_grace_factor', 0);
    self::assertSame('skipped', CRM_Pulse_Check_Jobs::check()['status']);

    \Civi::settings()->set('pulse_jobs_grace_factor', 3);
    $name = $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - 3600));
    self::assertSame('ok', CRM_Pulse_Check_Jobs::check()['status']);

    // Two days is inside Daily × 3, four days is not.
    $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (2 * 86_400)));
    self::assertSame('ok', CRM_Pulse_Check_Jobs::check()['status']);

    $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (4 * 86_400)));
    $stale = CRM_Pulse_Check_Jobs::check();
    self::assertSame('fail', $stale['status']);
    self::assertStringContainsString($name, $stale['detail']);
    self::assertStringContainsString('last run 96h ago', $stale['detail']);

    // An unrecognised run_frequency cannot be dated, so it is left alone
    // rather than guessed at.
    $this->soleActiveJob('Biweekly', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (400 * 86_400)));
    self::assertSame('ok', CRM_Pulse_Check_Jobs::check()['status']);

    // An INACTIVE job is nobody's problem, however long it has been dead.
    $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (400 * 86_400)));
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_job SET is_active = 0');
    self::assertSame('ok', CRM_Pulse_Check_Jobs::check()['status']);
  }

  public function testJobsCoreRefusesOutsideProductionAreNotJudged(): void {
    \Civi::settings()->set('pulse_jobs_never_ran_grace_hours', 48);
    \Civi::settings()->set('pulse_jobs_grace_factor', 3);
    \Civi::settings()->set('environment', 'Staging');
    $name = $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (14 * 86_400)));
    \CRM_Core_DAO::executeQuery("UPDATE civicrm_job SET parameters = 'version=3' WHERE is_active = 1");
    self::assertSame('ok', CRM_Pulse_Check_Jobs::check()['status']);

    // Neither does a never-run job core would refuse.
    $this->soleActiveJob('Daily', NULL);
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_job SET parameters = NULL WHERE is_active = 1');
    \Civi::settings()->set('pulse_jobs_first_seen', [$name => \CRM_Utils_Time::time() - (49 * 3600)]);
    self::assertSame('ok', CRM_Pulse_Check_Jobs::check()['status']);

    // A job allowed to run outside Production is judged as usual.
    $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (14 * 86_400)));
    \CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_job SET parameters = 'runInNonProductionEnvironment=TRUE' WHERE is_active = 1",
    );
    $r = CRM_Pulse_Check_Jobs::check();
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString($name, $r['detail']);
  }

  public function testJobsNeverRanLegRegistersBeforeItAccuses(): void {
    \Civi::settings()->set('pulse_jobs_grace_factor', 0);
    \Civi::settings()->set('pulse_jobs_never_ran_grace_hours', 48);
    \Civi::settings()->set('pulse_jobs_first_seen', []);
    $name = $this->soleActiveJob('Daily', NULL);

    // First sighting only records the job — a job created a minute ago must
    // not alert.
    self::assertSame('ok', CRM_Pulse_Check_Jobs::check()['status']);
    $seen = \Civi::settings()->get('pulse_jobs_first_seen');
    self::assertIsArray($seen);
    self::assertArrayHasKey($name, $seen);

    // Backdate the registration past the grace window: now it is a finding.
    \Civi::settings()->set('pulse_jobs_first_seen', [$name => \CRM_Utils_Time::time() - (49 * 3600)]);
    $r = CRM_Pulse_Check_Jobs::check();
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString($name . ' never ran', $r['detail']);

    // Once the job has run, the bookkeeping forgets it instead of growing.
    $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time()));
    self::assertSame('ok', CRM_Pulse_Check_Jobs::check()['status']);
    self::assertSame([], \Civi::settings()->get('pulse_jobs_first_seen'));
  }

  public function testQueuesReportAbortedRegistryRowsButNotTemplates(): void {
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue_item');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue');
    \Civi::settings()->set('pulse_queue_max_age_hours', 6);
    self::assertSame('ok', CRM_Pulse_Check_Queues::check()['status']);

    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_queue (name, type, status, is_template) VALUES ('pulse-test-aborted', 'Sql', 'aborted', 0)",
    );
    $r = CRM_Pulse_Check_Queues::check();
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString("queue 'pulse-test-aborted' aborted", $r['detail']);

    // A template is a prototype that never runs — not an outage.
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_queue SET is_template = 1');
    self::assertSame('ok', CRM_Pulse_Check_Queues::check()['status']);

    // Aborted queues fail even when the age leg is switched off.
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_queue SET is_template = 0');
    \Civi::settings()->set('pulse_queue_max_age_hours', 0);
    self::assertSame('fail', CRM_Pulse_Check_Queues::check()['status']);
  }

  public function testQueuesCountStuckItemsButNotDeferredRetries(): void {
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue_item');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue');
    \Civi::settings()->set('pulse_queue_max_age_hours', 6);
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_queue (name, type, status, is_template) VALUES ('pulse-test-active', 'Sql', 'active', 0)",
    );
    $old = date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (10 * 3600));
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_queue_item (queue_name, weight, submit_time, data)
       VALUES ('pulse-test-active', 0, %1, 'x'), ('pulse-test-active', 0, %1, 'y')",
      [1 => [$old, 'String']],
    );

    $r = CRM_Pulse_Check_Queues::check();
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString("queue 'pulse-test-active': 2 item(s) stuck, oldest 10h (max 6h)", $r['detail']);

    // A release_time in the future is a scheduled retry, not a stuck item.
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_queue_item SET release_time = %1',
      [1 => [date('Y-m-d H:i:s', \CRM_Utils_Time::time() + 3600), 'String']],
    );
    self::assertSame('ok', CRM_Pulse_Check_Queues::check()['status']);

    // A queue that is not 'active' is not being drained by anyone.
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_queue_item SET release_time = NULL');
    \CRM_Core_DAO::executeQuery("UPDATE civicrm_queue SET status = 'completed'");
    self::assertSame('ok', CRM_Pulse_Check_Queues::check()['status']);

    // 0 disables only the age leg.
    \CRM_Core_DAO::executeQuery("UPDATE civicrm_queue SET status = 'active'");
    \Civi::settings()->set('pulse_queue_max_age_hours', 0);
    self::assertSame('ok', CRM_Pulse_Check_Queues::check()['status']);
  }

  /**
   * The extension browser reads its feed through a mocked client: $versions
   * as the feed, or HTTP 500 for NULL. No request leaves the test.
   *
   * @param array<string, string>|null $versions extension key => release
   */
  private function serveFeed(?array $versions): void {
    $response = new Response(500);
    if ($versions !== NULL) {
      $feed = [];
      foreach ($versions as $key => $version) {
        $feed[$key] = sprintf(
          '<?xml version="1.0"?><extension key="%1$s" type="module"><file>%1$s</file><name>%1$s</name><version>%2$s</version></extension>',
          $key,
          $version,
        );
      }
      $response = new Response(200, [], (string) json_encode($feed));
    }
    $browser = \CRM_Extension_System::singleton()->getBrowser();
    self::assertTrue($browser->isEnabled(), 'the test site must have an extension feed configured');
    $browser->setGuzzleClient(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
    \Civi::cache('extension_browser')->flush();
    \Civi::cache('long')->delete(CRM_Pulse_Check_UpdateFeed::CACHE_KEY);
  }

  private function pulseVersion(): string {
    return (string) \CRM_Extension_System::singleton()->getMapper()->keyToInfo('pulse')->version;
  }

  /**
   * @param list<\CRM_Utils_Check_Message> $messages
   * @return array<string, \CRM_Utils_Check_Message>
   */
  private static function byName(array $messages): array {
    $named = [];
    foreach ($messages as $message) {
      $named[$message->getName()] = $message;
    }
    ksort($named);
    return $named;
  }

  public function testUpdatesSeverityGateAndIgnoreList(): void {
    \Civi::settings()->set('pulse_updates_min_severity', 'off');
    self::assertSame([], CRM_Pulse_Check_Updates::messages());

    // A typo in the setting must fail loudly, not silently disable the check.
    \Civi::settings()->set('pulse_updates_min_severity', 'not-a-severity');
    $bad = self::byName(CRM_Pulse_Check_Updates::messages());
    self::assertSame(['pulse_updates'], array_keys($bad));
    self::assertSame(LogLevel::ERROR, $bad['pulse_updates']->getSeverity());
    self::assertStringContainsString(
      'invalid pulse_updates_min_severity &#039;not-a-severity&#039;',
      $bad['pulse_updates']->getMessage(),
    );

    // "extensions" silences every extension update without reading the feed.
    \Civi::settings()->set('pulse_updates_min_severity', 'warning');
    \Civi::settings()->set('pulse_updates_ignore', ['extensions']);
    $this->serveFeed(['pulse' => '99.0.0']);
    self::assertSame([], CRM_Pulse_Check_Updates::messages());
    self::assertNull(\Civi::cache('long')->get(CRM_Pulse_Check_UpdateFeed::CACHE_KEY));
  }

  public function testUpdatesReportInstalledExtensionsWithANewerRelease(): void {
    \Civi::settings()->set('pulse_updates_min_severity', 'warning');
    \Civi::settings()->set('pulse_updates_ignore', []);
    $installed = $this->pulseVersion();

    // Healthy: the newest release is the installed one.
    $this->serveFeed(['pulse' => $installed]);
    self::assertSame([], CRM_Pulse_Check_Updates::messages());

    $this->serveFeed(['pulse' => '99.0.0', 'org.example.notinstalled' => '1.0.0']);
    $update = self::byName(CRM_Pulse_Check_Updates::messages());
    self::assertSame(['pulse_updates'], array_keys($update));
    self::assertSame(LogLevel::WARNING, $update['pulse_updates']->getSeverity());
    self::assertStringContainsString(
      "<code>pulse</code> {$installed} → 99.0.0",
      $update['pulse_updates']->getMessage(),
    );
    self::assertStringNotContainsString('org.example.notinstalled', $update['pulse_updates']->getMessage());

    // The setting names the message's severity; the read stays cached.
    \Civi::settings()->set('pulse_updates_min_severity', 'notice');
    self::assertSame(
      LogLevel::NOTICE,
      self::byName(CRM_Pulse_Check_Updates::messages())['pulse_updates']->getSeverity(),
    );

    // A snoozed release stays quiet; any other release reports again.
    \Civi::settings()->set('pulse_updates_ignore', ['pulse@99.0.0']);
    self::assertSame([], CRM_Pulse_Check_Updates::messages());
    \Civi::settings()->set('pulse_updates_ignore', ['pulse@98.0.0']);
    self::assertArrayHasKey('pulse_updates', self::byName(CRM_Pulse_Check_Updates::messages()));
  }

  /**
   * A failed feed read must not look like a recovery: the update message stays
   * with the last known versions, and the read is reported on its own.
   */
  public function testFailedFeedReadKeepsTheUpdateMessageAndIsReportedSeparately(): void {
    \Civi::settings()->set('pulse_updates_min_severity', 'warning');
    \Civi::settings()->set('pulse_updates_ignore', []);
    $this->serveFeed(['pulse' => '99.0.0']);
    $before = self::byName(CRM_Pulse_Check_Updates::messages());
    self::assertSame(['pulse_updates'], array_keys($before));

    $this->serveFeed(NULL);
    $after = self::byName(CRM_Pulse_Check_Updates::messages());
    self::assertSame(['pulse_updates', 'pulse_updates_feed'], array_keys($after));
    self::assertSame($before['pulse_updates']->getMessage(), $after['pulse_updates']->getMessage());
    self::assertSame(LogLevel::WARNING, $after['pulse_updates']->getSeverity());
    self::assertSame(LogLevel::ERROR, $after['pulse_updates_feed']->getSeverity());
    self::assertStringContainsString('extension feed could not be read', $after['pulse_updates_feed']->getMessage());

    // The failure is cached too: the mock holds one response, a second read would throw.
    self::assertSame(array_keys($after), array_keys(self::byName(CRM_Pulse_Check_Updates::messages())));
  }

  public function testFailedFirstFeedReadReportsOnlyTheRead(): void {
    \Civi::settings()->set('pulse_updates_min_severity', 'warning');
    \Civi::settings()->set('pulse_updates_ignore', []);
    \Civi::settings()->set(CRM_Pulse_Check_UpdateFeed::LAST_READ_SETTING, []);
    $this->serveFeed(NULL);
    self::assertSame(['pulse_updates_feed'], array_keys(self::byName(CRM_Pulse_Check_Updates::messages())));
  }

  /**
   * The update message comes from core's extension mapper and browser. Going
   * through System.check would run every check, pulse's own included, again.
   */
  public function testUpdateMessageIsProducedWithoutSystemCheck(): void {
    \Civi::settings()->set('pulse_updates_min_severity', 'warning');
    \Civi::settings()->set('pulse_updates_ignore', []);
    $this->serveFeed(['pulse' => '99.0.0']);

    $checkHooks = 0;
    $systemChecks = 0;
    $onCheck = static function () use (&$checkHooks): void {
      $checkHooks++;
    };
    $onApi = static function (PrepareEvent $event) use (&$systemChecks): void {
      if (strtolower($event->getEntityName() . '.' . $event->getActionName()) === 'system.check') {
        $systemChecks++;
      }
    };
    \Civi::dispatcher()->addListener('hook_civicrm_check', $onCheck);
    \Civi::dispatcher()->addListener('civi.api.prepare', $onApi);
    try {
      self::assertArrayHasKey('pulse_updates', self::byName(CRM_Pulse_Check_Updates::messages()));
      self::assertSame(0, $checkHooks);
      self::assertSame(0, $systemChecks);

      // The listeners do see a System.check when there is one.
      System::check(checkPermissions: FALSE)->addWhere('name', 'IN', ['pulse_updates'])->execute();
      self::assertSame(1, $checkHooks);
      self::assertSame(1, $systemChecks);
    }
    finally {
      \Civi::dispatcher()->removeListener('hook_civicrm_check', $onCheck);
      \Civi::dispatcher()->removeListener('civi.api.prepare', $onApi);
    }
  }

  /**
   * With the extension browser switched off by policy there is nothing to
   * compare against, and nothing to report.
   */
  public function testExtensionUpdatesStaySilentWithoutARemoteCatalogue(): void {
    $repoUrl = \Civi::settings()->get('ext_repo_url');
    \Civi::settings()->set('ext_repo_url', value: FALSE);
    \CRM_Extension_System::singleton(fresh: TRUE);
    try {
      self::assertFalse(\CRM_Extension_System::singleton()->getBrowser()->isEnabled());
      \Civi::cache('long')->delete(CRM_Pulse_Check_UpdateFeed::CACHE_KEY);
      \Civi::settings()->set('pulse_updates_min_severity', 'warning');
      \Civi::settings()->set('pulse_updates_ignore', []);
      self::assertSame([], CRM_Pulse_Check_Updates::messages());
    }
    finally {
      \Civi::settings()->set('ext_repo_url', $repoUrl);
      \CRM_Extension_System::singleton(fresh: TRUE);
    }
  }

  public function testCmsUpdatesSkipWhenTheHostCmsHasNoUpdateBookkeeping(): void {
    \Civi::settings()->set('pulse_cms_updates_mode', 'off');
    self::assertSame('skipped', CRM_Pulse_Check_CmsUpdates::check()['status']);

    // The test stack is Standalone: no Drupal update module, no WP transients.
    \Civi::cache('long')->delete('pulse_cms_updates_check');
    \Civi::settings()->set('pulse_cms_updates_mode', 'security');
    self::assertSame('skipped', CRM_Pulse_Check_CmsUpdates::check()['status']);

    \Civi::cache('long')->set('pulse_cms_updates_check', ['status' => 'fail', 'detail' => 'from the cache']);
    self::assertSame('from the cache', CRM_Pulse_Check_CmsUpdates::check()['detail']);
  }

  /**
   * The duplicates check must count only window-fresh contacts that share an
   * e-mail with another live contact — and honour both the threshold gate and
   * the 0-disables convention.
   */
  public function testDuplicatesCountsFreshEmailSharersOnly(): void {
    $email = 'pulse-dup-' . uniqid() . '@example.org';
    $old = $this->contactWithEmail('Old', $email, '2020-01-01 12:00:00');
    $fresh = $this->contactWithEmail('Fresh', $email, date('Y-m-d H:i:s'));

    \Civi::settings()->set('pulse_duplicates_threshold', 1);
    \Civi::settings()->set('pulse_duplicates_window_days', 7);
    $result = \CRM_Pulse_Check_Duplicates::check();
    self::assertSame('fail', $result['status']);
    self::assertStringContainsString("{$fresh}→{$old}", $result['detail']);
    self::assertStringNotContainsString(
      $email,
      $result['detail'],
      'e-mail addresses are PII and must not leak into check details',
    );

    // Below the threshold the same data is an ok.
    \Civi::settings()->set('pulse_duplicates_threshold', 2);
    self::assertSame('ok', \CRM_Pulse_Check_Duplicates::check()['status']);

    // A trashed counterpart no longer makes the fresh contact a duplicate.
    \Civi::settings()->set('pulse_duplicates_threshold', 1);
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_contact SET is_deleted = 1 WHERE id = %1',
      [1 => [$old, 'Integer']],
    );
    self::assertSame('ok', \CRM_Pulse_Check_Duplicates::check()['status']);
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_contact SET is_deleted = 0 WHERE id = %1',
      [1 => [$old, 'Integer']],
    );

    // Outside the window the fresh contact does not count either.
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_contact SET created_date = %1 WHERE id = %2',
      [1 => ['2020-01-02 12:00:00', 'String'], 2 => [$fresh, 'Integer']],
    );
    self::assertSame('ok', \CRM_Pulse_Check_Duplicates::check()['status']);

    \Civi::settings()->set('pulse_duplicates_threshold', 0);
    self::assertSame('skipped', \CRM_Pulse_Check_Duplicates::check()['status']);
  }

  public function testDuplicatesCountsTwoFreshSharersOnce(): void {
    $email = 'pulse-dup-' . uniqid() . '@example.org';
    $first = $this->contactWithEmail('First', $email, date('Y-m-d H:i:s', time() - 60));
    $second = $this->contactWithEmail('Second', $email, date('Y-m-d H:i:s'));

    \Civi::settings()->set('pulse_duplicates_window_days', 7);
    \Civi::settings()->set('pulse_duplicates_threshold', 2);
    self::assertSame('ok', \CRM_Pulse_Check_Duplicates::check()['status']);

    \Civi::settings()->set('pulse_duplicates_threshold', 1);
    $result = \CRM_Pulse_Check_Duplicates::check();
    self::assertSame('fail', $result['status']);
    self::assertStringContainsString("{$second}→{$first}", $result['detail']);
    self::assertStringNotContainsString("{$first}→{$second}", $result['detail']);
  }

  /**
   * A site's usual rate is not a spike: with three duplicates in each of the
   * last twelve windows, seven fresh ones stay below three times that normal.
   */
  public function testDuplicatesMeasuresAgainstTheSitesOwnNormal(): void {
    \Civi::settings()->set('pulse_duplicates_window_days', 7);
    \Civi::settings()->set('pulse_duplicates_threshold', 5);
    \Civi::settings()->set('pulse_duplicates_factor', 3);
    \Civi::settings()->set('pulse_duplicates_baseline', 0);
    for ($window = 1; $window <= 12; $window++) {
      $this->duplicatesAt(3, time() - ((($window * 7) + 3) * 86_400));
    }
    $this->duplicatesAt(7, time() - 3600);
    self::assertSame('ok', \CRM_Pulse_Check_Duplicates::check()['status']);

    $this->duplicatesAt(2, time() - 3600);
    $result = \CRM_Pulse_Check_Duplicates::check();
    self::assertSame('fail', $result['status']);
    self::assertStringContainsString('9 contact(s)', $result['detail']);
    self::assertStringContainsString('normal 3, limit 9', $result['detail']);

    // A fixed normal replaces the measured one.
    \Civi::settings()->set('pulse_duplicates_baseline', 4);
    self::assertSame('ok', \CRM_Pulse_Check_Duplicates::check()['status']);

    // Factor 0 leaves the plain threshold.
    \Civi::settings()->set('pulse_duplicates_baseline', 0);
    \Civi::settings()->set('pulse_duplicates_factor', 0);
    $result = \CRM_Pulse_Check_Duplicates::check();
    self::assertSame('fail', $result['status']);
    self::assertStringContainsString('(threshold 5)', $result['detail']);
  }

  /**
   * Without any history the threshold is the floor, so a new site does not
   * alert on its first duplicate.
   */
  public function testDuplicatesWithoutHistoryFallsToTheThreshold(): void {
    \Civi::settings()->set('pulse_duplicates_window_days', 7);
    \Civi::settings()->set('pulse_duplicates_threshold', 5);
    \Civi::settings()->set('pulse_duplicates_factor', 3);
    \Civi::settings()->set('pulse_duplicates_baseline', 0);
    $this->duplicatesAt(4, time() - 3600);
    self::assertSame('ok', \CRM_Pulse_Check_Duplicates::check()['status']);

    $this->duplicatesAt(1, time() - 3600);
    $result = \CRM_Pulse_Check_Duplicates::check();
    self::assertSame('fail', $result['status']);
    self::assertStringContainsString('normal 0, limit 5', $result['detail']);
  }

  /**
   * $count new contacts created at $timestamp, each sharing an e-mail with an
   * older contact.
   */
  private function duplicatesAt(int $count, int $timestamp): void {
    for ($i = 0; $i < $count; $i++) {
      $email = 'pulse-dup-' . uniqid('', TRUE) . '@example.org';
      $this->contactWithEmail('Old', $email, '2020-01-01 12:00:00');
      $this->contactWithEmail('New', $email, date('Y-m-d H:i:s', $timestamp));
    }
  }

  /**
   * Contact + e-mail with a controlled created_date (the API stamps
   * created_date itself, so it is overwritten directly).
   */
  private function contactWithEmail(string $lastName, string $email, string $createdDate): int {
    $id = (int) \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Pulse')
      ->addValue('last_name', $lastName)
      ->addChain('email', \Civi\Api4\Email::create(checkPermissions: FALSE)
        ->addValue('contact_id', '$id')
        ->addValue('email', $email))
      ->execute()
      ->first()['id'];
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_contact SET created_date = %1 WHERE id = %2',
      [1 => [$createdDate, 'String'], 2 => [$id, 'Integer']],
    );
    return $id;
  }

  /**
   * Every check switched on, the site in good shape. The checks that need
   * another extension's tables are covered in OptionalTablesHeadlessTest.
   */
  private function armAllChecksHealthy(): void {
    \Civi::settings()->set('pulse_updates_min_severity', 'warning');
    \Civi::settings()->set('pulse_updates_ignore', []);
    $this->serveFeed(['pulse' => $this->pulseVersion()]);
    \Civi::settings()->set('pulse_cms_updates_mode', 'security');
    \Civi::settings()->set('pulse_error_window', 60);
    \Civi::settings()->set('pulse_jobs_grace_factor', 3);
    \Civi::settings()->set('pulse_jobs_never_ran_grace_hours', 48);
    $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - 3600));
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue_item');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue');
    \Civi::settings()->set('pulse_queue_max_age_hours', 6);
    $dir = CRM_Pulse_Util::logDir();
    self::assertGreaterThanOrEqual(1.0, ((float) disk_free_space($dir) / (float) disk_total_space($dir)) * 100);
    \Civi::settings()->set('pulse_min_disk_free_percent', 1);
    \Civi::settings()->set(
      'pulse_host_status_file',
      $this->tempFile((string) json_encode([
        'updated' => date('c'),
        'jobs' => ['dump' => ['status' => 'ok', 'finished' => date('c')]],
      ])),
    );
    \Civi::settings()->set('pulse_host_status_environment', NULL);
    \Civi::settings()->set('pulse_duplicates_threshold', 5);
    \Civi::settings()->set('pulse_duplicates_window_days', 7);
  }

  /**
   * The test against false alarms: a healthy site gets no pulse message, read
   * directly and through System.check, the path of herald and the status page.
   */
  public function testHealthySiteGetsNoPulseMessage(): void {
    $this->armAllChecksHealthy();
    self::assertSame([], CRM_Pulse_StatusCheck::messages());
    self::assertSame(
      [],
      System::check(checkPermissions: FALSE)->addWhere('name', 'IN', self::NAMES)->execute()->column('name'),
    );
  }

  public function testFailingChecksBecomeMessagesWithTheirSeverity(): void {
    $this->armAllChecksHealthy();
    $this->serveFeed(['pulse' => '99.0.0']);
    \Civi::settings()->set('pulse_min_disk_free_percent', 100);
    $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (4 * 86_400)));
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_queue (name, type, status, is_template) VALUES ('pulse-test-aborted', 'Sql', 'aborted', 0)",
    );
    \Civi::settings()->set(
      'pulse_host_status_file',
      $this->tempFile((string) json_encode([
        'updated' => date('c'),
        'jobs' => ['dump' => ['status' => 'error', 'finished' => date('c'), 'detail' => '<b>restic</b> exited 1']],
      ])),
    );
    $log = CRM_Pulse_Util::logDir() . '/CiviCRM.pulse-fixture-' . uniqid() . '.log';
    $this->tempFiles[] = $log;
    file_put_contents($log, date('Y-m-d H:i:sO') . "  [critical] boom\n");
    $email = 'pulse-dup-' . uniqid() . '@example.org';
    $this->contactWithEmail('Old', $email, '2020-01-01 12:00:00');
    $this->contactWithEmail('Fresh', $email, date('Y-m-d H:i:s'));
    \Civi::settings()->set('pulse_duplicates_threshold', 1);

    $messages = self::byName(CRM_Pulse_StatusCheck::messages());
    self::assertSame(
      [
        'pulse_disk' => LogLevel::WARNING,
        'pulse_duplicates' => LogLevel::WARNING,
        'pulse_fatal_errors' => LogLevel::ERROR,
        'pulse_host_status' => LogLevel::ERROR,
        'pulse_jobs' => LogLevel::WARNING,
        'pulse_queues' => LogLevel::WARNING,
        'pulse_updates' => LogLevel::WARNING,
      ],
      array_map(static fn (\CRM_Utils_Check_Message $m): string => $m->getSeverity(), $messages),
    );
    foreach ($messages as $name => $message) {
      self::assertNotSame('', $message->getTitle(), "{$name} has no title");
    }
    // Details are text: markup in them arrives escaped.
    self::assertStringContainsString('&lt;b&gt;restic&lt;/b&gt;', $messages['pulse_host_status']->getMessage());

    $viaApi = System::check(checkPermissions: FALSE)->addWhere('name', 'IN', self::NAMES)->execute()->column('name');
    sort($viaApi);
    self::assertSame(array_keys($messages), $viaApi);
  }

  public function testRequestedStatusNamesLimitTheChecksThatRun(): void {
    $this->armAllChecksHealthy();
    \Civi::settings()->set('pulse_min_disk_free_percent', 100);
    $this->soleActiveJob('Daily', date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (4 * 86_400)));
    self::assertSame(['pulse_disk'], array_keys(self::byName(CRM_Pulse_StatusCheck::messages(['pulse_disk']))));
    self::assertSame(
      ['pulse_disk', 'pulse_jobs'],
      array_keys(self::byName(CRM_Pulse_StatusCheck::messages(['checkLastCron', 'pulse_jobs', 'pulse_disk']))),
    );
    self::assertSame([], CRM_Pulse_StatusCheck::messages(['checkLastCron']));
  }

  /**
   * The disable convention: a switched-off check is no finding.
   */
  public function testDisabledChecksGetNoMessage(): void {
    \Civi::settings()->set('pulse_min_disk_free_percent', 0);
    \Civi::settings()->set('pulse_updates_min_severity', 'off');
    \Civi::settings()->set('pulse_cms_updates_mode', 'off');
    \Civi::settings()->set('pulse_jobs_grace_factor', 0);
    \Civi::settings()->set('pulse_jobs_never_ran_grace_hours', 0);
    \Civi::settings()->set('pulse_host_status_file', NULL);
    \Civi::settings()->set('pulse_error_window', 0);
    \Civi::settings()->set('pulse_duplicates_threshold', 0);
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue_item');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue');
    self::assertSame([], CRM_Pulse_StatusCheck::messages());
  }

}
