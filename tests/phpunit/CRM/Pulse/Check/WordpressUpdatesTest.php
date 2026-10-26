<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;

// this sniff demands around a function declared inside a conditional block.
if (!function_exists('get_site_transient')) {
  /**
   * Stand-in for WordPress's transient API.
   *
   * pulse reads the three update transients through CRM_Pulse_Util::cmsCall(),
   * which dispatches on function_exists() — so declaring the function here is
   * all it takes to exercise the WordPress leg on a non-WordPress stack.
   * Harmless elsewhere: CRM_Pulse_Check_CmsUpdates::check() only reaches this
   * leg when CIVICRM_UF is literally 'WordPress'.
   *
   * @return mixed
   */
  function get_site_transient(string $transient) {
    return CRM_Pulse_Check_WordpressUpdatesTest::$transients[$transient] ?? FALSE;
  }
}

/**
 * The WordPress leg of pulse_cms_updates: reading pending core, plugin and
 * theme updates out of WP's own bookkeeping.
 *
 * The transient shapes are nested and entirely untyped (`$core->updates[0]
 * ->response`), which is exactly where a monitor quietly stops reporting — or
 * fatals inside cron.
 *
 * @group unit
 */
class CRM_Pulse_Check_WordpressUpdatesTest extends TestCase {

  /**
   * Transient name → value, as WordPress would return it.
   *
   * @var array<string, mixed>
   */
  public static array $transients = [];

  protected function setUp(): void {
    parent::setUp();
    self::$transients = [];
  }

  protected function tearDown(): void {
    self::$transients = [];
    parent::tearDown();
  }

  /**
   * WP's update_core transient: an object with an ->updates list of offers.
   */
  private static function coreOffer(string $response, string $version): stdClass {
    $offer = new stdClass();
    $offer->response = $response;
    $offer->version = $version;
    $transient = new stdClass();
    $transient->updates = [$offer];
    return $transient;
  }

  /**
   * WP's update_plugins/update_themes transient: ->response keyed by slug.
   *
   * @param list<string> $slugs
   */
  private static function componentOffers(array $slugs): stdClass {
    $transient = new stdClass();
    $transient->response = array_fill_keys($slugs, new stdClass());
    return $transient;
  }

  public function testNoTransientsMeansNothingPending(): void {
    // WP returns FALSE for an unset transient.
    $this->assertSame('ok', CRM_Pulse_Check_CmsUpdates::checkWordpress('all')['status']);
  }

  public function testPendingCoreUpgradeCountsAsSecurity(): void {
    self::$transients['update_core'] = self::coreOffer('upgrade', '6.9.1');
    $r = CRM_Pulse_Check_CmsUpdates::checkWordpress('security');
    $this->assertSame('fail', $r['status']);
    // WP does not flag security-ness, so core is treated as security-relevant
    // and reported even in the narrower 'security' mode.
    $this->assertStringContainsString('security: wordpress-core 6.9.1', $r['detail']);
  }

  public function testCoreOfferThatIsNotAnUpgradeIsIgnored(): void {
    // 'latest' means "you are current"; only 'upgrade' is an offer.
    self::$transients['update_core'] = self::coreOffer('latest', '6.9.1');
    $this->assertSame('ok', CRM_Pulse_Check_CmsUpdates::checkWordpress('all')['status']);
  }

  public function testPluginAndThemeUpdatesFollowTheMode(): void {
    self::$transients['update_plugins'] = self::componentOffers(['akismet/akismet.php']);
    self::$transients['update_themes'] = self::componentOffers(['twentytwenty']);

    // 'security' mode deliberately stays quiet about ordinary updates …
    $this->assertSame('ok', CRM_Pulse_Check_CmsUpdates::checkWordpress('security')['status']);

    // … 'all' reports them, tagged by kind.
    $all = CRM_Pulse_Check_CmsUpdates::checkWordpress('all');
    $this->assertSame('fail', $all['status']);
    $this->assertStringContainsString('plugin:akismet/akismet.php', $all['detail']);
    $this->assertStringContainsString('theme:twentytwenty', $all['detail']);
  }

  public function testCoreUpgradeIsReportedAlongsideOrdinaryUpdates(): void {
    self::$transients['update_core'] = self::coreOffer('upgrade', '6.9.1');
    self::$transients['update_plugins'] = self::componentOffers(['akismet/akismet.php']);
    $r = CRM_Pulse_Check_CmsUpdates::checkWordpress('security');
    $this->assertStringContainsString('security: wordpress-core 6.9.1', $r['detail']);
    $this->assertStringContainsString('updates: plugin:akismet/akismet.php', $r['detail']);
  }

  /**
   * A transient that does not have the documented shape must leave the check
   * quiet, never take cron down with it.
   */
  public function testMalformedTransientsAreSurvived(): void {
    self::$transients['update_core'] = 'not an object';
    self::$transients['update_plugins'] = (object) ['response' => 'not an array'];
    self::$transients['update_themes'] = new stdClass();
    $this->assertSame('ok', CRM_Pulse_Check_CmsUpdates::checkWordpress('all')['status']);

    // ->updates present but empty, and an offer without ->response.
    $empty = new stdClass();
    $empty->updates = [];
    self::$transients['update_core'] = $empty;
    $this->assertSame('ok', CRM_Pulse_Check_CmsUpdates::checkWordpress('all')['status']);

    $noResponse = new stdClass();
    $noResponse->updates = [new stdClass()];
    self::$transients['update_core'] = $noResponse;
    $this->assertSame('ok', CRM_Pulse_Check_CmsUpdates::checkWordpress('all')['status']);
  }

  /**
   * An upgrade offer without a version string still has to be reported —
   * dropping it would hide a pending core update behind a missing field.
   */
  public function testCoreUpgradeWithoutAVersionIsStillReported(): void {
    $offer = new stdClass();
    $offer->response = 'upgrade';
    $transient = new stdClass();
    $transient->updates = [$offer];
    self::$transients['update_core'] = $transient;

    $r = CRM_Pulse_Check_CmsUpdates::checkWordpress('security');
    $this->assertSame('fail', $r['status']);
    $this->assertStringContainsString('wordpress-core', $r['detail']);
  }

}
