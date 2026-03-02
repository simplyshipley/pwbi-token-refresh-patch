<?php

namespace Drupal\Tests\pwbi\Functional;

use Drupal\media\Entity\Media;
use Drupal\pwbi_api_request_mock_test\ServicePrincipalTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * Test of report embeds.
 */
class EmbedTest extends BrowserTestBase {

  use ServicePrincipalTrait;

  /**
   * Power Bi media entity.
   *
   * @var \Drupal\media\Entity\Media
   */
  protected $pwbiMedia;
  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'page_cache',
    'system',
    'breakpoint',
    'path',
    'node',
    'media',
    'oauth2_client',
    'pwbi',
    'http_request_mock',
    'pwbi_api_request_mock_test',
    'pwbi_embed_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configureServicePrincipal();
    $this->pwbiMedia = Media::create([
      'name' => 'My test report',
      'bundle' => 'power_bi_report',
      'status' => 1,
      'field_media_pwbi_embed_visual' => [
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
    $this->pwbiMedia->save();
    \Drupal::configFactory()
      ->getEditable('media.settings')
      ->set('standalone_url', TRUE)
      ->save(TRUE);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Test loading of powerbi client js library and embed options.
   */
  public function testEmbedReport(): void {
    $max_age = 100;
    $request_time = \Drupal::time()->getCurrentTime() + $max_age;
    \Drupal::state()->set('api_powerbi_com_token_expire', $request_time);
    $this->drupalGet('media/' . $this->pwbiMedia->id());
    /** @var \Drupal\Tests\DocumentElement $page */
    $page = $this->getSession()->getPage();
    $module_handler = \Drupal::getContainer()->get('module_handler');
    $module_path = $module_handler->getModule('pwbi')->getPath();
    $pwbi_embed = $page->find("css", sprintf('script[src*="%s/components/pwbi-embed/pwbi-embed.js"]', $module_path));
    $this->assertNotNull($pwbi_embed, 'powerbi-embed library not found');
    $pwbi_container = $page->find("css", ".pwbi-embed-container");
    $this->assertSession()->assert(str_contains((string) $pwbi_container?->getAttribute('style'), "width:100%"), "width should be 100%");
    $pwbi_media = $page->find("css", ".media-" . $this->getServiceReportId());
    $this->assertNotNull($pwbi_media);
    $pwbi_overlay_top = $page->find("css", ".pwbi-embed-overlay-top");
    $pwbi_overlay_blocking = $page->find("css", ".pwbi-embed-overlay-blocking");
    $this->assertNull($pwbi_overlay_top);
    $this->assertNull($pwbi_overlay_blocking);
    $headers = $this->getSession()->getResponseHeaders();
    $this->assertContains("pwbi_embed", explode(" ", $headers['X-Drupal-Cache-Tags'][0]));
    $this->assertEquals($max_age, $headers['X-Drupal-Cache-Max-Age'][0]);
  }

}
