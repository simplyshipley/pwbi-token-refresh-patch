<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi_purge\Kernel;

use Drupal\Core\CronInterface;
use Drupal\purge\Plugin\Purge\Invalidation\TagInvalidation;
use Drupal\Tests\pwbi\Kernel\PowerBiEmbedFormatterTest;

/**
 * @coversDefaultClass \Drupal\pwbi_purge\Plugin\Field\FieldFormatter\PowerBiEmbedPurgeFormatter
 */
class PowerBiEmbedPurgeFormatterTest extends PowerBiEmbedFormatterTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'purge',
    'purge_queuer_coretags',
    'pwbi_purge',
    'purge_purger_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig([
      'purge',
      'purge_queuer_coretags',
      'pwbi_purge',
    ]);

    // Set up a test purger from purge_purger_test that can purge tags.
    \Drupal::service('purge.purgers')->setPluginsEnabled(['good' => 'good']);
  }

  /**
   * Override the inherited test with default values.
   *
   * @covers ::viewElements
   */
  public function testViewElements(): void {

    $fixture = $this->createFixture();
    $media = $fixture['media'];
    $display = $fixture['display'];

    /** @var \Drupal\Core\Entity\Entity\EntityViewDisplay $display */
    $build = $display->build($media);
    $this->assertNotNull($build['embed']);
    $renderer = \Drupal::service('renderer');
    $renderer->renderInIsolation($build['embed']);

    // The default access token fixture minus the default cron window config.
    $expected_max_age = 3599 - 960;
    // Field contains the correct cache.
    $this->assertSame(
      [
        'contexts' => [
          'languages:language_interface',
          'theme',
          'user.permissions',
        ],
        'tags' => [
          'pwbi_embed',
          'config:pwbi.settings',
        ],
        'max-age' => $expected_max_age,
      ],
      $build['embed']['#cache'],
    );

    $expected_expiration = \Drupal::time()->getRequestTime() + $expected_max_age;
    self::assertEquals($expected_expiration, (int) \Drupal::state()->get('pwbi_purge_expiration'));
  }

  /**
   * Override the inherited test with default values.
   *
   * @covers ::viewElements
   */
  public function testAccessTokenReset(): void {

    $this->config('pwbi_purge.settings')->set('cron_window', 963)->save();
    // First value lower than the cron_window, the second is after reset.
    \Drupal::state()->set('api_powerbi_com_access_token_expires', [500, 2599]);

    $fixture = $this->createFixture();
    $media = $fixture['media'];
    $display = $fixture['display'];

    /** @var \Drupal\Core\Entity\Entity\EntityViewDisplay $display */
    $build = $display->build($media);
    $this->assertNotNull($build['embed']);
    $renderer = \Drupal::service('renderer');
    $renderer->renderInIsolation($build['embed']);

    // The access token fixture minus the cron window config.
    $expected_max_age = 2599 - 963;
    // Field contains the correct cache.
    $this->assertSame(
      [
        'contexts' => [
          'languages:language_interface',
          'theme',
          'user.permissions',
        ],
        'tags' => [
          'pwbi_embed',
          'config:pwbi.settings',
        ],
        'max-age' => $expected_max_age,
      ],
      $build['embed']['#cache'],
    );

    $expected_expiration = \Drupal::time()->getRequestTime() + $expected_max_age;
    self::assertEquals($expected_expiration, (int) \Drupal::state()->get('pwbi_purge_expiration'));
  }

  /**
   * Test that cron purges correctly.
   *
   * @covers \Drupal\pwbi_purge\Hook\PurgeHooks::purgeOnCron
   */
  public function testCron(): void {
    // Set the expiration to the past.
    \Drupal::state()->set('pwbi_purge_expiration', \Drupal::time()->getCurrentTime() - 100);
    // Run cron, now the pwbi_embed tag should be purging.
    \Drupal::service(CronInterface::class)->run();
    // Claim the items.
    $claims = \Drupal::service('purge.queue')->claim();
    self::assertCount(1, $claims);
    $claim = $claims[0];
    self::assertInstanceOf(TagInvalidation::class, $claim);
    self::assertEquals('pwbi_embed', $claim->getExpression());
  }

}
