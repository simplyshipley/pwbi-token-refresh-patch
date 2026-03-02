<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\pwbi_api_request_mock_test\ServicePrincipalTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * @coversDefaultClass \Drupal\pwbi\Plugin\Field\FieldFormatter\PowerBiEmbedFormatter
 */
class PowerBiEmbedFormatterTest extends KernelTestBase {

  use ServicePrincipalTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'oauth2_client',
    'pwbi',
    'http_request_mock',
    'pwbi_api_request_mock_test',
    'media',
    'user',
    'field',
    'system',
    'file',
    'image',
    'breakpoint',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');
    $this->installConfig([
      'field',
      'system',
      'user',
      'image',
      'file',
      'media',
    ]);

    // Install user module to avoid user 1 permissions bypass.
    \Drupal::moduleHandler()->loadInclude('user', 'install');
    user_install();
    $this->installEntitySchema('oauth2_client');
    $this->configureServicePrincipal();
  }

  /**
   * Creates a test media type, field, display, and media entity.
   *
   * @param int $tokenLifetimeSec
   *   How many seconds until the mock token expires.
   *
   * @return array{0: \Drupal\Core\Entity\Display\EntityDisplayInterface, 1: \Drupal\media\Entity\Media}
   *   The display and media entity for use in assertions.
   */
  private function createEmbedDisplay(int $tokenLifetimeSec = 300): array {
    $this->createMediaType('pwbi_embed_visual', ['id' => 'document']);

    $field_storage = FieldStorageConfig::create([
      'field_name' => 'embed',
      'entity_type' => 'media',
      'type' => 'pwbi_embed',
    ]);
    $field_storage->save();

    $instance = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'document',
    ]);
    $instance->save();

    /** @var \Drupal\Core\Entity\Display\EntityDisplayInterface $display */
    $display = \Drupal::service('entity_display.repository')
      ->getViewDisplay('media', 'document')
      ->setComponent('embed', [
        'type' => 'pwbi_embed_formatter',
        'settings' => [],
      ]);
    $display->save();

    $media = Media::create([
      'name' => 'My test report',
      'bundle' => 'document',
      'status' => 1,
      'embed' => [
        0 => [
          'report_id' => $this->getServiceReportId(),
          'workspace_id' => $this->getServiceWorkspaceId(),
          'report_height' => '100',
          'report_layout' => '3',
          'report_width' => '100',
          'token_type' => 'Embed',
          'embed_type' => 'report',
          'report_width_units' => '%',
          'report_height_units' => 'px',
          'report_breakpoints_height' => 'a:3:{s:7:"pwbi.sm";a:1:{s:6:"height";s:3:"500";}s:7:"pwbi.md";a:1:{s:6:"height";s:3:"900";}s:7:"pwbi.lg";a:1:{s:6:"height";s:4:"1100";}}',
        ],
      ],
    ]);
    $media->save();

    $user = $this->createUser(['access content']);
    $this->setCurrentUser($user);

    $expiry = \Drupal::time()->getCurrentTime() + $tokenLifetimeSec;
    \Drupal::state()->set('api_powerbi_com_token_expire', $expiry);

    return [$display, $media];
  }

  /**
   * Tests that field is rendered with correct cache metadata.
   *
   * @covers ::viewElements
   */
  public function testViewElements(): void {
    [$display, $media] = $this->createEmbedDisplay(300);

    $build = $display->build($media);
    $this->assertNotNull($build['embed']);
    $renderer = \Drupal::service('renderer');
    $renderer->renderInIsolation($build['embed']);

    // @todo Assert other elements and output HTML.
    // Field contains the correct cache — including the pwbi.settings config tag
    // added so that admin setting changes immediately invalidate cached renders.
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
        'max-age' => 300,
      ],
      $build['embed']['#cache'],
    );
  }

  /**
   * Token refresh settings and CSRF token are present in drupalSettings.
   *
   * When token_refresh_enabled is TRUE the formatter must include
   * token_refresh_enabled, token_refresh_minutes, and a non-empty
   * token_refresh_csrf string so the JS can schedule and authorise refreshes.
   *
   * @covers ::buildEmbedConfiguration
   */
  public function testTokenRefreshSettingsInDrupalSettings(): void {
    \Drupal::configFactory()
      ->getEditable('pwbi.settings')
      ->set('token_refresh_enabled', TRUE)
      ->set('token_refresh_minutes', 10)
      ->save();

    [$display, $media] = $this->createEmbedDisplay(1200);
    $build = $display->build($media);

    $embed_key = $this->getServiceReportId();
    $settings = $build['embed'][0]['#attached']['drupalSettings']['pwbi_embed'][$embed_key];

    $this->assertTrue($settings['token_refresh_enabled']);
    $this->assertSame(10, $settings['token_refresh_minutes']);
    // CSRF token must be present so the JS can append ?token=... to refresh
    // requests (the route requires _csrf_token: TRUE).
    $this->assertArrayHasKey('token_refresh_csrf', $settings);
    $this->assertIsString($settings['token_refresh_csrf']);
    $this->assertNotEmpty($settings['token_refresh_csrf']);
  }

  /**
   * Cache max-age is reduced by the refresh buffer when token refresh is on.
   *
   * The formatter subtracts token_refresh_minutes * 60 from the raw token
   * lifetime so that any fresh page load has a token with enough remaining life
   * for the JS timer to fire at least once before expiry.
   *
   * @covers ::viewElements
   */
  public function testCacheMaxAgeReducedByRefreshBuffer(): void {
    \Drupal::configFactory()
      ->getEditable('pwbi.settings')
      ->set('token_refresh_enabled', TRUE)
      ->set('token_refresh_minutes', 10)
      ->save();

    // Token lifetime: 1200s. Buffer: 10 * 60 = 600s. Expected max-age: 600.
    [$display, $media] = $this->createEmbedDisplay(1200);
    $build = $display->build($media);
    $renderer = \Drupal::service('renderer');
    $renderer->renderInIsolation($build['embed']);

    $this->assertSame(600, $build['embed']['#cache']['max-age']);
  }

}
