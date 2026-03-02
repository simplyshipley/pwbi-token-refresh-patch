<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi\Kernel;

use Drupal\KernelTests\KernelTestBase;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Ensure credentials/certificate can be provided via settings.php overrides.
 */
class SettingsOverrideTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'oauth2_client',
    'pwbi',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('oauth2_client');
  }

  /**
   * Test credentials resolved from configuration overrides.
   */
  public function testSettingsCredentialsAreUsed(): void {
    $module_installer = \Drupal::service('module_installer');
    $certPath = $this->copyCertToTemp();

    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_client');
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client $entity */
    $entity = $storage->create([
      'label' => 'Power Bi service principal auth',
      'id' => 'pwbi_service_principal',
      'oauth2_client_plugin_id' => 'pwbi_service_principal',
      'status' => 1,
      'credential_provider' => 'settings',
      'credential_storage_key' => '',
      'third_party_settings' => [
        'pwbi' => [
          'tenant' => 'settings-tenant',
          'client_id' => 'settings-client-id',
          'client_secret' => 'settings-client-secret',
          'use_cert' => 1,
          'cert_file' => $certPath,
        ],
      ],
    ]);
    $entity->set('credential_storage_key', $entity->uuid());
    $entity->save();

    /** @var \Drupal\pwbi\Plugin\Oauth2Client\PwbiServicePrincipal $client */
    $client = \Drupal::service('oauth2_client.service')->getClient('pwbi_service_principal');

    $this->assertEquals('settings-client-id', $client->getClientId());
    $this->assertEquals('settings-client-secret', $client->getClientSecret());
    $this->assertEquals('settings-tenant', $client->getTenant());
    $this->assertTrue($client->useCertificate());
    $this->assertEquals($certPath, $client->getCertificateFilePath());

    $provider = $client->getProvider();
    $this->assertInstanceOf(Azure::class, $provider);

    // Test overriding all credentials.
    $module_installer->install(['pwbi_settings_override_test']);

    /** @var \Drupal\pwbi\Plugin\Oauth2Client\PwbiServicePrincipal $client */
    $client = \Drupal::service('oauth2_client.service')->getClient('pwbi_service_principal');
    $pwbi_path = \Drupal::service('module_handler')->getModule('pwbi')->getPath();
    $cert_file = DRUPAL_ROOT . '/' . $pwbi_path . '/tests/mock_cert_file.pem';

    $this->assertEquals('override-settings-client-id', $client->getClientId());
    $this->assertEquals('override-settings-client-secret', $client->getClientSecret());
    $this->assertEquals('override-settings-tenant', $client->getTenant());
    $this->assertEquals($cert_file, $client->getCertificateFilePath());

    // Test overriding the client_id.
    $module_installer->uninstall(['pwbi_settings_override_test']);
    \Drupal::state()->set('pwbi_settings_override_test.config_values', [
      'third_party_settings' => [
        'pwbi' => [
          'client_id' => 'runtime-settings-client-id',
        ],
      ],
    ]);
    $module_installer->install(['pwbi_settings_override_test']);
    /** @var \Drupal\pwbi\Plugin\Oauth2Client\PwbiServicePrincipal $client */
    $client = \Drupal::service('oauth2_client.service')->getClient('pwbi_service_principal');

    $this->assertEquals('runtime-settings-client-id', $client->getClientId());
    $this->assertEquals('settings-client-secret', $client->getClientSecret());
    $this->assertEquals('settings-tenant', $client->getTenant());
    $this->assertTrue($client->useCertificate());
    $this->assertEquals($certPath, $client->getCertificateFilePath());

    // Test overriding the client_secret.
    $module_installer->uninstall(['pwbi_settings_override_test']);
    \Drupal::state()->set('pwbi_settings_override_test.config_values', [
      'third_party_settings' => [
        'pwbi' => [
          'client_secret' => 'runtime-settings-client-secret',
        ],
      ],
    ]);
    $module_installer->install(['pwbi_settings_override_test']);
    /** @var \Drupal\pwbi\Plugin\Oauth2Client\PwbiServicePrincipal $client */
    $client = \Drupal::service('oauth2_client.service')->getClient('pwbi_service_principal');

    $this->assertEquals('settings-client-id', $client->getClientId());
    $this->assertEquals('runtime-settings-client-secret', $client->getClientSecret());
    $this->assertEquals('settings-tenant', $client->getTenant());
    $this->assertTrue($client->useCertificate());
    $this->assertEquals($certPath, $client->getCertificateFilePath());
  }

  /**
   * Copy test certificate to a temp location.
   */
  private function copyCertToTemp(): string {
    $source = \Drupal::service('module_handler')->getModule('pwbi')->getPath() . '/tests/mock_cert_file.pem';
    $target = sys_get_temp_dir() . '/pwbi_settings_cert.pem';
    file_put_contents($target, file_get_contents($source));
    return $target;
  }

}
