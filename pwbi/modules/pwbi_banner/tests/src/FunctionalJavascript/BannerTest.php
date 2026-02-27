<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi_banner\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\media\Entity\Media;
use Drupal\pwbi_api_request_mock_test\ServicePrincipalTrait;
use Drupal\pwbi_banner\Form\PowerBiBannerSettingsForm;
use Drupal\pwbi\PowerBiEmbed;
use Drupal\Tests\block\Traits\BlockCreationTrait;

/**
 * Test the disclaimer banner.
 */
class BannerTest extends WebDriverTestBase {

  use BlockCreationTrait;
  use ServicePrincipalTrait;

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  protected static $modules = [
    'pwbi_banner',
    'pwbi_api_request_mock_test',
    'pwbi_embed_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Text to add to the banner.
   *
   * @var string
   */
  protected string $initialBannerText = 'This is a test banner text.';

  /**
   * Text to add to the banner.
   *
   * @var string
   */
  protected string $replacementBannerText = 'This is a replacement test with <a href="#">Link</a>';

  /**
   * Power Bi media entity.
   *
   * @var \Drupal\media\Entity\Media
   */
  protected $pwbiMedia;

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
    \Drupal::service('state')->set(PowerBiEmbed::PWBI_EMBED_SETTINGS, ['pwbi_workspaces' => $this->getServiceWorkspaceId() . "|Workspace"]);
    $this->container->get('router.builder')->rebuild();

    $this->placeBlock('pwbi_disclaimer_block', [
      'id' => 'pwbi_disclaimer_block',
    ]);
  }

  /**
   * Test banner won't appear when there is an acceptance cookie.
   */
  public function testAcceptedCookie() {
    $this->getSession()->setCookie("Drupal.pwbi_banner.accepted_disclaimer", "true");
    $this->configureBanner(PowerBiBannerSettingsForm::DISPLAY_BANNER_TOP, PowerBiBannerSettingsForm::BANNER_TYPE_DISCLAIMER, $this->initialBannerText);
    $this->drupalGet('media/' . $this->pwbiMedia->id());
    $this->assertNoBanner();
  }

  /**
   * Test disclaimer banners.
   */
  public function testDisclaimerBanners() {
    $this->configureBanner(PowerBiBannerSettingsForm::DISPLAY_BANNER_AS_BLOCK, PowerBiBannerSettingsForm::BANNER_TYPE_DISCLAIMER, $this->initialBannerText);
    $this->drupalGet('media/' . $this->pwbiMedia->id());
    $this->noCookie();
    $this->assertBannerText($this->initialBannerText);
    $this->assertNoTopOverlay();
    $this->assertNoBlockingOverlay();
    $this->assertBlockBanner();
    $this->assertEmbed();
    $this->clickBanner();
    $this->yesCookie();
    $this->assertNoBanner();
    $this->deleteCookie();

    $this->configureBanner(PowerBiBannerSettingsForm::DISPLAY_BANNER_TOP, PowerBiBannerSettingsForm::BANNER_TYPE_DISCLAIMER, $this->initialBannerText);
    $this->drupalGet('media/' . $this->pwbiMedia->id());
    $this->noCookie();
    $this->assertBannerText($this->initialBannerText);
    $this->assertTopOverlay();
    $this->assertNoBlockingOverlay();
    $this->assertNoBlockBanner();
    $this->assertEmbed();
    $this->clickBanner();
    $this->yesCookie();
    $this->assertNoBanner();
    $this->deleteCookie();

    $this->configureBanner(PowerBiBannerSettingsForm::DISPLAY_BANNER_BLOCKING, PowerBiBannerSettingsForm::BANNER_TYPE_DISCLAIMER, $this->replacementBannerText);
    $this->drupalGet('media/' . $this->pwbiMedia->id());
    $this->noCookie();
    $this->assertBannerText($this->replacementBannerText);
    $this->assertNoTopOverlay();
    $this->assertBlockingOverlay();
    $this->assertNoBlockBanner();
    $this->assertEmbed();
    $this->clickBanner();
    $this->yesCookie();
    $this->assertNoBanner();
  }

