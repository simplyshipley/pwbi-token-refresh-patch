<?php

declare(strict_types=1);

namespace Drupal\pwbi_purge\Plugin\Field\FieldFormatter;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\State\StateInterface;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\pwbi\Plugin\Field\FieldFormatter\PowerBiEmbedFormatter;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'pwbi_embed_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "pwbi_embed_formatter",
 *   label = @Translation("PowerBi Embed report"),
 *   field_types = {
 *     "pwbi_embed"
 *   }
 * )
 */
class PowerBiEmbedPurgeFormatter extends PowerBiEmbedFormatter {


  /**
   * The Drupal state to save the expiration time.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The request time.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The oauth client service to revoke a key before it expired.
   *
   * @var \Drupal\oauth2_client\Service\Oauth2ClientServiceInterface
   */
  protected Oauth2ClientServiceInterface $oauth2Client;

  /**
   * The config factory to read the cron frequency config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    // @phpstan-ignore-next-line
    $instance->state = $container->get('state');
    // @phpstan-ignore-next-line
    $instance->time = $container->get('datetime.time');
    // @phpstan-ignore-next-line
    $instance->oauth2Client = $container->get('oauth2_client.service');
    // @phpstan-ignore-next-line
    $instance->configFactory = $container->get('config.factory');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $cron_window = (int) $this->configFactory->get('pwbi_purge.settings')->get('cron_window');
    // We get the "principal" access token here before attempting to render
    // the field, because the token for the widget can not outlive the
    // principal token. So if the principal token expires before cron runs
    // the next time, cron couldn't purge varnish in time for the widget to
    // not have an expired key.
    $access = $this->oauth2Client->getAccessToken('pwbi_service_principal', NULL);
    // If we can't get a token things are probably not working, and we can't
    // fix them here.
    if ($access instanceof AccessTokenInterface) {
      // If it has expired or will expire before the next cron run,
      // we delete it from storage so that it is regenerated.
      // When the parent formatter tries to get a token the principal will be
      // regenerated with its default one hour expiration.
      if ($access->getExpires() <= $this->time->getRequestTime() + $cron_window) {
        $this->oauth2Client->clearAccessToken('pwbi_service_principal');
      }
    }

    // Call the parent method, it will get the embed token from the API.
    $element = parent::viewElements($items, $langcode);

    // Now we need to change the cache max-age the parent widget set.
    $min_age = 3600;
    array_walk($element, function (&$item) use (&$min_age, $cron_window) {
      // Shorten the max-age so that drupal refreshes the cache before
      // the token actually expires. We need some time while the token is
      // still valid in the js served from varnish to purge varnish and
      // get a new one from Drupal.
      $item_age = $item['#cache']['max-age'];
      $item_age = $item_age - $cron_window;
      if ($item_age > 0) {
        $item['#cache']['max-age'] = $item_age;
        if ($item_age < $min_age) {
          $min_age = $item_age;
        }
      }
    });
    if ($min_age >= 3600) {
      // This means something went wrong, so set it to expire soon.
      $min_age = -100;
    }
    // We save the expiration time to state for cron to pick up and
    // purge varnish when it runs after this time. We set the max-age
    // in the element to have expired by then too, which forces Drupal
    // to call this formatter again.
    $expiration = ($this->time->getRequestTime() + $min_age);
    if (is_null($this->state->get('pwbi_purge_expiration')) || $this->state->get('pwbi_purge_expiration') >= $expiration) {
      $this->state->set('pwbi_purge_expiration', $expiration);
    }

    return $element;
  }

}
