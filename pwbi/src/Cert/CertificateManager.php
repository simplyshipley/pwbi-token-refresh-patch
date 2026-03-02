<?php

declare(strict_types=1);

namespace Drupal\pwbi\Cert;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\pwbi\Api\PowerBiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Management operations for certificate file.
 */
class CertificateManager implements ContainerInjectionInterface {

  public function __construct(
    protected readonly ConfigFactory $configFactory,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly PowerBiClient $pwbiClient,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('pwbi_api.client'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Check if the certificate file path is defined in the settings.php file.
   *
   * @param string $pluginId
   *   The plugin to check.
   *
   * @return bool
   *   True if the file exists.
   */
  public function definedInSettings(string $pluginId): bool {
    $cert_file = $this->configFactory->get('oauth2_client.oauth2_client.' . $pluginId)->get('third_party_settings.pwbi.cert_file');
    if ($cert_file !== NULL && file_exists($cert_file)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Get the cert path from settings.php of from the uploaded file.
   *
   * @param string $pluginId
   *   The plugin to check.
   *
   * @return array|mixed|null
   *   The path to the file.
   */
  public function getCertificatePath(string $pluginId) {
    $cert_file = $this->configFactory->get('oauth2_client.oauth2_client.' . $pluginId)->get('third_party_settings.pwbi.cert_file');
    if (file_exists($cert_file)) {
      return $cert_file;
    }
    $cert_file_managed = $this->entityTypeManager->getStorage('file')->load($cert_file);
    return $cert_file_managed->getFileUri();
  }

  /**
   * Get the fid of the cert file.
   *
   * @param string $pluginId
   *   The plugin to check.
   *
   * @return mixed
   *   The fid of the file..
   */
  public function getManagedCertificateFid(string $pluginId): mixed {
    $cert_file_fid = $this->configFactory->get('oauth2_client.oauth2_client.' . $pluginId)->get('third_party_settings.pwbi.cert_file');
    if ($cert_file_fid === NULL || $this->entityTypeManager->getStorage('file')->load($cert_file_fid) === NULL) {
      return NULL;
    }
    return $cert_file_fid;
  }

}
