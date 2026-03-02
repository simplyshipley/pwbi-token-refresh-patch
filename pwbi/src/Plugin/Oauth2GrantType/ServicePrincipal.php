<?php

declare(strict_types=1);

namespace Drupal\pwbi\Plugin\Oauth2GrantType;

use Drupal\Core\Utility\Error;
use Drupal\oauth2_client\OAuth2\Client\OptionProvider\ClientCredentialsOptionProvider;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginInterface;
use Drupal\oauth2_client\Plugin\Oauth2GrantType\Oauth2GrantTypePluginBase;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Log\LogLevel;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

/**
 * Handles Service principal for PowerBi for the OAuth2 Client module.
 *
 * @Oauth2GrantType(
 *   id = "service_principal",
 *   label = @Translation("Service principal"),
 *   description = @Translation("Makes Service principal for PowerBi grant requests.")
 * )
 */
class ServicePrincipal extends Oauth2GrantTypePluginBase {

  const string CLIENT_ASSERTION_TYPE_JWT = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

  /**
   * {@inheritdoc}
   */
  public function getAccessToken(Oauth2ClientPluginInterface $clientPlugin): ?AccessTokenInterface {
    /** @var \Drupal\pwbi\Plugin\Oauth2Client\PwbiServicePrincipal $clientPlugin */
    $provider = $clientPlugin->getProvider();
    $optionProvider = $provider->getOptionProvider();
    // If the provider was just created, our OptionProvider must be set.
    if (!($optionProvider instanceof ClientCredentialsOptionProvider)) {
      $provider->setOptionProvider(new ClientCredentialsOptionProvider($clientPlugin));
    }
    try {
      if ($clientPlugin->useCertificate()) {
        $jwt_signed = '';
        try {
          $jwt_signed = $clientPlugin->jwtSigned();
        }
        catch (\Exception $e) {
          // Parent class Oauth2GrantTypePluginBase _construct is final
          // and we can't inject the logger service.
          // @phpstan-ignore-next-line
          $logger = \Drupal::logger('update');
          Error::logException($logger, $e, "Error signing the certificate " . $e->getMessage(), [], LogLevel::CRITICAL);
        }
        return $provider->getAccessToken('client_credentials', [
          'scope' => $clientPlugin->getScope(),
          'client_assertion_type' => self::CLIENT_ASSERTION_TYPE_JWT,
          'client_assertion' => $jwt_signed,
        ]);
      }
      return $provider->getAccessToken('client_credentials', [
        'resource' => $clientPlugin->getResourceUri(),
        'scope' => $clientPlugin->getScope(),
      ]);
    }
    catch (IdentityProviderException $e) {
      // Parent class Oauth2GrantTypePluginBase _construct is final
      // and we can't inject the logger service.
      // @phpstan-ignore-next-line
      $logger = \Drupal::logger('update');
      Error::logException($logger, $e, "Error connecting to service: " . $e->getMessage(), [], LogLevel::CRITICAL);
      return NULL;
    }
  }

}
