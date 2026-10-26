<?php

declare(strict_types = 1);

use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Upgrade steps run through core's queue adapter, as `cv ext:upgrade-db` runs them.
 *
 * @group headless
 */
class CRM_Pulse_UpgraderTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function testFreshInstallHasNoPendingRevision(): void {
    $upgrader = CRM_Extension_System::singleton()->getMapper()->getUpgrader('pulse');
    self::assertInstanceOf(CRM_Pulse_Upgrader::class, $upgrader);
    self::assertFalse($upgrader->hasPendingRevisions());
  }

  public function testUpgrade1000MovesTheHostStatusSettingsToTheirNewNames(): void {
    $this->insertRetired('pulse_civenv_status_file', serialize('/var/lib/host/status.json'));
    $this->insertRetired('pulse_civenv_environment', serialize('prod'));
    $this->insertRetired('pulse_civenv_max_age', serialize(30));

    self::assertTrue($this->runUpgrade1000());

    $settings = Civi::settings();
    self::assertSame('/var/lib/host/status.json', $settings->get('pulse_host_status_file'));
    self::assertSame('prod', $settings->get('pulse_host_status_environment'));
    self::assertSame(30, $settings->get('pulse_host_status_max_age'));
    self::assertSame(0, $this->countRetiredRows());
    // The check reads the moved values: configured, so no longer skipped.
    self::assertSame('fail', CRM_Pulse_Check_HostStatus::check()['status']);
  }

  public function testUpgrade1000KeepsAValueAlreadySetUnderTheNewName(): void {
    Civi::settings()->set('pulse_host_status_environment', 'staging');
    $this->insertRetired('pulse_civenv_environment', serialize('prod'));
    // A reverted setting leaves a row holding the literal 'null'; it carries nothing over.
    $this->insertRetired('pulse_civenv_status_file', 'null');

    self::assertTrue($this->runUpgrade1000());

    self::assertSame('staging', Civi::settings()->get('pulse_host_status_environment'));
    self::assertFalse(Civi::settings()->hasExplicit('pulse_host_status_file'));
    self::assertSame(0, $this->countRetiredRows());
  }

  public function testUpgrade1000MovesEachDomainsValueIntoThatDomain(): void {
    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_domain (name, version) VALUES ('Pulse upgrade test', %1)", [
      1 => [CRM_Utils_System::version(), 'String'],
    ]);
    $second = (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    $this->insertRetired('pulse_civenv_environment', serialize('prod'));
    $this->insertRetired('pulse_civenv_environment', serialize('staging'), $second);

    self::assertTrue($this->runUpgrade1000());

    self::assertSame('prod', Civi::settings()->get('pulse_host_status_environment'));
    self::assertSame('staging', Civi::settings($second)->get('pulse_host_status_environment'));
    self::assertSame(0, $this->countRetiredRows());
  }

  public function testUpgrade1000WithoutRetiredSettingsChangesNothing(): void {
    self::assertTrue($this->runUpgrade1000());
    self::assertFalse(Civi::settings()->hasExplicit('pulse_host_status_file'));
    self::assertSame('skipped', CRM_Pulse_Check_HostStatus::check()['status']);
  }

  private function runUpgrade1000(): bool {
    $ctx = new CRM_Queue_TaskContext();
    $ctx->log = CRM_Core_Error::createDebugLogger();
    return CRM_Pulse_Upgrader::_queueAdapter($ctx, 'pulse', 'upgrade_1000');
  }

  private function insertRetired(string $name, string $serialized, ?int $domainId = NULL): void {
    CRM_Core_DAO::executeQuery(
      'INSERT INTO civicrm_setting (name, value, domain_id, is_domain) VALUES (%1, %2, %3, 1)',
      [
        1 => [$name, 'String'],
        2 => [$serialized, 'String'],
        3 => [$domainId ?? CRM_Core_Config::domainID(), 'Integer'],
      ],
    );
  }

  private function countRetiredRows(): int {
    return (int) CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM civicrm_setting WHERE name IN ('pulse_civenv_status_file', 'pulse_civenv_environment', 'pulse_civenv_max_age')",
    );
  }

}