  /**
   * Test must have banners.
   */
  public function testMustHaveBanners() {
    $this->configureBanner(PowerBiBannerSettingsForm::DISPLAY_BANNER_AS_BLOCK, PowerBiBannerSettingsForm::BANNER_TYPE_MUST_HAVE, $this->initialBannerText);
    $this->drupalGet('media/' . $this->pwbiMedia->id());
    $this->noCookie();
    $this->assertBannerText($this->initialBannerText);
    $this->assertNoTopOverlay();
    $this->assertNoBlockingOverlay();
    $this->assertBlockBanner();
    $this->clickBanner();
    $this->assertNoBlockBanner();
    $this->assertEmbed();
    $this->yesCookie();
    $this->assertNoBanner();
    $this->deleteCookie();

    $this->configureBanner(PowerBiBannerSettingsForm::DISPLAY_BANNER_TOP, PowerBiBannerSettingsForm::BANNER_TYPE_MUST_HAVE, $this->initialBannerText);
    $this->drupalGet('media/' . $this->pwbiMedia->id());
    $this->noCookie();
    $this->assertBannerText($this->initialBannerText);
    $this->assertTopOverlay();
    $this->assertNoBlockingOverlay();
    $this->assertNoBlockBanner();
    $this->clickBanner();
    $this->assertNoTopOverlay();
    $this->yesCookie();
    $this->assertEmbed();
    $this->deleteCookie();

    $this->configureBanner(PowerBiBannerSettingsForm::DISPLAY_BANNER_BLOCKING, PowerBiBannerSettingsForm::BANNER_TYPE_MUST_HAVE, $this->replacementBannerText);
    $this->drupalGet('media/' . $this->pwbiMedia->id());
    $this->noCookie();
    $this->assertBannerText($this->replacementBannerText);
    $this->assertNoTopOverlay();
    $this->assertBlockingOverlay();
    $this->assertNoBlockBanner();
    $this->clickBanner();
    $this->assertNoBlockingOverlay();
    $this->yesCookie();
    $this->assertEmbed();
  }

  /**
   * Configure banner for test.
   *
   * @param int $display_option
   *   Where to show the banner.
   * @param int $banner_type
   *   Banner behaviour.
   * @param string $banner_text
   *   The text to show.
   */
  protected function configureBanner(int $display_option, int $banner_type, string $banner_text): void {
    $text = [
      "value" => $banner_text,
      "format" => "full_html",
    ];
    \Drupal::configFactory()
      ->getEditable('pwbi_banner.settings')
      ->set('banner_type', $banner_type)
      ->set('display_options', $display_option)
      ->set('banner_text', $text)
      ->save();
    \Drupal::service('cache.render')->deleteAll();
  }

  /**
   * Click on the accept button.
   */
  protected function clickBanner(): void {
    $this->getSession()->getPage()->find('css', '.pwbi-disclaimer-accept button')->click();
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $assert_session->waitForElementRemoved('css', '.pwbi-disclaimer-banner');
  }

  /**
   * Delete the accept cookie.
   */
  protected function deleteCookie(): void {
    $this->getSession()->setCookie("Drupal.pwbi_banner.accepted_disclaimer");
  }

  /**
   * Assert there is not a top overlay in the pwbi embed.
   */
  protected function assertNoTopOverlay(): void {
    $this->assertSession()->elementNotExists('css', '.pwbi-embed-overlay-top .pwbi-disclaimer-banner');
  }

  /**
   * Assert there is top overlay in the pwbi embed.
   */
  protected function assertTopOverlay(): void {
    $this->assertSession()->elementExists('css', '.pwbi-embed-overlay-top .pwbi-disclaimer-banner');
  }

  /**
   * Assert there is not blocking overlay in the pwbi embed.
   */
  protected function assertNoBlockingOverlay(): void {
    $this->assertSession()->elementNotExists('css', '.pwbi-embed-overlay-blocking .pwbi-disclaimer-banner');
  }

  /**
   * Assert there is blocking overlay in the pwbi embed.
   */
  protected function assertBlockingOverlay(): void {
    $this->assertSession()->elementExists('css', '.pwbi-embed-overlay-blocking .pwbi-disclaimer-banner');
  }

  /**
   * Assert there is a pwbi embed.
   */
  protected function assertEmbed(): void {
    $this->assertSession()->elementExists('css', '.pwbi-embed-container');
  }

  /**
   * Asset there is a block banner.
   */
  protected function assertBlockBanner(): void {
    $this->assertSession()->elementExists('css', '#block-pwbi-disclaimer-block .pwbi-disclaimer-banner');

  }

  /**
   * Assert there is no banner block.
   */
  protected function assertNoBlockBanner(): void {
    $this->assertSession()->elementNotExists('css', '#block-pwbi-disclaimer-block .pwbi-disclaimer-banner');
  }

  /**
   * Assert there is not banner.
   */
  public function assertNoBanner(): void {
    $this->assertSession()->elementNotExists('css', '.pwbi-disclaimer-banner');
  }

  /**
   * Assert the banner text appears.
   *
   * @param string $banner_text
   *   The text of the banner.
   */
  public function assertBannerText(string $banner_text): void {
    $page = $this->getSession()->getPage();
    /** @var \Behat\Mink\Element\NodeElement $element */
    $element = $page->find('css', '.pwbi-disclaimer-text')->getHtml();
    $this->assertEquals($banner_text, trim($element));
  }

  /**
   * Assert the cookie is not defined.
   */
  public function noCookie(): void {
    $cookie = $this->getSession()->getCookie("Drupal.pwbi_banner.accepted_disclaimer");
    $this->assertNull($cookie);
  }

  /**
   * Assert the cookie is defined.
   */
  public function yesCookie(): void {
    $cookie = $this->getSession()->getCookie("Drupal.pwbi_banner.accepted_disclaimer");
    $this->assertEquals($cookie, "true");
  }

}
