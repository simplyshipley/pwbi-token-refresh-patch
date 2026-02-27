<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi_banner\Functional;

use Drupal\Core\Url;
use Drupal\pwbi_banner\Form\PowerBiBannerSettingsForm;
use Drupal\Tests\BrowserTestBase;
use Drupal\filter\Entity\FilterFormat;

/**
 * Test for the banner configuration form.
 */
class ConfigFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'pwbi_banner',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A privileged user with additional access to the 'full_html' format.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $pwbiUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $full_html_format = FilterFormat::create([
      'format' => 'full_html',
      'name' => 'Full HTML',
      'weight' => 1,
      'filters' => [],
    ]);
    $full_html_format->save();

    $this->pwbiUser = $this->drupalCreateUser([
      'administer pwbi disclaimer',
      'use text format full_html',
    ]);
  }

  /**
   * Tests the configuration for the disclaimer banner.
   */
  public function testConfigForm(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet(Url::fromRoute('pwbi_banner.settings'));
    $assert_session->statusCodeEquals(403);
    $this->drupalLogin($this->pwbiUser);
    $this->drupalGet(Url::fromRoute('pwbi_banner.settings'));
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('banner_type')->setValue((string) PowerBiBannerSettingsForm::BANNER_TYPE_MUST_HAVE);
    $assert_session->fieldExists('display_options')->setValue((string) PowerBiBannerSettingsForm::DISPLAY_BANNER_BLOCKING);
    $assert_session->fieldExists('banner_text[value]')->setValue('<p>This is the text of the banner</p>');
    $assert_session->buttonExists('Save configuration')->press();
    $assert_session->statusMessageContains('The configuration options have been saved.', 'status');
    $this->drupalGet(Url::fromRoute('pwbi_banner.settings'));
    $assert_session->fieldValueEquals('banner_type', (string) PowerBiBannerSettingsForm::BANNER_TYPE_MUST_HAVE);
    $assert_session->fieldValueEquals('display_options', (string) PowerBiBannerSettingsForm::DISPLAY_BANNER_BLOCKING);
    $assert_session->fieldValueEquals('banner_text[value]', '<p>This is the text of the banner</p>');
  }

}
