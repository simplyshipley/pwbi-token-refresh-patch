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
   * Tests that field is rendered.
   *
   * @covers ::viewElements
   */
  public function testViewElements(): void {
    // Create field configuration and content.
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

    $user = $this->createUser([
      'access content',
    ]);
    $this->setCurrentUser($user);

    // Token will expire in 300 seconds.
    $request_time = \Drupal::time()->getCurrentTime() + 300;
    \Drupal::state()->set('api_powerbi_com_token_expire', $request_time);

    /** @var \Drupal\Core\Entity\Entity\EntityViewDisplay $display */
    $build = $display->build($media);
    $this->assertNotNull($build['embed']);
    $renderer = \Drupal::service('renderer');
    $renderer->renderInIsolation($build['embed']);

    // @todo Assert other elements and output HTML.
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
        ],
        'max-age' => 300,
      ],
      $build['embed']['#cache'],
    );
  }

}
