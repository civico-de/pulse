<?php

declare(strict_types = 1);

namespace Civi\Pulse;

use Civi\Api4\System;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint is public-routed and security-relevant (token gate), so every
 * branch of HealthEndpoint::evaluate() gets covered headlessly.
 *
 * @group headless
 */
class HealthEndpointHeadlessTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  // Test fixture, not a credential: the endpoint refuses short tokens.
  // @mago-expect lint:no-literal-password
  private const TOKEN = 'test-token-with-plenty-of-entropy';

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->set('pulse_token', self::TOKEN);
    // Keep the default checks deterministic: cron + system checks off unless
    // a test opts in.
    \Civi::settings()->set('pulse_max_cron_age', 0);
    \Civi::settings()->set('pulse_min_severity', 'off');
    \Civi::cache('long')->delete('pulse_system_check');
  }

  /**
   * @return array<string, mixed>
   */
  private function evaluate(#[\SensitiveParameter] ?string $token): array {
    [$code, $body] = HealthEndpoint::evaluate($token);
    return ['code' => $code] + $body;
  }

  public function testUnconfiguredTokenRefusesInsteadOfOpening(): void {
    \Civi::settings()->set('pulse_token', NULL);
    $r = $this->evaluate('anything');
    self::assertSame(503, $r['code']);
    self::assertSame('unconfigured', $r['status']);
  }

  public function testWrongOrMissingTokenIsForbidden(): void {
    self::assertSame(403, $this->evaluate('wrong')['code']);
    self::assertSame(403, $this->evaluate(NULL)['code']);
    self::assertSame(403, $this->evaluate('')['code']);
  }

  public function testHealthySiteAnswersOk(): void {
    $r = $this->evaluate(self::TOKEN);
    self::assertSame(200, $r['code']);
    self::assertSame('ok', $r['status']);
    self::assertSame('ok', $r['checks']['database']['status']);
    self::assertSame('skipped', $r['checks']['cron']['status']);
    self::assertSame('skipped', $r['checks']['system']['status']);
    self::assertSame('skipped', $r['checks']['host_status']['status']);
  }

  public function testHostStatusIsInformationalAndNeverFlipsTheHttpStatus(): void {
    // A missing status file is a finding, but a late backup is not an
    // outage: the endpoint stays 200 and only carries the check along.
    \Civi::settings()->set('pulse_host_status_file', '/nonexistent/pulse/host-status.json');
    try {
      $r = $this->evaluate(self::TOKEN);
      self::assertSame(200, $r['code']);
      self::assertSame('ok', $r['status']);
      self::assertSame('fail', $r['checks']['host_status']['status']);
    }
    finally {
      \Civi::settings()->set('pulse_host_status_file', NULL);
    }
  }

  private function logCronTick(int $secondsAgo): void {
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civicrm_job_log (domain_id, run_time, name, command) VALUES (%1, %2, %3, %4)',
      [
        1 => [\CRM_Core_Config::domainID(), 'Integer'],
        2 => [date('Y-m-d H:i:s', \CRM_Utils_Time::time() - $secondsAgo), 'String'],
        3 => ['pulse test', 'String'],
        4 => ['Starting scheduled jobs execution', 'String'],
      ],
    );
  }

  public function testStaleCronFailsAgainstConfiguredMaxAge(): void {
    \Civi::settings()->set('pulse_max_cron_age', 60);
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_job_log');
    $this->logCronTick(7200);

    $r = $this->evaluate(self::TOKEN);
    self::assertSame(503, $r['code']);
    self::assertSame('fail', $r['checks']['cron']['status']);
    self::assertGreaterThanOrEqual(119, $r['checks']['cron']['age_minutes']);
  }

  public function testFreshCronPasses(): void {
    \Civi::settings()->set('pulse_max_cron_age', 60);
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_job_log');
    $this->logCronTick(60);

    $r = $this->evaluate(self::TOKEN);
    self::assertSame('ok', $r['checks']['cron']['status']);
  }

  public function testFreshCronTickPassesEvenWhenNoJobWasDue(): void {
    // A weekly-only job leaves civicrm_job.last_run old for days; the runner is
    // alive nonetheless and must not be reported as failing.
    \Civi::settings()->set('pulse_max_cron_age', 60);
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_job SET is_active = 0, last_run = NULL');
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_job SET is_active = 1, run_frequency = "Weekly", last_run = %1 WHERE id = (SELECT * FROM (SELECT MIN(id) FROM civicrm_job) x)',
      [1 => [date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (5 * 86_400)), 'String']],
    );
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_job_log');
    $this->logCronTick(60);

    $r = $this->evaluate(self::TOKEN);
    self::assertSame('ok', $r['checks']['cron']['status']);
  }

  public function testCronWithoutAnyRunEverFails(): void {
    \Civi::settings()->set('pulse_max_cron_age', 60);
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_job_log');

    $r = $this->evaluate(self::TOKEN);
    self::assertSame('fail', $r['checks']['cron']['status']);
    self::assertSame('cron has never run', $r['checks']['cron']['detail']);
  }

  public function testInvalidSeveritySettingFailsClosed(): void {
    \Civi::settings()->set('pulse_min_severity', 'not-a-severity');
    $r = $this->evaluate(self::TOKEN);
    self::assertSame(503, $r['code']);
    self::assertSame('fail', $r['checks']['system']['status']);
  }

  public function testSystemCheckResultIsCached(): void {
    \Civi::settings()->set('pulse_min_severity', 'critical');
    $first = $this->evaluate(self::TOKEN);
    self::assertContains($first['checks']['system']['status'], ['ok', 'fail']);

    // Second call must come from the 5-minute cache, not a fresh run.
    $cached = \Civi::cache('long')->get('pulse_system_check');
    self::assertIsArray($cached);
    self::assertSame($cached['status'], $this->evaluate(self::TOKEN)['checks']['system']['status']);
  }

  public function testPulseMessagesNeverFailTheEndpoint(): void {
    // Free space is always below 100 percent, so pulse_disk reports a warning.
    \Civi::settings()->set('pulse_min_disk_free_percent', 100);
    $disk = System::check(checkPermissions: FALSE)->addWhere('name', '=', 'pulse_disk')->execute()->first();
    self::assertIsArray($disk);
    self::assertTrue((bool) $disk['is_visible']);

    \Civi::settings()->set('pulse_min_severity', 'warning');
    $system = $this->evaluate(self::TOKEN)['checks']['system'];
    self::assertNotContains('pulse_disk', $system['failing'] ?? []);
  }

  public function testHandleReturnsJsonResponse(): void {
    $response = HealthEndpoint::handle(
      new Request('GET', '/civicrm/pulse?token=' . self::TOKEN),
    );
    self::assertSame(200, $response->getStatusCode());
    self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    $body = json_decode((string) $response->getBody(), TRUE);
    self::assertSame('ok', $body['status']);
  }

  public function testHandleWithoutTokenIsForbidden(): void {
    $response = HealthEndpoint::handle(new Request('GET', '/civicrm/pulse'));
    self::assertSame(403, $response->getStatusCode());
    self::assertSame('{"status":"forbidden"}', (string) $response->getBody());
  }

}
