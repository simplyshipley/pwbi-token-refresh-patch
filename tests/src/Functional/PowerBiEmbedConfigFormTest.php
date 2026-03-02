<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the PowerBiEmbedConfigForm admin settings form.
 *
 * @coversDefaultClass \Drupal\pwbi\Form\PowerBiEmbedConfigForm
 * @group pwbi
 */
class PowerBiEmbedConfigFormTest extends BrowserTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'breakpoint',
    'media',
    'oauth2_client',
    'pwbi',
  ];

  /**
   * Admin user with form access.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser(['configure pwbi']);
    $this->drupalLogin($this->adminUser);

    // Seed the workspace list so the required textarea passes validation.
    \Drupal::state()->set('pwbi_embed_settings', [
      'pwbi_workspaces' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890|Test Workspace',
    ]);
  }

  /**
   * Form submits and persists all new toggle settings in Config API.
   *
   * @covers ::submitForm
   */
  public function testFormSavesNewSettings(): void {
    $this->drupalGet('admin/config/pwbi/embed_settings');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'pwbi_workspaces'          => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890|Test Workspace',
      'token_refresh_enabled'    => TRUE,
      'token_refresh_minutes'    => 15,
      'debug_enabled'            => TRUE,
      'block_subscribe_heartbeat'=> TRUE,
      'block_telemetry'          => TRUE,
    ], 'Save configuration');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('pwbi.settings');
    $this->assertTrue($config->get('token_refresh_enabled'));
    $this->assertSame(15, $config->get('token_refresh_minutes'));
    $this->assertTrue($config->get('debug_enabled'));
    $this->assertTrue($config->get('block_subscribe_heartbeat'));
    $this->assertTrue($config->get('block_telemetry'));
  }

  /**
   * Minute validation is skipped when token refresh is disabled.
   *
   * The field is hidden via #states and its value may be stale or out-of-range
   * from a previous save, so the validator must not flag it when refresh is off.
   *
   * @covers ::validateForm
   */
  public function testValidationSkippedWhenRefreshDisabled(): void {
    $this->drupalGet('admin/config/pwbi/embed_settings');

    // token_refresh_enabled is unchecked; submit an out-of-range minutes value.
    $this->submitForm([
      'pwbi_workspaces'       => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890|Test Workspace',
      'token_refresh_enabled' => FALSE,
      'token_refresh_minutes' => 99,
    ], 'Save configuration');

    // No validation error — form should save successfully.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSession()->pageTextNotContains('Minutes must be between 1 and 55');
  }

  /**
   * Minute validation fires when token refresh is enabled and value is invalid.
   *
   * @covers ::validateForm
   */
  public function testValidationEnforcedWhenRefreshEnabled(): void {
    $this->drupalGet('admin/config/pwbi/embed_settings');

    $this->submitForm([
      'pwbi_workspaces'       => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890|Test Workspace',
      'token_refresh_enabled' => TRUE,
      'token_refresh_minutes' => 99,
    ], 'Save configuration');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Minutes must be between 1 and 55');
    // Config must not have been updated.
    $this->assertFalse((bool) $this->config('pwbi.settings')->get('token_refresh_enabled'));
  }

  /**
   * Changing the API endpoint clears the cached OAuth2 token.
   *
   * The cached access token is scoped to the previous cloud endpoint's audience.
   * When the endpoint changes, the stale token must be deleted so a fresh one
   * is fetched with the correct audience on the next API call.
   *
   * @covers ::submitForm
   */
  public function testEndpointChangeClearsOAuthTokenCache(): void {
    // Pre-seed a cached token so we can verify it gets deleted.
    \Drupal::state()->set('oauth2_client_access_token-pwbi_service_principal', 'stale-token');

    $this->drupalGet('admin/config/pwbi/embed_settings');

    // Switch from commercial (default) to GCC.
    $this->submitForm([
      'pwbi_workspaces'    => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890|Test Workspace',
      'pwbi_api_endpoint'  => 'https://api.powerbigov.us',
      'token_refresh_enabled' => FALSE,
    ], 'Save configuration');

    $this->assertSession()->statusCodeEquals(200);
    // Warning message confirms the cache was cleared.
    $this->assertSession()->pageTextContains('OAuth2 token cache cleared');
    // State key must be gone.
    $this->assertNull(\Drupal::state()->get('oauth2_client_access_token-pwbi_service_principal'));
    // Config must reflect the new endpoint.
    $this->assertSame('https://api.powerbigov.us', $this->config('pwbi.settings')->get('pwbi_api_endpoint'));
  }

}
