<?php

declare(strict_types=1);

namespace Drupal\pwbi;

use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\pwbi\Api\PowerBiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class to get the information needed to configure the embed.
 */
class PowerBiEmbed implements ContainerInjectionInterface {

  public const PWBI_EMBED_SETTINGS = 'pwbi_embed_settings';

  public function __construct(
    protected readonly StateInterface $state,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly PowerBiClient $pwbiClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('state'),
      $container->get('logger.factory'),
      $container->get('pwbi_api.client'),
    );
  }

  /**
   * Get the embed configuration.
   *
   * @return array <mixed>
   *   The array with the embed configuration.
   */
  public function getEmbedConfiguration(): array {
    return $this->state->get(self::PWBI_EMBED_SETTINGS, []);
  }

  /**
   * Save the embed configuration.
   *
   * @param array <mixed> $config
   *   The configuration to save.
   */
  public function setEmbedConfiguration(array $config): void {
    $this->state->set(self::PWBI_EMBED_SETTINGS, $config);
  }

  /**
   * Get information to configure the embed.
   *
   * @param string $workspace
   *   The workspace of the report.
   * @param string $reportId
   *   The report to embed.
   *
   * @return array<string, string>
   *   The embed info to configure it.
   *
   * @throws \Exception
   *   Generic Exception can be thrown.
   */
  public function getEmbedDataFromApi(string $workspace, string $reportId): array {
    $reportInfo = Json::decode($this->pwbiClient->getGroupReport($workspace, $reportId));
    $pages = Json::decode($this->pwbiClient->getPages($workspace, $reportId));
    $embedTokenBody = [
      'datasets' => [
        ['id' => $reportInfo['datasetId']],
      ],
      'reports' => [
        ['id' => $reportId],
      ],
    ];

    $embedToken = Json::decode($this->pwbiClient->getEmbedToken($embedTokenBody));
    return [
      'pageName' => $pages['value'][0]['name'],
      'visualName' => $pages['value'][0]['displayName'],
      'embedUrl' => $reportInfo['embedUrl'],
      'accessToken' => $embedToken['token'],
      'tokenExpirationDate' => $embedToken['expiration'],
      'datasetId' => $reportInfo['datasetId'],
    ];
  }

}
