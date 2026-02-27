<?php

declare(strict_types=1);

namespace Drupal\pwbi_api_request_mock_test\Plugin\ServiceMock;

use Drupal\Component\Datetime\TimeInterface;
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
 * Intercepts any HTTP request made to microsoft.
 *
 * @ServiceMock(
 *   id = "api_powerbi_com",
 *   label = @Translation("api.powerbi.com"),
 *   weight = 0,
 * )
 */
class PowerBiMockPlugin extends PluginBase implements ServiceMockPluginInterface, ContainerFactoryPluginInterface {

  use ServicePrincipalTrait;

  /**
   * The expiration used by the access token.
   *
   * @var int
   */
  private static int $expiration;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly StateInterface $state,
    protected readonly TimeInterface $time,
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
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line $options value type not defined.
   */
  public function applies(RequestInterface $request, array $options): bool {
    return in_array($request->getUri()->getHost(), ['api.powerbi.com', 'login.windows.net'], TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line $options value type not defined.
   */
  public function getResponse(RequestInterface $request, array $options): ResponseInterface {
    $response = '';
    switch ($request->getUri()->getHost()) {
      case 'api.powerbi.com':
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
            throw new \Exception('Unexpected path : api.powerbi.com' . $request->getUri()->getPath());
        }
        break;

      case 'login.windows.net':
        switch ($request->getUri()->getPath()) {
          case "/my-tenant/oauth2/v2.0/token/":
            $response = $this->getAccessTokenResponse();
            break;

          default:
            throw new \Exception('Unexpected path : login.windows.net' . $request->getUri()->getPath());
        }
        break;
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
    // Default to the expiration of the access token.
    $expires = self::$expiration ?? 3599;
    $timestamp = $this->state->get('api_powerbi_com_token_expire') ?? $this->time->getRequestTime() + $expires;
    $date = \DateTimeImmutable::createFromFormat('U', (string) $timestamp)->format('c');
    return sprintf('{
      "@odata.context": "http://server.analysis.windows.net/v1.0/myorg/$metadata#Microsoft.PowerBI.ServiceContracts.Api.V1.GenerateTokenResponse",
      "token": "token",
      "tokenId": "tokenId",
      "expiration": "%s"
    }', $date);
  }

  /**
   * Response for get access token.
   *
   * @see https://learn.microsoft.com/en-us/rest/api/power-bi/embed-token/generate-token
   *
   * @return string
   *   The json with the response.
   */
  protected function getAccessTokenResponse(): string {
    $expires = 3599;
    $expirations = $this->state->get('api_powerbi_com_access_token_expires', []);
    if (is_numeric($expirations)) {
      $expires = $expirations;
    }
    if (is_array($expirations) && !empty($expirations)) {
      $expires = array_shift($expirations);
      $this->state->set('api_powerbi_com_access_token_expires', $expirations);
    }
    self::$expiration = $expires;

    return sprintf('{
      "token_type":"Bearer",
      "expires_in":%s,
      "ext_expires_in":%s,
      "access_token":"pwbi-access-token"
    }', $expires, $expires);
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
