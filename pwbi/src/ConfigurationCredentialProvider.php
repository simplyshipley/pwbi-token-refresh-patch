<?php

namespace Drupal\pwbi;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\oauth2_client\Entity\Oauth2Client;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginInterface;
use Drupal\oauth2_client\Service\CredentialProvider;
use Drupal\pwbi\Cert\CertificateManager;

/**
 * Decorator for CredentialProvider to get the tenant.
 */
class ConfigurationCredentialProvider extends CredentialProvider {

  /**
   * KeyService constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The key value store to use.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Injected entity manager service.
   * @param \Drupal\pwbi\Cert\CertificateManager $certificateManager
   *   The certificate manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(
    StateInterface $state,
    EntityTypeManagerInterface $entityTypeManager,
    protected CertificateManager $certificateManager,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($state, $entityTypeManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getCredentials(Oauth2ClientPluginInterface $plugin): array {
    $default_credentials = parent::getCredentials($plugin);
    $settingsCredentials = $this->getSettingsCredentials($plugin->getId());
    if (is_null($settingsCredentials)) {
      return $default_credentials;
    }
    return [
      'client_id' => empty($settingsCredentials['client_id']) ? $default_credentials['client_id'] : $settingsCredentials['client_id'],
      'client_secret' => empty($settingsCredentials['client_secret']) ? $default_credentials['client_secret'] : $settingsCredentials['client_secret'],
    ];
  }

  /**
   * Get the tenant configured in the credentials form.
   *
   * @param string $pluginId
   *   The id of the authentication plugin.
   *
   * @return mixed
   *   The tenant id.
   *
   * @throws \Exception
   */
  public function getTenant(string $pluginId): mixed {
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client|null $oauthClientConfig */
    $oauthClientConfig = $this->entityTypeManager->getStorage('oauth2_client')->load($pluginId);
    if (!($oauthClientConfig instanceof Oauth2Client)) {
      throw new \Exception(sprintf('There is no configuration for plugin:%s', $pluginId));
    }
    return $oauthClientConfig->getThirdPartySetting('pwbi', 'tenant');
  }

  /**
   * Checks if the auth plugin uses certificate for authentication.
   *
   * @param string $pluginId
   *   The plugin to check.
   *
   * @return bool
   *   True if it uses certificate.
   *
   * @throws \Exception
   */
  public function useCertificate(string $pluginId): bool {
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client|null $oauthClientConfig */
    $oauthClientConfig = $this->entityTypeManager->getStorage('oauth2_client')->load($pluginId);
    if (!($oauthClientConfig instanceof Oauth2Client)) {
      throw new \Exception(sprintf('There is no configuration for plugin:%s', $pluginId));
    }
    return $oauthClientConfig->getThirdPartySetting('pwbi', 'use_cert') == 1;
  }

  /**
   * Return the path to the cert file.
   *
   * @param string $pluginId
   *   The plugin to check.
   *
   * @return string
   *   The path to the cert file.
   *
   * @throws \Exception
   */
  public function getCertificateFilePath(string $pluginId): string {
    return $this->certificateManager->getCertificatePath($pluginId);
  }

  /**
   * Return credentials defined in settings.php (config overrides).
   *
   * @param string $pluginId
   *   The OAuth client plugin id.
   *
   * @return array|null
   *   Credentials or NULL when not configured via settings.php.
   */
  protected function getSettingsCredentials(string $pluginId): ?array {
    $config = $this->configFactory->get('oauth2_client.oauth2_client.' . $pluginId);

    $clientId = $config->get('third_party_settings.pwbi.client_id');
    $clientSecret = $config->get('third_party_settings.pwbi.client_secret');

    if ($clientId === NULL && $clientSecret === NULL) {
      return NULL;
    }

    return [
      'client_id' => $clientId ?? '',
      'client_secret' => $clientSecret ?? '',
    ];
  }

}
