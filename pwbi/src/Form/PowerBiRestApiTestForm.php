<?php

declare(strict_types=1);

namespace Drupal\pwbi\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\pwbi\Api\PowerBiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class for the test form.
 */
class PowerBiRestApiTestForm extends FormBase {

  /**
   * The PowerBi api client service.
   *
   * @var \Drupal\pwbi\Api\PowerBiClient
   */
  protected PowerBiClient $client;

  public function __construct(PowerBiClient $client) {
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('pwbi_api.client'),
    );
  }

  /**
   * Getter method for Form ID.
   *
   * The form ID is used in implementations of hook_form_alter() to allow other
   * modules to alter the render array built by this form controller. It must be
   * unique site wide. It normally starts with the providing module's name.
   *
   * @return string
   *   The unique ID of the form defined by this class.
   */
  public function getFormId(): string {
    return 'pwbi_api_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->requestBuilderForm($form, $form_state);
    $this->getEmbedTokenForm($form, $form_state);
    $this->executeQueryForm($form, $form_state);
    $this->exportToFileForm($form, $form_state);
    $this->exportToFileStatusForm($form, $form_state);
    $this->getGroupExportFileForm($form, $form_state);
    return $form;
  }

  /**
   * Create the form to run any request.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function requestBuilderForm(array &$form, FormStateInterface $form_state): void {
    $form['pwbi_request_builder'] = [
      '#type' => 'details',
      '#title' => $this->t('Request builder test.'),
      '#open' => FALSE,
    ];
    $form['pwbi_request_builder']['pwbi_api_method'] = [
      '#type' => 'select',
      '#options' => ['get' => 'GET', 'post' => 'POST'],
      '#title' => $this->t('Request Method'),
    ];
    $form['pwbi_request_builder']['pwbi_api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Request Endpoint'),
      '#size' => 500,
      '#maxlength' => 500,
    ];
    $form['pwbi_request_builder']['pwbi_api_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Request body ()'),
    ];
    $form['pwbi_request_builder']['request_builder_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="request-builder-result-message"></div>',
    ];

    $form['pwbi_request_builder']['actions'] = [
      '#type' => 'actions',
    ];
    $form['pwbi_request_builder']['actions']['request_builder'] = [
      '#type' => 'button',
      '#name' => 'builder',
      '#value' => $this->t('Test'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::runRequest',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
    ];
  }

  /**
   * Create the form to run the endpoint to generate an embed token.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function getEmbedTokenForm(array &$form, FormStateInterface $form_state): void {
    $form['pwbi_get_embed_token'] = [
      '#type' => 'details',
      '#title' => $this->t('Get embed token test.'),
      '#open' => FALSE,
    ];

    $form['pwbi_get_embed_token']['pwbi_api_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Request body ()'),
    ];
    $form['pwbi_get_embed_token']['embed_token_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="embed-token-result-message"></div>',
    ];

    $form['pwbi_get_embed_token']['actions'] = [
      '#type' => 'actions',
    ];
    $form['pwbi_get_embed_token']['actions']['embed_token'] = [
      '#type' => 'button',
      '#value' => $this->t('Test'),
      '#button_type' => 'primary',
      '#name' => 'embed_token',
      '#ajax' => [
        'callback' => '::runRequest',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
    ];
  }

  /**
   * Create the form to run the endpoint to export a report.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function exportToFileForm(array &$form, FormStateInterface $form_state): void {
    $form['pwbi_to_file'] = [
      '#type' => 'details',
      '#title' => $this->t('Export report to file.'),
      '#open' => FALSE,
    ];
    $form['pwbi_to_file']['pwbi_to_file_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Request body ()'),
    ];
    $form['pwbi_to_file']['pwbi_to_file_report'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report Id'),
    ];
    $form['pwbi_to_file']['pwbi_to_file_workspace'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report Workspace Id'),
    ];
    $form['pwbi_to_file']['export_to_file_token_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="export-to-file-result-message"></div>',
    ];

    $form['pwbi_to_file']['actions'] = [
      '#type' => 'actions',
    ];
    $form['pwbi_to_file']['actions']['to_file'] = [
      '#type' => 'button',
      '#name' => 'export_file',
      '#value' => $this->t('Test'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::runRequest',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
    ];
  }

  /**
   * Create the form to run the endpoint to get the status of a file export.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function exportToFileStatusForm(array &$form, FormStateInterface $form_state): void {
    $form['pwbi_to_file_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Export report to file status.'),
      '#open' => FALSE,
    ];
    $form['pwbi_to_file_status']['pwbi_to_file_status_report'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report Id'),
    ];
    $form['pwbi_to_file_status']['pwbi_to_file_status_workspace'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report Workspace Id'),
    ];
    $form['pwbi_to_file_status']['pwbi_to_file_status_exportid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The export file Id'),
    ];
    $form['pwbi_to_file_status']['export_to_file_token_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="export-to-file-status-result-message"></div>',
    ];

    $form['pwbi_to_file_status']['actions'] = [
      '#type' => 'actions',
    ];
    $form['pwbi_to_file_status']['actions']['to_file_status'] = [
      '#type' => 'button',
      '#name' => 'export_file_status',
      '#value' => $this->t('Test'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::runRequest',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
    ];
  }

  /**
   * Create the form to run the endpoint to export a report.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function getGroupExportFileForm(array &$form, FormStateInterface $form_state): void {
    $form['pwbi_get_file'] = [
      '#type' => 'details',
      '#title' => $this->t('Get the exported file.'),
      '#open' => FALSE,
    ];
    $form['pwbi_get_file']['pwbi_get_file_report'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report Id'),
    ];
    $form['pwbi_get_file']['pwbi_get_file_workspace'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report Workspace Id'),
    ];
    $form['pwbi_get_file']['pwbi_get_file_exportid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The export file Id'),
    ];
    $form['pwbi_get_file']['pwbi_get_file_filename'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The export file name'),
    ];
    $form['pwbi_get_file']['export_to_file_token_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="export-get-file-result-message"></div>',
    ];

    $form['pwbi_get_file']['actions'] = [
      '#type' => 'actions',
    ];
    $form['pwbi_get_file']['actions']['get_file'] = [
      '#type' => 'button',
      '#name' => 'get_file',
      '#value' => $this->t('Test'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::runRequest',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
    ];
  }

  /**
   * Create the form to run the endpoint to execute queries.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function executeQueryForm(array &$form, FormStateInterface $form_state): void {
    $form['pwbi_query'] = [
      '#type' => 'details',
      '#title' => $this->t('Execute a query on the PowerBi dataset.'),
      '#open' => FALSE,
    ];
    $form['pwbi_query']['pwbi_query_dataset'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dataset Id'),
    ];
    $form['pwbi_query']['pwbi_query_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Request body ()'),
    ];
    $form['pwbi_query']['execute_query_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="execute-query-result-message"></div>',
    ];

    $form['pwbi_query']['actions'] = [
      '#type' => 'actions',
    ];
    $form['pwbi_query']['actions']['query'] = [
      '#type' => 'button',
      '#name' => 'query',
      '#value' => $this->t('Test'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::runRequest',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
    ];
  }

  /**
   * Process ajax request to identify the action.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response from the request to show.
   */
  public function runRequest(array &$form, FormStateInterface $form_state): AjaxResponse {
    $requestToRun = [
      'builder' => 'requestBuilder',
      'embed_token' => 'generateToken',
      'export_file' => 'exportToFile',
      'export_file_status' => 'exportToFileStatus',
      'get_file' => 'getGroupExportFile',
      'query' => 'runQuery',
    ];
    return $this->{$requestToRun[$form_state->getTriggeringElement()['#name']]}($form, $form_state);
  }

  /**
   * Call method to run any request.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response from the request to show.
   *
   * @throws \Exception
   *   Can throw a generic exception.
   */
  public function requestBuilder(array &$form, FormStateInterface $form_state): AjaxResponse {
    $method = $form_state->getValue('pwbi_api_method');
    $endpoint = $form_state->getValue('pwbi_api_endpoint');
    $body = $form_state->getValue('pwbi_api_body');
    $requestResponse = $this->client->connect($method, $endpoint, (array) json_decode($body));
    $message = [
      '#markup' => $requestResponse,
    ];
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#request-builder-result-message', $message));
    return $response;
  }

  /**
   * Call method to generate an embed token.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response from the request to show.
   *
   * @throws \Exception
   *   Can throw a generic exception.
   */
  public function generateToken(array &$form, FormStateInterface $form_state):AjaxResponse {
    $body = $form_state->getValue('pwbi_api_body');
    $requestResponse = $this->client->getEmbedToken((array) json_decode($body));
    $message = [
      '#markup' => $requestResponse,
    ];
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#embed-token-result-message', $message));
    return $response;
  }

  /**
   * Call method to execute query on a dataset.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response from the request to show.
   *
   * @throws \Exception
   *   Can throw a generic exception.
   */
  public function runQuery(array &$form, FormStateInterface $form_state): AjaxResponse {
    $datasetId = $form_state->getValue('pwbi_query_dataset');
    $body = $form_state->getValue('pwbi_query_body');
    $requestResponse = $this->client->executeQuery($datasetId, (array) json_decode($body));
    $message = [
      '#markup' => $requestResponse,
    ];
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#execute-query-result-message', $message));
    return $response;
  }

  /**
   * Call method to export report to file.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response from the request to show.
   *
   * @throws \Exception
   *   Can throw a generic exception.
   */
  public function exportToFile(array &$form, FormStateInterface $form_state): AjaxResponse {
    $body = $form_state->getValue('pwbi_to_file_body');
    $body = json_decode($body);
    $reportId = $form_state->getValue('pwbi_to_file_report');
    $workspace = $form_state->getValue('pwbi_to_file_workspace');
    $requestResponse = $this->client->exportGroupReportToFile($workspace, $reportId, $body);
    $message = [
      '#markup' => $requestResponse,
    ];
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#export-to-file-result-message', $message));
    return $response;
  }

  /**
   * Call method to get a file export status.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response from the request to show.
   */
  public function exportToFileStatus(array &$form, FormStateInterface $form_state): AjaxResponse {
    $workspace = $form_state->getValue('pwbi_to_file_status_workspace');
    $reportId = $form_state->getValue('pwbi_to_file_status_report');
    $exportId = $form_state->getValue('pwbi_to_file_status_exportid');
    $requestResponse = $this->client->getGroupExportToFileStatus($workspace, $reportId, $exportId);
    $message = [
      '#markup' => $requestResponse,
    ];
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#export-to-file-status-result-message', $message));
    return $response;
  }

  /**
   * Call method to get an exported file.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response from the request to show.
   */
  public function getGroupExportFile(array &$form, FormStateInterface $form_state): AjaxResponse {
    $workspace = $form_state->getValue('pwbi_get_file_workspace');
    $reportId = $form_state->getValue('pwbi_get_file_report');
    $exportId = $form_state->getValue('pwbi_get_file_exportid');
    $filename = $form_state->getValue('pwbi_get_file_filename');
    $requestResponse = $this->client->getGroupExportFile($workspace, $reportId, $exportId, "public://", $filename);
    $message = [
      '#markup' => $requestResponse,
    ];
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#export-get-file-result-message', $message));
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
