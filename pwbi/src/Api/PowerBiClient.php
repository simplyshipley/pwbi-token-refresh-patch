<?php

declare(strict_types=1);

namespace Drupal\pwbi\Api;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\oauth2_client\Service\Oauth2ClientService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Client to run request against PowerBi RestAPI.
 */
class PowerBiClient implements ContainerInjectionInterface {

  private const API_ENDPOINTS = [
    'commercial' => 'https://api.powerbi.com',
    'gcc'        => 'https://api.powerbigov.us',
    'gcc_high'   => 'https://api.high.powerbigov.us',
    'dod'        => 'https://api.mil.powerbigov.us',
  ];

  public function __construct(
    protected readonly StateInterface $state,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ClientInterface $httpClient,
    protected readonly Oauth2ClientService $auth,
    protected readonly FileUrlGenerator $fileUrlGenerator,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('state'),
      $container->get('logger.factory'),
      $container->get('http_client'),
      $container->get('oauth2_client.service'),
      $container->get('file_url_generator'),
      $container->get('file_system'),
      $container->get('config.factory'),
    );
  }

  /**
   * Get the API root URL based on the configured endpoint.
   *
   * @return string
   *   The API root URL with no trailing slash.
   */
  protected function getApiRoot(): string {
    $config = $this->configFactory->get('pwbi.settings');
    $endpoint = $config->get('pwbi_api_endpoint') ?? self::API_ENDPOINTS['commercial'];
    return rtrim((string) $endpoint, '/');
  }

  /**
   * Run request to PowerBi endpoint.
   *
   * @param string $httpMethod
   *   The http request method.
   * @param string $endpoint
   *   The url of the endpoint.
   * @param array $body
   *   The body to send on the request body.
   * @param array $options
   *   Additional options to create the request.
   *
   * @return string
   *   The response in json.
   *
   * @throws \Exception
   *   Can throw a generic exception.
   */
  public function connect(string $httpMethod, string $endpoint, array $body = [], array $options = []): string {
    try {
      $accessToken = $this->auth->getAccessToken('pwbi_service_principal');
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('pwbi_api')->error('Failed to complete task "@error"', [
        '@error' => $e->getMessage(),
      ]);
      return $e->getMessage();
    }
    if (!$accessToken->getToken()) {
      $message = 'The access token is empty.';
      throw new \Exception($message);
    }
    $options['headers'] = [
      'Authorization' => 'Bearer ' . $accessToken->getToken(),
      'Cache-Control' => 'no-cache',
    ];
    if (!empty($body)) {
      $options['form_params'] = $body;
    }
    try {
      $response = $this->httpClient->{$httpMethod}(
        $endpoint,
        $options,
      );
    }
    catch (RequestException $e) {
      // Log message if there is error.
      $this->loggerFactory->get('pwbi_api')->error('Failed to complete task "@error"', [
        '@error' => $e->getMessage(),
      ]);
      // Return the response body when available so callers can JSON-decode the
      // Power BI error object ({"error":{"code":"...","message":"..."}}).
      // Fall back to the Guzzle summary (which includes HTTP status + URL) when
      // the body is empty — an empty 403 body typically means the tenant-level
      // "Allow service principals to use Power BI APIs" setting is disabled.
      if ($e->hasResponse()) {
        $body = (string) $e->getResponse()->getBody();
        if ($body !== '') {
          return $body;
        }
        // Empty body — encode HTTP status so callers can surface it.
        return json_encode([
          'httpError' => $e->getResponse()->getStatusCode(),
          'httpReason' => $e->getResponse()->getReasonPhrase(),
          'message' => $e->getMessage(),
        ]);
      }
      return $e->getMessage();
    }
    return $response->getBody()->getContents();
  }

  /**
   * Run request to create an embed token.
   *
   * @param array $body
   *   The options to create the body request.
   *
   * @return string
   *   The json response from the request.
   *
   * @throws \Exception
   */
  public function getEmbedToken(array $body): string {
    return $this->connect('post', $this->getApiRoot() . '/v1.0/myorg/GenerateToken', $body);
  }

  /**
   * Run request to query a dataset table.
   *
   * @param string $datasetId
   *   The report to export.
   * @param array $body
   *   The options to create the body request.
   *
   * @return string
   *   The json response from the request.
   *
   * @throws \Exception
   */
  public function executeQuery(string $datasetId, array $body): string {
    $endpoint = sprintf($this->getApiRoot() . '/v1.0/myorg/datasets/%s/executeQueries', $datasetId);
    return $this->connect('post', $endpoint, $body);
  }

  /**
   * Run request to query a workspace dataset table.
   *
   * @param string $workspace
   *   The workspace id of the dataset.
   * @param string $datasetId
   *   The dataset to query.
   * @param array $body
   *   The options to create the body request.
   *
   * @return string
   *   The json response from the request.
   *
   * @throws \Exception
   */
  public function executeGroupQuery(string $workspace, string $datasetId, array $body): string {
    $endpoint = sprintf($this->getApiRoot() . '/v1.0/myorg/groups/%s/datasets/%s/executeQueries', $workspace, $datasetId);
    return $this->connect('post', $endpoint, $body);
  }

  /**
   * Run request to export a report to a file.
   *
   * @param string $workspace
   *   The workspace id of the exported report.
   * @param string $reportId
   *   The report to export.
   * @param array $body
   *   The options to create the request body.
   *
   * @return string
   *   The json response from the request.
   *
   * @throws \Exception
   */
  public function exportGroupReportToFile(string $workspace, string $reportId, array $body): string {
    $endpoint = sprintf($this->getApiRoot() . '/v1.0/myorg/groups/%s/reports/%s/ExportTo', $workspace, $reportId);
    return $this->connect('post', $endpoint, $body);
  }

  /**
   * Get the status of an export.
   *
   * @param string $workspace
   *   The workspace id of the exported report.
   * @param string $reportId
   *   The report id exported.
   * @param string $exportId
   *   The export id.
   *
   * @return string
   *   The json response from the request.
   *
   * @throws \Exception
   *   Can throw a generic exception.
   */
  public function getGroupExportToFileStatus(string $workspace, string $reportId, string $exportId): string {
    $endpoint = sprintf($this->getApiRoot() . '/v1.0/myorg/groups/%s/reports/%s/exports/%s', $workspace, $reportId, $exportId);
    return $this->connect('get', $endpoint);
  }

  /**
   * Download a file to public files.
   *
   * @param string $workspace
   *   The workspace id of the exported report.
   * @param string $reportId
   *   The report id exported.
   * @param string $exportId
   *   The export id.
   * @param string $filePath
   *   The path to save the exported file.
   * @param string $filename
   *   The filename to create locally.
   *
   * @return string
   *   The url to download.
   *
   * @throws \Exception
   *   Can throw a generic exception.
   */
  public function getGroupExportFile(string $workspace, string $reportId, string $exportId, string $filePath, string $filename): string {
    $endpoint = sprintf($this->getApiRoot() . '/v1.0/myorg/groups/%s/reports/%s/exports/%s/file', $workspace, $reportId, $exportId);
    $filePath = $filePath . '/' . $filename;
    $fileRealPath = $this->fileSystem->realpath($filePath);
    $resource = fopen($fileRealPath, 'w+');
    $requestOptions = [
      'sink' => $resource,
    ];
    $this->connect('get', $endpoint, [], $requestOptions);
    $url = $this->fileUrlGenerator->generate($filePath);
    $url->setAbsolute();
    return Json::encode(["download_url" => $url->toString()]);
  }

  /**
   * Run request to get the report in a workspace.
   *
   * @param string $workspace
   *   The workspace id of the report.
   * @param string $reportId
   *   The report id to export.
   *
   * @return string
   *   The json response from the request.
   *
   * @throws \Exception
   *   Can throw a generic exception.
   */
  public function getGroupReport(string $workspace, string $reportId): string {
    $endpoint = sprintf($this->getApiRoot() . '/v1.0/myorg/groups/%s/reports/%s', $workspace, $reportId);
    return $this->connect('get', $endpoint);
  }

  /**
   * Run request to get the pages in a report.
   *
   * @param string $workspace
   *   The workspace of the report.
   * @param string $reportId
   *   The report to export.
   *
   * @return string
   *   The json response from the request.
   *
   * @throws \Exception
   *   Can throw a generic exception.
   */
  public function getPages(string $workspace, string $reportId): string {
    $endpoint = sprintf($this->getApiRoot() . '/v1.0/myorg/groups/%s/reports/%s/pages', $workspace, $reportId);
    return $this->connect('get', $endpoint);
  }

}
