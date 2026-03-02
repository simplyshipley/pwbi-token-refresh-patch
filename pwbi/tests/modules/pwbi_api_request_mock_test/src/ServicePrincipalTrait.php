<?php

declare(strict_types=1);

namespace Drupal\pwbi_api_request_mock_test;

use Drupal\oauth2_client\Entity\Oauth2Client;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Provides methods to configure authentication and access token.
 */
trait ServicePrincipalTrait {

  /**
   * Client id for testing.
   */
  public static string $pwbiClientId = 'client-id';

  /**
   * Client secret for testing.
   */
  public static string $pwbiClientSecret = 'client-secret';

  /**
   * Client tenant for testing.
   */
  public static string $pwbiTenant = 'my-tenant';

  /**
   * Client plugin for testing.
   */
  public static string $pwbiPluginId = 'pwbi_service_principal';

  /**
   * Client workspace for testing.
   */
  public static string $pwbiWorkspaceId = 'workspace_id';

  /**
   * Client report id for testing.
   */
  public static string $pwbiReportId = 'report_id';

  /**
   * Client id for testing.
   */
  public static string $pwbiDatasetId = 'dataset_id';

  /**
   * Get workspace id.
   */
  protected function getServiceWorkspaceId(): string {
    return self::$pwbiWorkspaceId;
  }

  /**
   * Get client id.
   */
  protected function getServiceClientId(): string {
    return self::$pwbiClientId;
  }

  /**
   * Get report id.
   */
  protected function getServiceReportId(): string {
    return self::$pwbiReportId;
  }

  /**
   * Get dataset id.
   */
  protected function getServiceDatasetId(): string {
    return self::$pwbiDatasetId;
  }

  /**
   * Get plugin id.
   */
  protected function getServicePluginId(): string {
    return self::$pwbiPluginId;
  }

  /**
   * Get tenant id.
   */
  protected function getServiceTenant(): string {
    return self::$pwbiTenant;
  }

  /**
   * Get secret id.
   */
  protected function getServiceClientSecret(): string {
    return self::$pwbiClientSecret;
  }

  /**
   * Configure Service Principal auth type.
   */
  protected function configureServicePrincipal(): void {
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client $oauth_entity_storage */
    $oauth_entity_storage = \Drupal::entityTypeManager()->getStorage('oauth2_client');
    $credentials_options = [
      'label' => 'Power Bi service principal auth',
      'id' => $this->getServicePluginId(),
      'oauth2_client_plugin_id' => $this->getServicePluginId(),
      'status' => 1,
      'third_party_settings' => [
        'pwbi' => [
          'tenant' => $this->getServiceTenant(),
        ],
      ],
      'client_id' => $this->getServiceClientId(),
      'client_secret' => $this->getServiceClientSecret(),
      'credential_provider' => 'oauth2_client',
    ];
    $credentials_entity = $oauth_entity_storage->create($credentials_options);
    $credentials_entity->set('credential_storage_key', $credentials_entity->uuid());
    $credentials_entity->save();
    $this->createAccessToken($credentials_entity, $credentials_options);
  }

  /**
   * Create an access token for testing.
   *
   * @param \Drupal\oauth2_client\Entity\Oauth2Client $credentials_entity
   *   The credentials' entity.
   * @param array <mixed> $credentials_options
   *   The configuration credentials.
   */
  protected function createAccessToken(Oauth2Client $credentials_entity, array $credentials_options): void {
    $state = \Drupal::service('state');
    $state->set($credentials_entity->uuid(), $credentials_options);
    $token_options = [
      'token_type' => "Bearer",
      'expires_in' => 3599,
      'ext_expires_in' => 3599,
      'expires_on' => 3599,
      'resource' => "https://analysis.windows.net/powerbi/api",
      'access_token' => "access token",
    ];
    $token = new AccessToken($token_options);
    $state->set("oauth2_client_access_token-" . $this->getServicePluginId(), $token);
  }

}
