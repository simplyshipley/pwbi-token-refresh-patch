<?php

declare(strict_types=1);

namespace Drupal\pwbi_settings_override_test;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Provides test-only config overrides for Power BI oauth credentials.
 */
class PwbiSettingsOverrideConfigFactoryOverride implements ConfigFactoryOverrideInterface {

  /**
   * Target oauth2 client config name.
   */
  private const OAUTH_CONFIG = 'oauth2_client.oauth2_client.pwbi_service_principal';

  /**
   * State key used by tests to override default override values.
   */
  public const STATE_KEY = 'pwbi_settings_override_test.config_values';

  /**
   * Constructs the override service.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly StateInterface $state,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names): array {
    if (!in_array(self::OAUTH_CONFIG, $names, TRUE)) {
      return [];
    }

    $cert_file = DRUPAL_ROOT . '/' . $this->moduleHandler->getModule('pwbi')->getPath() . '/tests/mock_cert_file.pem';

    $overrides = [
      self::OAUTH_CONFIG => [
        'client_id' => 'override-client-id',
        'client_secret' => 'override-client-secret',
        'third_party_settings' => [
          'pwbi' => [
            'tenant' => 'override-settings-tenant',
            'client_id' => 'override-settings-client-id',
            'client_secret' => 'override-settings-client-secret',
            'cert_file' => $cert_file,
          ],
        ],
      ],
    ];

    $runtime_overrides = $this->state->get(self::STATE_KEY, []);
    if (is_array($runtime_overrides) && $runtime_overrides !== []) {
      $overrides[self::OAUTH_CONFIG]['third_party_settings'] = $runtime_overrides['third_party_settings'];
    }

    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'PwbiSettingsOverrideTestConfigFactoryOverride';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

}
