<?php

namespace Drupal\pwbi;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\oauth2_client\Entity\Oauth2Client;
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
   */
  public function __construct(
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CertificateManager $certificateManager,
  ) {
    parent::__construct($state, $entityTypeManager);
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
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client $oauthClientConfig */
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
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client $oauthClientConfig */
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

}
