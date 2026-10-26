<?php

declare(strict_types = 1);

use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * pulse_fatal_errors against real files in the real ConfigAndLog directory.
 *
 * The marker regex itself is unit-tested; what is covered here is the scanning
 * around it — the glob, the mtime window, the tail limit, and the setting that
 * switches the leg off. Every assertion is a DELTA against the count the site
 * already produces: the check reads every CiviCRM*.log in the directory, so
 * whatever the boot or another test left behind must not decide the outcome.
 *
 * @group headless
 */
class CRM_Pulse_Check_LogScanHeadlessTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * @var list<string>
   */
  private array $logFiles = [];

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  protected function tearDown(): void {
    foreach ($this->logFiles as $file) {
      if (!file_exists($file)) {
        continue;
      }

      unlink($file);
    }
    $this->logFiles = [];
    parent::tearDown();
  }

  /**
   * Drop a log file the check will pick up (it globs CiviCRM*.log).
   */
  private function writeLog(string $contents): string {
    $dir = CRM_Pulse_Util::logDir();
    $file = $dir . '/CiviCRM.pulse-fixture-' . uniqid() . '.log';
    $this->logFiles[] = $file;
    file_put_contents($file, $contents);
    return $file;
  }

  /**
   * One ConfigAndLog line as CRM_Core_Error's logger writes it:
   * `Y-m-d H:i:sO`, empty ident, `[level] message`.
   */
  private function logLine(int $timestamp, string $rest): string {
    return date('Y-m-d H:i:sO', $timestamp) . '  ' . $rest . "\n";
  }

  /**
   * How many fatal markers the check currently sees (0 when it is not failing).
   */
  private function fatalCount(): int {
    $r = CRM_Pulse_Check_FatalErrors::check();
    if ($r['status'] !== 'fail') {
      return 0;
    }
    preg_match('/^(\d+) fatal/', (string) $r['detail'], $m);
    return (int) ($m[1] ?? 0);
  }

  public function testFatalErrorsScanCountsMarkersAndHonoursTheWindow(): void {
    \Civi::settings()->set('pulse_error_window', 60);
    $before = $this->fatalCount();
    $now = time();

    $file = $this->writeLog(
      $this->logLine($now - 300, '[info] routine')
        . $this->logLine($now - 240, '[error] PHP Fatal error:  Uncaught TypeError in /x.php:1')
        . $this->logLine($now - 180, '[critical] boom'),
    );
    self::assertSame($before + 2, $this->fatalCount());
    self::assertStringContainsString(
      'fatal-error marker(s) in CiviCRM logs touched within 60 min',
      (string) CRM_Pulse_Check_FatalErrors::check()['detail'],
    );

    // 0 disables the leg outright — the filesystem is not touched at all.
    \Civi::settings()->set('pulse_error_window', 0);
    self::assertSame('skipped', CRM_Pulse_Check_FatalErrors::check()['status']);

    // A file last touched outside the window is history, not a current fault.
    \Civi::settings()->set('pulse_error_window', 60);
    touch($file, time() - 7200);
    self::assertSame($before, $this->fatalCount());
  }

  /**
   * A busy site keeps its log file's mtime current forever, so the file stays
   * in scope. The entries inside it still have to age out — otherwise the
   * check never returns to 'ok' and herald's transition monitor goes silent
   * for every incident after the first.
   */
  public function testFatalErrorsIgnoreOldEntriesInAFreshlyWrittenFile(): void {
    \Civi::settings()->set('pulse_error_window', 60);
    $before = $this->fatalCount();
    $now = time();

    $file = $this->writeLog(
      $this->logLine($now - 10_800, '[critical] boom, three hours ago')
        . $this->logLine($now - 120, '[info] routine chatter keeping the file warm'),
    );
    self::assertSame($before, $this->fatalCount(), 'an entry outside the window must not count');

    // The same file with a CURRENT fatal must still fail.
    file_put_contents($file, $this->logLine($now - 60, '[critical] boom, one minute ago'), FILE_APPEND);
    self::assertSame($before + 1, $this->fatalCount());
  }

  /**
   * Only the tail of a large file is read, so a marker before that cut-off is
   * deliberately not reported: the check asks what is happening now, and
   * re-reading multi-megabyte logs on every cron tick is not affordable.
   */
  public function testFatalErrorsOnlyScanTheTailOfALargeFile(): void {
    \Civi::settings()->set('pulse_error_window', 60);
    $before = $this->fatalCount();

    $now = time();
    $filler = str_repeat($this->logLine($now - 120, '[info] routine chatter that fills the file up'), 6000);
    self::assertGreaterThan(262_144, strlen($filler), 'the filler must exceed the tail window');
    $file = $this->writeLog(
      $this->logLine($now - 300, '[error] PHP Fatal error:  ancient, long since scrolled away') . $filler,
    );
    self::assertSame($before, $this->fatalCount());

    // The same marker at the END of the file is inside the tail and counts.
    file_put_contents($file, $this->logLine($now - 60, '[error] PHP Fatal error:  fresh one'), FILE_APPEND);
    self::assertSame($before + 1, $this->fatalCount());
  }

  /**
   * pulse_disk and both log legs resolve the same directory. If that ever
   * stops pointing at ConfigAndLog, three checks go quiet at once.
   */
  public function testLogDirResolvesToTheConfigAndLogDirectory(): void {
    $dir = CRM_Pulse_Util::logDir();
    self::assertDirectoryExists($dir);
    self::assertSame(rtrim($dir, '/'), $dir, 'the trailing slash must be normalised away');
  }

}
