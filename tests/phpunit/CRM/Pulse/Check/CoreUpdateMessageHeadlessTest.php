<?php

declare(strict_types = 1);

use Civi\Api4\StatusPreference;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * Core's checkExtensionsUpdates is hidden exactly while pulse_updates replaces
 * it: through the severity setting and through pulse's own lifecycle.
 *
 * Deliberately NOT transactional: disabling and uninstalling pulse runs
 * outside any rollback. The test leaves pulse installed with the default setting.
 *
 * @group headless
 */
class CRM_Pulse_Check_CoreUpdateMessageHeadlessTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Start from no preference at all: the check off, leftovers removed.
   */
  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->set(CRM_Pulse_Check_Updates::SEVERITY_SETTING, 'off');
    CRM_Pulse_Check_Updates::reconcileCoreMessage();
    StatusPreference::delete(checkPermissions: FALSE)->addWhere('name', '=', 'checkExtensionsUpdates')->execute();
  }

  protected function tearDown(): void {
    $manager = \CRM_Extension_System::singleton()->getManager();
    if ($manager->getStatus('pulse') !== \CRM_Extension_Manager::STATUS_INSTALLED) {
      $manager->install(['pulse']);
    }
    \Civi::settings()->set(CRM_Pulse_Check_Updates::SEVERITY_SETTING, 'warning');
    parent::tearDown();
  }

  /**
   * @return array<string, mixed>|null
   */
  private function preference(): ?array {
    $row = StatusPreference::get(checkPermissions: FALSE)
      ->addSelect('ignore_severity', 'hush_until', 'is_active')
      ->addWhere('name', '=', 'checkExtensionsUpdates')
      ->addWhere('domain_id', '=', 'current_domain')
      ->execute();
    self::assertLessThanOrEqual(1, $row->count());
    $first = $row->first();
    return is_array($first) ? $first : NULL;
  }

  private function coreMessageVisible(): bool {
    return (new CRM_Utils_Check_Message('checkExtensionsUpdates', 'x', 'x', LogLevel::WARNING))->isVisible();
  }

  private function assertHidden(): void {
    $pref = $this->preference();
    self::assertNotNull($pref, 'the StatusPreference must exist');
    self::assertSame(3, (int) $pref['ignore_severity']);
    self::assertNull($pref['hush_until']);
    self::assertTrue((bool) $pref['is_active']);
    self::assertFalse($this->coreMessageVisible());
  }

  private function adminPreference(): void {
    StatusPreference::create(checkPermissions: FALSE)
      ->addValue('name', 'checkExtensionsUpdates')
      ->addValue('domain_id', 'current_domain')
      ->addValue('ignore_severity', 0)
      ->addValue('hush_until', '2000-01-01')
      ->execute();
    self::assertTrue($this->coreMessageVisible());
  }

  public function testPreferenceExistsExactlyWhileTheUpdateCheckIsActive(): void {
    // An admin's own preference for the name is taken over and overwritten.
    $this->adminPreference();
    \Civi::settings()->set(CRM_Pulse_Check_Updates::SEVERITY_SETTING, 'warning');
    $this->assertHidden();

    // The setting's post_change keeps the preference in step.
    \Civi::settings()->set(CRM_Pulse_Check_Updates::SEVERITY_SETTING, 'off');
    self::assertNull($this->preference());
    self::assertTrue($this->coreMessageVisible());

    \Civi::settings()->set(CRM_Pulse_Check_Updates::SEVERITY_SETTING, 'warning');
    $this->assertHidden();

    $manager = \CRM_Extension_System::singleton()->getManager();
    $manager->disable(['pulse']);
    $pref = $this->preference();
    self::assertNotNull($pref);
    self::assertFalse((bool) $pref['is_active']);
    self::assertSame(0, (int) $pref['ignore_severity']);
    self::assertTrue($this->coreMessageVisible());

    $manager->enable(['pulse']);
    $this->assertHidden();

    $manager->disable(['pulse']);
    $manager->uninstall(['pulse']);
    self::assertNull($this->preference());
    self::assertTrue($this->coreMessageVisible());

    // Installing over an admin's preference takes it over as well.
    $this->adminPreference();
    $manager->install(['pulse']);
    $this->assertHidden();
  }

  /**
   * Visibility ignores is_active, so the disable hook itself must lift the
   * ignore level; independent of what core writes on disable.
   */
  public function testDisableHookMakesTheCoreMessageVisible(): void {
    \Civi::settings()->set(CRM_Pulse_Check_Updates::SEVERITY_SETTING, 'warning');
    $this->assertHidden();

    pulse_civicrm_disable();
    $pref = $this->preference();
    self::assertNotNull($pref);
    self::assertSame(0, (int) $pref['ignore_severity']);
    self::assertNull($pref['hush_until']);
    self::assertTrue($this->coreMessageVisible());
  }

}
