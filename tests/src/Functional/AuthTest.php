<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi\Functional;

use Drupal\Tests\BrowserTestBase;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Test Power Bi authentication.
 */
class AuthTest extends BrowserTestBase {

  protected const TENANT = 'my-tenant';
  protected const CLIENT_ID = 'client-id';
  protected const CLIENT_SECRET = 'client-secret';
  protected const LABEL = 'Test service principal auth';
  protected const PLUGIN_ID = 'pwbi_service_principal';
  protected const CERT_FILE_TEST = 'mock_cert_file.pem';
  protected const URL_LOGIN = "https://login.microsoftonline.com/";

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'oauth2_client',
    'pwbi',
  ];

  /**
   * Test service principal authentication plugin.
   */
  public function testServicePrincipalPlugin(): void {
    // Test credentials configuration.
    /** @var \Drupal\user\Entity\User $account */
    $account = $this->drupalCreateUser(['administer oauth2 clients']);
    $this->drupalLogin($account);

    $this->drupalGet('admin/config/system/oauth2-client');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('admin/config/system/oauth2-client/' . self::PLUGIN_ID . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();
    $page->fillField('edit-label', self::LABEL);
    $page->fillField('edit-client-id', self::CLIENT_ID);
    $page->fillField('edit-client-secret', self::CLIENT_SECRET);
    $page->fillField('edit-tenant', self::TENANT);
    $page->checkField('edit-status');
    $page->pressButton('edit-submit');
    // Updated oauth2 client Power Bi service principal auth.
    $this->assertSession()->pageTextContains("Updated oauth2 client " . self::LABEL . ".");
    $this->assertSession()->elementTextContains('css', '.responsive-enabled', 'Enabled');

    // Test authentication provider credentials creation.
    /** @var \Drupal\pwbi\Plugin\Oauth2Client\PwbiServicePrincipal $oauth_service_principal */
    $oauth_service_principal = \Drupal::service('oauth2_client.service')->getClient(self::PLUGIN_ID);
    /** @var \League\OAuth2\Client\Provider\GenericProvider $oauth_provider */
    $oauth_provider = $oauth_service_principal->getProvider();
    $reflectionClass = new \ReflectionClass('League\OAuth2\Client\Provider\GenericProvider');
    $credentials = [
      'clientId' => self::CLIENT_ID,
      'clientSecret' => self::CLIENT_SECRET,
      'urlAuthorize' => sprintf("https://login.microsoftonline.com/%s/oauth2/v2.0/authorize/", self::TENANT),
      'urlAccessToken' => sprintf("https://login.windows.net/%s/oauth2/v2.0/token/", self::TENANT),
      'urlResourceOwnerDetails' => "https://analysis.windows.net/powerbi/api",
    ];
    foreach ($credentials as $credential => $value) {
      $this->assertEquals($value, $reflectionClass->getProperty($credential)->getValue($oauth_provider));
    }
    $this->assertEquals(self::TENANT, $oauth_service_principal->getTenant());
    $this->assertEquals("https://analysis.windows.net/powerbi/api/.default", $oauth_service_principal->getScope());
  }

  /**
   * Test service principal with certificate authentication plugin.
   */
  public function testServicePrincipalWithCertPlugin(): void {
    // Test credentials configuration.
    /** @var \Drupal\user\Entity\User $account */
    $account = $this->drupalCreateUser(['administer oauth2 clients']);
    $this->drupalLogin($account);

    $this->drupalGet('admin/config/system/oauth2-client');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('admin/config/system/oauth2-client/' . self::PLUGIN_ID . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();
    $page->fillField('edit-label', self::LABEL);
    $page->fillField('edit-client-id', self::CLIENT_ID);
    $page->fillField('edit-tenant', self::TENANT);
    $page->checkField('edit-status');
    $page->selectFieldOption('use_cert', "1");
    $file_path = \Drupal::service('module_handler')->getModule('pwbi')->getPath() . "/tests/" . self::CERT_FILE_TEST;
    $absolute_file_path = \Drupal::service('file_system')->realpath($file_path);
    $page->attachFileToField('edit-cert-file-upload', $absolute_file_path);
    $page->pressButton('edit-submit');
    // Updated oauth2 client Power Bi service principal auth.
    $this->assertSession()->pageTextContains("Updated oauth2 client " . self::LABEL . ".");
    $this->assertSession()->elementTextContains('css', '.responsive-enabled', 'Enabled');

    // Test authentication provider credentials creation.
    /** @var \Drupal\pwbi\Plugin\Oauth2Client\PwbiServicePrincipal $oauth_service_principal */
    $oauth_service_principal = \Drupal::service('oauth2_client.service')->getClient(self::PLUGIN_ID);
    /** @var \TheNetworg\OAuth2\Client\Provider\Azure $oauth_provider */
    $oauth_provider = $oauth_service_principal->getProvider();
    $this->assertEquals(self::TENANT, $oauth_service_principal->getTenant());
    $this->assertEquals(self::CLIENT_ID, $oauth_service_principal->getClientId());
    $this->assertEquals(self::URL_LOGIN, $oauth_provider->urlLogin);
    $this->assertCertJwtSign($absolute_file_path, $oauth_service_principal->jwtSigned());
    $this->assertNotEmpty($oauth_service_principal->jwtSigned());
    $this->assertEquals("https://analysis.windows.net/powerbi/api/.default", $oauth_service_principal->getScope());
    $this->assertFileUsage();
  }

  /**
   * Assert jwt sign is correct.
   *
   * @param string $absolute_file_path
   *   Path to the cert file.
   * @param string $jwt
   *   The signed cert file.
   */
  public function assertCertJwtSign(string $absolute_file_path, string $jwt): void {
    $certificate_data = file_get_contents($absolute_file_path);
    $public_key = trim(explode("-----BEGIN RSA PRIVATE KEY-----", $certificate_data)[0]);
    $decoded_jwt = JWT::decode($jwt, new Key($public_key, 'RS256'));
    $this->assertEquals(sprintf("https://login.windows.net/%s/oauth2/v2.0/token/", self::TENANT), $decoded_jwt->aud);
    $this->assertEquals(self::CLIENT_ID, $decoded_jwt->iss);
  }

  /**
   * Assert the cert file is uploaded correctly.
   */
  public function assertFileUsage(): void {
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['filename' => self::CERT_FILE_TEST]);
    /** @var \Drupal\file\Entity\File $uploaded_cert */
    $uploaded_cert = array_pop($files);
    /** @var \Drupal\file\FileUsage\DatabaseFileUsageBackend $file_usage */
    $file_usage = \Drupal::service('file.usage');
    $expected_usage = [
      "pwbi" => [
        "oauth2_client" => [
          "pwbi_service_principal" => "1",
        ],
      ],
    ];
    $this->assertEquals($expected_usage, $file_usage->listUsage($uploaded_cert));
    $this->assertTrue($uploaded_cert->isPermanent());
  }

}
