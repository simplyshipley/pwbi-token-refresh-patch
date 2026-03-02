<?php

declare(strict_types=1);

namespace Drupal\pwbi_api_request_mock_test\Plugin\ServiceMock;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\http_request_mock\ServiceMockPluginInterface;
use Drupal\pwbi_api_request_mock_test\ServicePrincipalTrait;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Intercepts any HTTP request made to example.com.
 *
 * @ServiceMock(
 *   id = "api_powerbi_com",
 *   label = @Translation("api.powerbi.com"),
 *   weight = 0,
 * )
 */
class PowerBiMockPlugin extends PluginBase implements ServiceMockPluginInterface, ContainerFactoryPluginInterface {

  use ServicePrincipalTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly StateInterface $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line $options value type not defined.
   */
  public function applies(RequestInterface $request, array $options): bool {
    return $request->getUri()->getHost() === 'api.powerbi.com';
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line $options value type not defined.
   */
  public function getResponse(RequestInterface $request, array $options): ResponseInterface {
    switch ($request->getUri()->getPath()) {
      case "/v1.0/myorg/GenerateToken":
        $response = $this->getEmbedTokenResponse();
        break;

      case "/v1.0/myorg/groups":
        $response = $this->getGroupsResponse();
        break;

      case "/v1.0/myorg/groups/" . $this->getServiceWorkspaceId() . "/reports/" . $this->getServiceReportId() . "/pages":
        $response = $this->getPagesResponse();
        break;

      case "/v1.0/myorg/groups/" . $this->getServiceWorkspaceId() . "/reports/" . $this->getServiceReportId():
        $response = $this->getReport();
        break;

      default:
        throw new \Exception('Unexpected value');
    }
    return new Response(200, [], $response);
  }

  /**
   * Response for get groups.
   *
   * @see https://learn.microsoft.com/en-us/rest/api/power-bi/groups/get-groups
   *
   * @return string
   *   The json with the response.
   */
  protected function getGroupsResponse(): string {
    return '{
      "@odata.context": "http://server.analysis.windows.net/v1.0/myorg/$metadata#groups",
      "@odata.count": 1,
      "value": [
          {
              "id": "id",
              "isReadOnly": false,
              "isOnDedicatedCapacity": true,
              "capacityId": "capacityId",
              "defaultDatasetStorageFormat": "Small",
              "type": "Workspace",
              "name": "Data Explorer"
          }
      ]
    }';
  }

  /**
   * Response for get embed token.
   *
   * @see https://learn.microsoft.com/en-us/rest/api/power-bi/embed-token/generate-token
   *
   * @return string
   *   The json with the response.
   */
  protected function getEmbedTokenResponse(): string {
    $timestamp = $this->state->get('api_powerbi_com_token_expire') ?? date('U') + 3599;
    $date = \DateTimeImmutable::createFromFormat('U', (string) $timestamp)->format('c');
    return sprintf('{
      "@odata.context": "http://server.analysis.windows.net/v1.0/myorg/$metadata#Microsoft.PowerBI.ServiceContracts.Api.V1.GenerateTokenResponse",
      "token": "token",
      "tokenId": "tokenId",
      "expiration": "%s"
    }', $date);
  }

  /**
   * Response for get group report pages.
   *
   * @see https://learn.microsoft.com/en-us/rest/api/power-bi/reports/get-pages-in-group
   *
   * @return string
   *   The json with the response.
   */
  protected function getPagesResponse(): string {
    return '{
     "@odata.context":"http://wabi-europe-north-b-redirect.analysis.windows.net/v1.0/myorg/groups/' . $this->getServiceWorkspaceId() . '/$metadata#pages",
     "value":[
        {
           "name":"ReportSection",
           "displayName":"Page 1",
           "order":0
        }
     ]
    }';
  }

  /**
   * Response to get a group report.
   *
   * @see https://learn.microsoft.com/en-us/rest/api/power-bi/reports/get-report-in-group
   *
   * @return string
   *   The json with the response.
   */
  protected function getReport(): string {
    return '{
      "@odata.context": "http://wabi-europe-north-b-redirect.analysis.windows.net/v1.0/myorg/groups/' . $this->getServiceWorkspaceId() . '/$metadata#reports/$entity",
      "id": "' . $this->getServiceReportId() . '",
      "reportType": "PowerBIReport",
      "name": "Report 1",
      "webUrl": "https://app.powerbi.com/groups/' . $this->getServiceWorkspaceId() . '/reports/' . $this->getServiceReportId() . '",
      "embedUrl": "https://app.powerbi.com/reportEmbed?reportId=' . $this->getServiceReportId() . 'f&groupId=' . $this->getServiceWorkspaceId() . '",
      "isFromPbix": true,
      "isOwnedByMe": true,
      "datasetId": "' . $this->getServiceWorkspaceId() . '",
      "datasetWorkspaceId": "' . $this->getServiceWorkspaceId() . '",
      "users": [],
      "subscriptions": [],
      "sections": []
    }';
  }

}
