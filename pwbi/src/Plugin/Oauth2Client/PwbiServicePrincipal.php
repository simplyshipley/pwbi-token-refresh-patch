<?php

declare(strict_types=1);

namespace Drupal\pwbi\Plugin\Oauth2Client;

use Drupal\Core\Utility\Error;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginBase;
use Drupal\oauth2_client\Plugin\Oauth2Client\StateTokenStorage;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Log\LogLevel;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Authentication plugin for Power Bi service principal.
 *
 * @Oauth2Client(
 *   id = "pwbi_service_principal",
 *   name = @Translation("Power Bi service principal auth"),
 *   grant_type = "service_principal",
 *   label = @Translation("Power Bi service principal"),
 *   authorization_uri = "https://login.microsoftonline.com/%s/oauth2/v2.0/authorize/",
 *   token_uri = "https://login.windows.net/%s/oauth2/v2.0/token/",
 *   resource_owner_uri = "https://analysis.usgovcloudapi.net/powerbi/api",
 *   source = "https://analysis.usgovcloudapi.net/powerbi/api/.default",
 * )
 */
class PwbiServicePrincipal extends Oauth2ClientPluginBase {

  use StateTokenStorage;

  /**
   * Retrieves the scope for authentication.
   *
   * @return string|null
   *   The tenant value.
   */
  public function getScope(): string|null {
    return $this->pluginDefinition['source'] ?? NULL;
  }

  /**
   * Get the credentials tenant.
   *
   * @return string
   *   The tenant value.
   */
  public function getTenant(): string {
    /** @var \Drupal\pwbi\ConfigurationCredentialProvider $pwbi_provider */
    $pwbi_provider = $this->credentialService;
    try {
      return $pwbi_provider->getTenant($this->pluginId);
    }
    catch (\Exception $e) {
      // Parent class Oauth2GrantTypePluginBase _construct is final and we can't
      // inject the logger service.
      // @phpstan-ignore-next-line
      $logger = \Drupal::logger('pwbi');
      Error::logException($logger, $e, "Error getting service tenant: " . $e->getMessage(), [], LogLevel::CRITICAL);
    }
    return '';
  }

  /**
   * Return the path to the cert file.
   *
   * @return string
   *   The path to the cert file.
   */
  public function getCertificateFilePath(): string {
    /** @var \Drupal\pwbi\ConfigurationCredentialProvider $pwbi_provider */
    $pwbi_provider = $this->credentialService;
    try {
      return $pwbi_provider->getCertificateFilePath($this->pluginId);
    }
    catch (\Exception $e) {
      // Parent class Oauth2GrantTypePluginBase _construct is final and we can't
      // inject the logger service.
      // @phpstan-ignore-next-line
      $logger = \Drupal::logger('pwbi');
      Error::logException($logger, $e, "Error getting service certificate path: " . $e->getMessage(), [], LogLevel::CRITICAL);
    }
    return '';
  }

  /**
   * Check if the auth process uses or not a certificate.
   *
   * @return bool
   *   True if it uses certificate.
   */
  public function useCertificate(): bool {
    /** @var \Drupal\pwbi\ConfigurationCredentialProvider $pwbi_provider */
    $pwbi_provider = $this->credentialService;
    try {
      return $pwbi_provider->useCertificate($this->pluginId);
    }
    catch (\Exception $e) {
      // Parent class Oauth2GrantTypePluginBase _construct is final and we can't
      // inject the logger service.
      // @phpstan-ignore-next-line
      $logger = \Drupal::logger('pwbi');
      Error::logException($logger, $e, "Error getting service certicate: " . $e->getMessage(), [], LogLevel::CRITICAL);
    }
    return FALSE;
  }

  /**
   * Creates a new provider object.
   *
   * @return \League\OAuth2\Client\Provider\AbstractProvider|\TheNetworg\OAuth2\Client\Provider\Azure
   *   The provider of the OAuth2 Server.
   */
  public function getProvider(): AbstractProvider|Azure {
    if ($this->useCertificate()) {
      return $this->getCertificateProvider();
    }
    return $this->getServicePrincipalProvider();
  }

  /**
   * Get the provider for certificate auth.
   *
   * @return \TheNetworg\OAuth2\Client\Provider\Azure
   *   The authentication provider.
   */
  public function getCertificateProvider(): Azure {
    return new Azure(
      [
        'clientId' => $this->getClientId(),
        'tenant' => $this->getTenant(),
        'clientSecret' => NULL,
        'defaultEndPointVersion' => '2.0',
      ],
      $this->getCollaborators()
    );
  }

  /**
   * Get the provider for service principal auth.
   *
   * @return \League\OAuth2\Client\Provider\AbstractProvider
   *   The authentication provider.
   */
  public function getServicePrincipalProvider(): AbstractProvider {
    $tenant = $this->getTenant();
    return new GenericProvider(
      [
        'clientId' => $this->getClientId(),
        'clientSecret' => $this->getClientSecret(),
        'redirectUri' => $this->getRedirectUri(),
        'urlAuthorize' => sprintf($this->getAuthorizationUri(), $tenant),
        'urlAccessToken' => sprintf($this->getTokenUri(), $tenant),
        'urlResourceOwnerDetails' => $this->getResourceUri(),
        'scopes' => $this->getScopes(),
        'scope' => $this->getScope(),
        'scopeSeparator' => $this->getScopeSeparator(),
        'tenant' => $tenant,
      ],
      $this->getCollaborators()
    );
  }

  /**
   * Create jwt sign for the request.
   *
   * @return string
   *   Sign to use for authentication.
   *
   * @throws \Exception
   */
  public function jwtSigned(): string {
    $cert_path = $this->getCertificateFilePath();
    $token_endpoint = sprintf($this->getTokenUri(), $this->getTenant());
    // Load the certificate.
    $cert_data = file_get_contents($cert_path);
    if (!$cert_data) {
      throw new \Exception("Unable to read the certificate at " . $cert_path);
    }

    $cert = openssl_x509_read($cert_data);
    // Extract the private key from the certificate.
    $private_key = openssl_pkey_get_private($cert_data);
    if (!$private_key) {
      throw new \Exception("Unable to extract the private key. Check the certificate password.");
    }

    $now = time();
    $payload = [
      "aud" => $token_endpoint,
      "iss" => $this->getClientId(),
      "sub" => $this->getClientId(),
      "jti" => bin2hex(random_bytes(16)),
      "nbf" => $now,
      "exp" => $now + 3600,
    ];

    // Create JWT header.
    $header = [
      "alg" => "RS256",
      "typ" => "JWT",
      "x5t" => base64_encode(openssl_x509_fingerprint($cert, "sha1", TRUE)),
    ];

    // Encode header and payload.
    $jwt_header = base64_encode(json_encode($header));
    $jwt_payload = base64_encode(json_encode($payload));
    $jwt_unsigned = $jwt_header . "." . $jwt_payload;

    // Sign the JWT.
    openssl_sign($jwt_unsigned, $jwt_signature, $private_key, OPENSSL_ALGO_SHA256);
    return $jwt_unsigned . "." . base64_encode($jwt_signature);
  }

}
