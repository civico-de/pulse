<?php

declare(strict_types = 1);

namespace Civi\Pulse;

use Civi\Api4\Contact;
use Civi\Pulse\Demo\ScenarioSeeder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
final class DemoScenarioHeadlessTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function testSeedIsIdempotentAndDuplicateCheckFails(): void {
    $first = (new ScenarioSeeder())->seed();
    $second = (new ScenarioSeeder())->seed();

    self::assertSame($first, $second);
    self::assertCount(2, $second['contact_ids']);
    self::assertSame(
      2,
      Contact::get(checkPermissions: FALSE)
        ->addWhere('id', 'IN', $second['contact_ids'])
        ->execute()
        ->count(),
    );

    $messages = \CRM_Pulse_StatusCheck::messages([$second['check']]);
    self::assertCount(1, $messages);
    self::assertSame($second['check'], $messages[0]->getName());
    self::assertSame($second['expected_severity'], $messages[0]->getSeverity());
    self::assertStringNotContainsString(
      ScenarioSeeder::EMAIL,
      $messages[0]->getMessage(),
      'status messages must not leak the shared email address',
    );
  }

}
