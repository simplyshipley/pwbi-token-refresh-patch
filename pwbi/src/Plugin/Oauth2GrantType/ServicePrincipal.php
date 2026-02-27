<?php

declare(strict_types=1);

namespace Drupal\pwbi\Plugin\Oauth2GrantType;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Error;
use Drupal\oauth2_client\OAuth2\Client\OptionProvider\ClientCredentialsOptionProvider;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginInterface;
use Drupal\oauth2_client\Plugin\Oauth2GrantType\Oauth2GrantTypePluginBase;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Log\LogLevel;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * The injected logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    // @phpstan-ignore-next-line
    $plugin->loggerFactory = $container->get('logger.factory');

    return $plugin;
  }

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
          Error::logException($this->loggerFactory->get('pwbi'), $e, "Error signing the certificate " . $e->getMessage(), [], LogLevel::CRITICAL);
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
      Error::logException($this->loggerFactory->get('pwbi'), $e, "Error connecting to service: " . $e->getMessage(), [], LogLevel::CRITICAL);
      return NULL;
    }
  }

}
