<?php

declare(strict_types = 1);

use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * pulse_civirules and pulse_payments watch tables that belong to OTHER
 * extensions (CiviRules, mjwshared). Neither is installed in CI, and pulling
 * them in would make this suite depend on their release cadence — so the
 * tables the checks read are created here directly, with the columns the
 * queries actually name and nothing else. What is under test is our SQL and
 * our verdicts, not their schema.
 *
 * Deliberately NOT transactional: DDL commits implicitly in MySQL, which would
 * silently defeat the rollback. The tables are dropped again in tearDown, and
 * every settings change is restored.
 *
 * @group headless
 */
class CRM_Pulse_Check_OptionalTablesHeadlessTest extends TestCase implements HeadlessInterface {

  private const CIVIRULE_TABLES = [
    'civirule_rule_action',
    'civirule_rule_condition',
    'civirule_rule',
    'civirule_trigger',
    'civirule_condition',
    'civirule_action',
  ];

  private const WEBHOOK_TABLE = 'civicrm_paymentprocessor_webhook';

  /**
   * @var array<string, mixed>
   */
  private array $savedSettings = [];

  /**
   * @var list<string>
   */
  private array $logFiles = [];

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    foreach (['pulse_error_window', 'pulse_payment_webhook_max_age_hours'] as $key) {
      $this->savedSettings[$key] = \Civi::settings()->get($key);
    }
    $this->dropTables();
  }

  protected function tearDown(): void {
    $this->dropTables();
    foreach ($this->savedSettings as $key => $value) {
      \Civi::settings()->set($key, $value);
    }
    $this->savedSettings = [];
    foreach ($this->logFiles as $file) {
      if (!file_exists($file)) {
        continue;
      }

      unlink($file);
    }
    $this->logFiles = [];
    parent::tearDown();
  }

  private function dropTables(): void {
    foreach (array_merge(self::CIVIRULE_TABLES, [self::WEBHOOK_TABLE]) as $table) {
      \CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS `{$table}`");
    }
  }

  /**
   * One ConfigAndLog line as CRM_Core_Error's logger writes it:
   * `Y-m-d H:i:sO`, empty ident, `[level] message`.
   */
  private function logLine(int $timestamp, string $rest): string {
    return date('Y-m-d H:i:sO', $timestamp) . '  ' . $rest . "\n";
  }

  /**
   * The subset of CiviRules' schema the check reads.
   */
  private function createCivirulesTables(): void {
    \CRM_Core_DAO::executeQuery(
      'CREATE TABLE civirule_rule (
         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
         trigger_id INT UNSIGNED NULL,
         is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB',
    );
    \CRM_Core_DAO::executeQuery(
      'CREATE TABLE civirule_trigger (
         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
         class_name VARCHAR(255) NULL,
         is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB',
    );
    \CRM_Core_DAO::executeQuery(
      'CREATE TABLE civirule_condition (
         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
         class_name VARCHAR(255) NULL) ENGINE=InnoDB',
    );
    \CRM_Core_DAO::executeQuery(
      'CREATE TABLE civirule_action (
         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
         class_name VARCHAR(255) NULL) ENGINE=InnoDB',
    );
    \CRM_Core_DAO::executeQuery(
      'CREATE TABLE civirule_rule_condition (
         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
         rule_id INT UNSIGNED NOT NULL,
         condition_id INT UNSIGNED NULL,
         condition_link VARCHAR(3) NULL,
         condition_params TEXT NULL,
         weight INT DEFAULT 1,
         is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB',
    );
    \CRM_Core_DAO::executeQuery(
      'CREATE TABLE civirule_rule_action (
         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
         rule_id INT UNSIGNED NOT NULL,
         action_id INT UNSIGNED NULL,
         action_params TEXT NULL,
         is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB',
    );
  }

  /**
   * One healthy active rule: loadable trigger, one condition, one action.
   */
  private function seedHealthyRule(): void {
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_trigger (id, class_name, is_active) VALUES (1, 'CRM_Pulse_Util', 1)",
    );
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_condition (id, class_name) VALUES (1, 'CRM_Pulse_Util')",
    );
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_action (id, class_name) VALUES (1, 'CRM_Pulse_Util')",
    );
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule (id, trigger_id, is_active) VALUES (1, 1, 1)',
    );
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_condition (id, rule_id, condition_id, condition_link, condition_params, weight, is_active)
       VALUES (1, 1, 1, NULL, %1, 1, 1)',
      [1 => [serialize(['operator' => '>']), 'String']],
    );
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_action (id, rule_id, action_id, action_params, is_active)
       VALUES (1, 1, 1, %1, 1)',
      [1 => [serialize([]), 'String']],
    );
  }

  public function testCivirulesSkippedWhenTheExtensionIsAbsent(): void {
    self::assertFalse(\CRM_Core_DAO::checkTableExists('civirule_rule_condition'));
    self::assertSame('skipped', CRM_Pulse_Check_Civirules::check()['status']);
  }

  public function testCivirulesPassesOnAHealthyRuleSet(): void {
    \Civi::settings()->set('pulse_error_window', 0);
    $this->createCivirulesTables();
    $this->seedHealthyRule();
    self::assertSame('ok', CRM_Pulse_Check_Civirules::check()['status']);
  }

  /**
   * A real incident: a condition added by hand without a
   * condition_link aborted the evaluation of the WHOLE rule, silently.
   */
  public function testCivirulesFlagsAConditionWithoutAValidLink(): void {
    \Civi::settings()->set('pulse_error_window', 0);
    $this->createCivirulesTables();
    $this->seedHealthyRule();

    // The FIRST condition (lowest weight) legitimately has no link.
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule_condition (id, rule_id, condition_id, condition_link, weight, is_active)
       VALUES (2, 1, 1, 'AND', 2, 1)",
    );
    self::assertSame('ok', CRM_Pulse_Check_Civirules::check()['status']);

    // The second one without a link is the fault.
    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_condition SET condition_link = NULL WHERE id = 2');
    $r = CRM_Pulse_Check_Civirules::check();
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString('condition_link invalid', $r['detail']);
    self::assertStringContainsString('ids 2', $r['detail']);

    // Garbage in the column is no better than NULL.
    \CRM_Core_DAO::executeQuery("UPDATE civirule_rule_condition SET condition_link = 'XOR' WHERE id = 2");
    self::assertSame('fail', CRM_Pulse_Check_Civirules::check()['status']);

    // Lower case is fine — CiviRules compares case-insensitively.
    \CRM_Core_DAO::executeQuery("UPDATE civirule_rule_condition SET condition_link = 'or' WHERE id = 2");
    self::assertSame('ok', CRM_Pulse_Check_Civirules::check()['status']);

    // An INACTIVE condition is not evaluated, so its link does not matter.
    \CRM_Core_DAO::executeQuery(
      'UPDATE civirule_rule_condition SET condition_link = NULL, is_active = 0 WHERE id = 2',
    );
    self::assertSame('ok', CRM_Pulse_Check_Civirules::check()['status']);
  }

  public function testCivirulesFlagsBrokenReferencesAndParams(): void {
    \Civi::settings()->set('pulse_error_window', 0);
    $this->createCivirulesTables();
    $this->seedHealthyRule();

    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule SET trigger_id = 99 WHERE id = 1');
    self::assertStringContainsString(
      'rule 1: trigger 99 missing',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule SET trigger_id = 1 WHERE id = 1');
    \CRM_Core_DAO::executeQuery('UPDATE civirule_trigger SET is_active = 0 WHERE id = 1');
    self::assertStringContainsString(
      'rule 1: trigger inactive',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    \CRM_Core_DAO::executeQuery('UPDATE civirule_trigger SET is_active = 1 WHERE id = 1');
    \CRM_Core_DAO::executeQuery("UPDATE civirule_trigger SET class_name = 'CRM_No_Such_Class' WHERE id = 1");
    self::assertStringContainsString(
      'trigger class CRM_No_Such_Class not loadable',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    \CRM_Core_DAO::executeQuery("UPDATE civirule_trigger SET class_name = 'CRM_Pulse_Util' WHERE id = 1");
    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_condition SET condition_id = 99 WHERE id = 1');
    self::assertStringContainsString(
      'condition definition missing',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_condition SET condition_id = 1 WHERE id = 1');
    \CRM_Core_DAO::executeQuery("UPDATE civirule_condition SET class_name = 'CRM_No_Such_Condition' WHERE id = 1");
    self::assertStringContainsString(
      'class CRM_No_Such_Condition not loadable',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    \CRM_Core_DAO::executeQuery("UPDATE civirule_condition SET class_name = 'CRM_Pulse_Util' WHERE id = 1");
    \CRM_Core_DAO::executeQuery(
      "UPDATE civirule_rule_condition SET condition_params = 'a:2:{s:8:\"operator\";s:1:' WHERE id = 1",
    );
    self::assertStringContainsString(
      'params not unserializable',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_condition SET condition_params = NULL WHERE id = 1');
    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_action SET action_id = 99 WHERE id = 1');
    self::assertStringContainsString(
      'action definition missing',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_action SET action_id = 1 WHERE id = 1');
    \CRM_Core_DAO::executeQuery("UPDATE civirule_action SET class_name = 'CRM_No_Such_Action' WHERE id = 1");
    self::assertStringContainsString(
      'class CRM_No_Such_Action not loadable',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    \CRM_Core_DAO::executeQuery("UPDATE civirule_action SET class_name = 'CRM_Pulse_Util' WHERE id = 1");
    \CRM_Core_DAO::executeQuery(
      "UPDATE civirule_rule_action SET action_params = 'a:1:{s:3:\"foo\"' WHERE id = 1",
    );
    self::assertStringContainsString(
      'params not unserializable',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    // A rule whose only action is switched off runs into the void.
    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_action SET action_params = NULL WHERE id = 1');
    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_action SET is_active = 0 WHERE id = 1');
    self::assertStringContainsString(
      'rule 1: active but has no active action',
      (string) CRM_Pulse_Check_Civirules::check()['detail'],
    );

    // An INACTIVE rule is nobody's problem.
    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule SET is_active = 0 WHERE id = 1');
    self::assertSame('ok', CRM_Pulse_Check_Civirules::check()['status']);
  }

  /**
   * The log leg only runs once the config lint is clean — a structural fault
   * is the more actionable message, so it wins.
   */
  public function testCivirulesLogLegCountsCiviRulesErrorLines(): void {
    $this->createCivirulesTables();
    $this->seedHealthyRule();
    \Civi::settings()->set('pulse_error_window', 60);

    $dir = CRM_Pulse_Util::logDir();
    $file = $dir . '/CiviCRM.pulse-civirules-' . uniqid() . '.log';
    $this->logFiles[] = $file;
    $now = time();
    file_put_contents(
      $file,
      $this->logLine($now - 300, '[error] CiviRules: RuleID: 46 has invalid condition_link operator:')
        . $this->logLine($now - 240, '[error] some unrelated handled error'),
    );

    $r = CRM_Pulse_Check_Civirules::check();
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString('CiviRules error line(s)', $r['detail']);

    // A file last written outside the window is history, not a live fault.
    touch($file, time() - 7200);
    self::assertSame('ok', CRM_Pulse_Check_Civirules::check()['status']);

    // 0 disables the log leg; the rule set itself is clean.
    touch($file);
    \Civi::settings()->set('pulse_error_window', 0);
    self::assertSame('ok', CRM_Pulse_Check_Civirules::check()['status']);
  }

  /**
   * Like the fatal-error scan, only the tail of a large log is read — a
   * CiviRules error that has already scrolled out of that window is not a
   * current fault, and re-reading whole logs every cron tick is not affordable.
   */
  public function testCivirulesLogLegOnlyReadsTheTailOfALargeLog(): void {
    $this->createCivirulesTables();
    $this->seedHealthyRule();
    \Civi::settings()->set('pulse_error_window', 60);

    $dir = CRM_Pulse_Util::logDir();
    $file = $dir . '/CiviCRM.pulse-civirules-tail-' . uniqid() . '.log';
    $this->logFiles[] = $file;
    $now = time();
    // Every entry sits inside the time window: what has to hide the first one
    // is the tail cut-off, not its age.
    $filler = str_repeat($this->logLine($now - 120, '[info] CiviRules: rule executed fine'), 6000);
    self::assertGreaterThan(262_144, strlen($filler), 'the filler must exceed the tail window');
    file_put_contents(
      $file,
      $this->logLine($now - 300, '[error] CiviRules: failure long since scrolled away') . $filler,
    );
    self::assertSame('ok', CRM_Pulse_Check_Civirules::check()['status']);

    file_put_contents($file, $this->logLine($now - 60, '[error] CiviRules: fresh failure'), FILE_APPEND);
    self::assertSame('fail', CRM_Pulse_Check_Civirules::check()['status']);
  }

  /**
   * Cores predating the queue registry have no `civicrm_queue` table. There
   * the check steps aside rather than emitting a query against nothing — the
   * same reflex that keeps it quiet about CiviRules and mjwshared.
   */
  public function testQueuesSkippedOnCoresWithoutTheQueueRegistry(): void {
    \CRM_Core_DAO::executeQuery('RENAME TABLE civicrm_queue TO pulse_test_queue_backup');
    try {
      self::assertSame('skipped', CRM_Pulse_Check_Queues::check()['status']);
    }
    finally {
      \CRM_Core_DAO::executeQuery('RENAME TABLE pulse_test_queue_backup TO civicrm_queue');
    }
  }

  // The flag models the two real schema variants the check must survive.
  // @mago-expect lint:no-boolean-flag-parameter
  private function createWebhookTable(bool $withProcessedDate = TRUE): void {
    $processed = $withProcessedDate ? ', processed_date TIMESTAMP NULL' : '';
    \CRM_Core_DAO::executeQuery(
      'CREATE TABLE ' . self::WEBHOOK_TABLE . ' (
         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
         status VARCHAR(32) NULL,
         created_date TIMESTAMP NULL' . $processed . ') ENGINE=InnoDB',
    );
  }

  public function testPaymentsSkippedWithoutMjwshared(): void {
    self::assertSame('skipped', CRM_Pulse_Check_Payments::check()['status']);
  }

  /**
   * mjwshared could rename its columns. Rather than emitting a query that
   * would explode, the check confirms them first: it could not check, an error.
   */
  public function testPaymentsReportAnErrorWhenTheSchemaDoesNotMatch(): void {
    \CRM_Core_DAO::executeQuery(
      'CREATE TABLE ' . self::WEBHOOK_TABLE . ' (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB',
    );
    $r = CRM_Pulse_Check_Payments::check();
    self::assertSame('fail', $r['status']);
    self::assertSame(LogLevel::ERROR, $r['severity']);
    self::assertStringContainsString('could not check', $r['detail']);
  }

  /**
   * The tables of the other extensions present and in good shape: no message.
   */
  public function testHealthyOptionalTablesGetNoMessage(): void {
    \Civi::settings()->set('pulse_error_window', 0);
    \Civi::settings()->set('pulse_payment_webhook_max_age_hours', 2);
    $this->createCivirulesTables();
    $this->seedHealthyRule();
    $this->createWebhookTable();
    self::assertSame([], CRM_Pulse_StatusCheck::messages(['pulse_civirules', 'pulse_payments']));

    \CRM_Core_DAO::executeQuery(
      'INSERT INTO ' . self::WEBHOOK_TABLE . ' (status, created_date, processed_date) VALUES (%1, %2, %2)',
      [1 => ['error', 'String'], 2 => [date('Y-m-d H:i:s'), 'String']],
    );
    \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_action SET is_active = 0');
    $severities = [];
    foreach (CRM_Pulse_StatusCheck::messages(['pulse_civirules', 'pulse_payments']) as $message) {
      $severities[$message->getName()] = $message->getSeverity();
    }
    ksort($severities);
    self::assertSame(['pulse_civirules' => LogLevel::ERROR, 'pulse_payments' => LogLevel::ERROR], $severities);
  }

  /**
   * A check that throws could not check: an error under its own name, never silence.
   */
  public function testACheckThatThrowsBecomesAnErrorMessage(): void {
    // The condition table alone: the first query joins civirule_rule, which is missing.
    \CRM_Core_DAO::executeQuery(
      'CREATE TABLE civirule_rule_condition (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB',
    );
    $messages = CRM_Pulse_StatusCheck::messages(['pulse_civirules']);
    self::assertCount(1, $messages);
    self::assertSame('pulse_civirules', $messages[0]->getName());
    self::assertSame(LogLevel::ERROR, $messages[0]->getSeverity());
    self::assertStringStartsWith('Could not check: ', $messages[0]->getMessage());
  }

  public function testPaymentsFlagsUndrainedAndErroredWebhooks(): void {
    \Civi::settings()->set('pulse_payment_webhook_max_age_hours', 2);
    $this->createWebhookTable();
    self::assertSame('ok', CRM_Pulse_Check_Payments::check()['status']);

    // Fresh arrivals are not stuck.
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO ' . self::WEBHOOK_TABLE . ' (status, created_date) VALUES (%1, %2)',
      [1 => ['new', 'String'], 2 => [date('Y-m-d H:i:s'), 'String']],
    );
    self::assertSame('ok', CRM_Pulse_Check_Payments::check()['status']);

    // Rows sitting in new/processing past the threshold are.
    $old = date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (5 * 3600));
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO ' . self::WEBHOOK_TABLE . ' (status, created_date) VALUES (%1, %2), (%3, %2)',
      [1 => ['new', 'String'], 2 => [$old, 'String'], 3 => ['processing', 'String']],
    );
    $r = CRM_Pulse_Check_Payments::check();
    self::assertSame('fail', $r['status']);
    self::assertStringContainsString('2 webhook(s) stuck in new/processing, oldest 5h (max 2h)', $r['detail']);

    // 'success' rows are done, however old.
    \CRM_Core_DAO::executeQuery('UPDATE ' . self::WEBHOOK_TABLE . " SET status = 'success'");
    self::assertSame('ok', CRM_Pulse_Check_Payments::check()['status']);

    // 0 disables only the age leg — an error inside the 24h window still fails.
    \Civi::settings()->set('pulse_payment_webhook_max_age_hours', 0);
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO ' . self::WEBHOOK_TABLE . ' (status, created_date, processed_date) VALUES (%1, %2, %3)',
      [
        1 => ['error', 'String'],
        2 => [date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (20 * 86_400)), 'String'],
        3 => [date('Y-m-d H:i:s', \CRM_Utils_Time::time() - 3600), 'String'],
      ],
    );
    $errored = CRM_Pulse_Check_Payments::check();
    self::assertSame('fail', $errored['status']);
    self::assertStringContainsString('1 webhook(s) errored in the last 24h', $errored['detail']);
  }

  /**
   * Errors are dated by when they ERRORED, not when the webhook arrived: a
   * days-old webhook that failed today must count, and one that failed weeks
   * ago must not.
   */
  public function testPaymentsDateErrorsByProcessedDateWhenAvailable(): void {
    \Civi::settings()->set('pulse_payment_webhook_max_age_hours', 0);
    $this->createWebhookTable();
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO ' . self::WEBHOOK_TABLE . ' (status, created_date, processed_date) VALUES (%1, %2, %3)',
      [
        1 => ['error', 'String'],
        2 => [date('Y-m-d H:i:s', \CRM_Utils_Time::time() - 3600), 'String'],
        3 => [date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (40 * 86_400)), 'String'],
      ],
    );
    // Arrived an hour ago, but failed forty days ago: outside the window.
    self::assertSame('ok', CRM_Pulse_Check_Payments::check()['status']);

    // On an older schema without processed_date the check falls back to
    // created_date instead of dropping the error leg.
    \CRM_Core_DAO::executeQuery('DROP TABLE ' . self::WEBHOOK_TABLE);
    $this->createWebhookTable(withProcessedDate: FALSE);
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO ' . self::WEBHOOK_TABLE . ' (status, created_date) VALUES (%1, %2)',
      [1 => ['error', 'String'], 2 => [date('Y-m-d H:i:s', \CRM_Utils_Time::time() - 3600), 'String']],
    );
    self::assertSame('fail', CRM_Pulse_Check_Payments::check()['status']);
  }

}
