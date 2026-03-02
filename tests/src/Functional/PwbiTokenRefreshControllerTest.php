<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi\Functional;

use Drupal\pwbi_api_request_mock_test\ServicePrincipalTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the PwbiTokenRefreshController endpoint.
 *
 * @coversDefaultClass \Drupal\pwbi\Controller\PwbiTokenRefreshController
 * @group pwbi
 */
class PwbiTokenRefreshControllerTest extends BrowserTestBase {

  use ServicePrincipalTrait;

  protected $defaultTheme = 'stark';

  /**
   * Valid UUID-format IDs required by the route parameter regex and
   * isAllowedEmbed() UUID validation. The trait's plain-string IDs
   * ('workspace_id', 'report_id') would fail the UUID check.
   */
  private const WORKSPACE_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
  private const REPORT_UUID = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
  private const DATASET_UUID = 'c3d4e5f6-a7b8-9012-cdef-123456789012';

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
    'http_request_mock',
    'pwbi_api_request_mock_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configureServicePrincipal();

    // Register the workspace in the controller allowlist via State API.
    \Drupal::state()->set('pwbi_embed_settings', [
      'pwbi_workspaces' => self::WORKSPACE_UUID . '|Test Workspace',
    ]);
  }

  /**
   * Happy path: allowlisted workspace+report with valid dataset_id returns
   * a JSON token and expiration with Cache-Control: no-store.
   *
   * @covers ::refresh
   */
  public function testRefreshReturnsToken(): void {
    $user = $this->drupalCreateUser(['view pwbi embed token']);
    $this->drupalLogin($user);

    $this->drupalGet('pwbi/token-refresh/' . self::WORKSPACE_UUID . '/' . self::REPORT_UUID, [
      'query' => ['dataset_id' => self::DATASET_UUID],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertArrayHasKey('token', $data);
    $this->assertArrayHasKey('expiration', $data);
    // PowerBiMockPlugin returns "token" as the fixed token value.
    $this->assertSame('token', $data['token']);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
  }

  /**
   * Workspace not present in the State API allowlist returns 403.
   *
   * @covers ::refresh
   * @covers ::isAllowedEmbed
   */
  public function testRefreshForbiddenWhenWorkspaceNotAllowlisted(): void {
    $user = $this->drupalCreateUser(['view pwbi embed token']);
    $this->drupalLogin($user);

    $otherWorkspace = 'd4e5f6a7-b8c9-0123-def0-123456789012';
    $this->drupalGet('pwbi/token-refresh/' . $otherWorkspace . '/' . self::REPORT_UUID, [
      'query' => ['dataset_id' => self::DATASET_UUID],
    ]);

    $this->assertSession()->statusCodeEquals(403);
    $data = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('Forbidden', $data['error']);
  }

  /**
   * Anonymous user with the permission granted can refresh tokens.
   *
   * The route has no CSRF requirement — it is a read-only GET endpoint and
   * same-origin policy prevents cross-site callers from reading the response.
   *
   * @covers ::refresh
   */
  public function testRefreshWorksForAnonymousWithPermission(): void {
    // Grant the permission to the anonymous role.
    user_role_grant_permissions('anonymous', ['view pwbi embed token']);

    $this->drupalGet('pwbi/token-refresh/' . self::WORKSPACE_UUID . '/' . self::REPORT_UUID, [
      'query' => ['dataset_id' => self::DATASET_UUID],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('token', $data['token']);
  }

  /**
   * A user without the required permission is denied.
   *
   * @covers ::refresh
   */
  public function testRefreshForbiddenWhenUserLacksPermission(): void {
    $user = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($user);

    $this->drupalGet('pwbi/token-refresh/' . self::WORKSPACE_UUID . '/' . self::REPORT_UUID, [
      'query' => ['dataset_id' => self::DATASET_UUID],
    ]);

    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Missing dataset_id query parameter returns 400.
   *
   * @covers ::refresh
   */
  public function testRefreshBadRequestWhenDatasetIdMissing(): void {
    $user = $this->drupalCreateUser(['view pwbi embed token']);
    $this->drupalLogin($user);

    $this->drupalGet('pwbi/token-refresh/' . self::WORKSPACE_UUID . '/' . self::REPORT_UUID);

    $this->assertSession()->statusCodeEquals(400);
    $data = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('dataset_id query parameter required', $data['error']);
  }

  /**
   * dataset_id that is not a valid UUID returns 400.
   *
   * @covers ::refresh
   */
  public function testRefreshBadRequestWhenDatasetIdNotUuid(): void {
    $user = $this->drupalCreateUser(['view pwbi embed token']);
    $this->drupalLogin($user);

    $this->drupalGet('pwbi/token-refresh/' . self::WORKSPACE_UUID . '/' . self::REPORT_UUID, [
      'query' => ['dataset_id' => 'not-a-valid-uuid'],
    ]);

    $this->assertSession()->statusCodeEquals(400);
    $data = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('Invalid dataset_id format', $data['error']);
  }

}
