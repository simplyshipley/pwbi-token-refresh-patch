<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\pwbi_api_request_mock_test\ServicePrincipalTrait;

/**
 * Test requests to Power BI rest api.
 */
class ConnectTest extends KernelTestBase {

  use ServicePrincipalTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'oauth2_client',
    'pwbi',
    'http_request_mock',
    'pwbi_api_request_mock_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('oauth2_client');
    $this->configureServicePrincipal();
  }

  /**
   * Test getGroupReport request to Power BI Rest API.
   */
  public function testgetGroups(): void {
    /** @var \Drupal\pwbi\Api\PowerBiClient $pwbi_client */
    $pwbi_client = \Drupal::service('pwbi_api.client');
    $response = $pwbi_client->connect('get', 'https://api.powerbi.com/v1.0/myorg/groups');
    $this->assertEquals("Data Explorer", json_decode($response)->value[0]->name);
  }

  /**
   * Test getEmbedToken request to Power BI Rest API.
   */
  public function testGetEmbedToken(): void {
    /** @var \Drupal\pwbi\Api\PowerBiClient $pwbi_client */
    $pwbi_client = \Drupal::service('pwbi_api.client');
    $embed_options = [
      'datasets' => [
        'id' => ['dataset_id'],
      ],
      'reports' => [
        'id' => ['reports_id'],
      ],
    ];
    $response = $pwbi_client->getEmbedToken($embed_options);
    $this->assertEquals("token", json_decode($response)->token);
    $response = $pwbi_client->connect('post', 'https://api.powerbi.com/v1.0/myorg/GenerateToken', $embed_options);
    $this->assertEquals("token", json_decode($response)->token);
  }

  /**
   * Test getPages request to Power BI Rest API.
   */
  public function testGetPages(): void {
    /** @var \Drupal\pwbi\Api\PowerBiClient $pwbi_client */
    $pwbi_client = \Drupal::service('pwbi_api.client');
    $response = $pwbi_client->connect('get', "https://api.powerbi.com/v1.0/myorg/groups/" . $this->getServiceWorkspaceId() . "/reports/" . $this->getServiceReportId() . "/pages");
    $this->assertEquals("Page 1", json_decode($response)->value[0]->displayName);
    $response = $pwbi_client->getPages($this->getServiceWorkspaceId(), $this->getServiceReportId());
    $this->assertEquals("Page 1", json_decode($response)->value[0]->displayName);
  }

  /**
   * Test getGroupReport request to Power BI Rest API.
   */
  public function testGetGroupReport(): void {
    /** @var \Drupal\pwbi\Api\PowerBiClient $pwbi_client */
    $pwbi_client = \Drupal::service('pwbi_api.client');
    $response = $pwbi_client->connect('get', "https://api.powerbi.com/v1.0/myorg/groups/" . $this->getServiceWorkspaceId() . "/reports/" . $this->getServiceReportId());
    $this->assertEquals("Report 1", json_decode($response)->name);
    $response = $pwbi_client->getGroupReport($this->getServiceWorkspaceId(), $this->getServiceReportId());
    $this->assertEquals("Report 1", json_decode($response)->name);
  }

}
