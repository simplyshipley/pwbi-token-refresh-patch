<?php

declare(strict_types=1);

namespace Drupal\pwbi\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\pwbi\PowerBiEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form to access PowerBi.
 */
class PowerBiEmbedConfigForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected readonly PowerBiEmbed $pwbiEmbed,
    protected readonly StateInterface $state,
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('config.factory'),
      $container->get('pwbi_embed.embed'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['pwbi.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'pwbi_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    $settings = $this->pwbiEmbed->getEmbedConfiguration();
    $form['pwbi_workspaces'] = [
      '#type' => 'textarea',
      '#title' => $this->t('PowerBi Workspaces'),
      '#default_value' => $settings['pwbi_workspaces'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Add here a list of available workspaces with the form workspaceid|work space name, for example: <div>1234512345|My workspace</div><div>112345465767|Another workspace</div>'),
    ];

    $pwbi_settings = $this->config('pwbi.settings');

    $form['pwbi_api_endpoint'] = [
      '#type' => 'select',
      '#title' => $this->t('Power BI API endpoint'),
      '#default_value' => $pwbi_settings->get('pwbi_api_endpoint') ?? 'https://api.powerbi.com',
      '#options' => [
        'https://api.powerbi.com'         => $this->t('Commercial (api.powerbi.com)'),
        'https://api.powerbigov.us'        => $this->t('US Government GCC (api.powerbigov.us)'),
        'https://api.high.powerbigov.us'   => $this->t('US Government GCC High (api.high.powerbigov.us)'),
        'https://api.mil.powerbigov.us'    => $this->t('US DoD (api.mil.powerbigov.us)'),
      ],
      '#description' => $this->t('Select the Power BI REST API root for your tenant cloud environment.'),
    ];

    $form['token_refresh_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic embed token refresh'),
      '#default_value' => $pwbi_settings->get('token_refresh_enabled') ?? FALSE,
      '#description' => $this->t('Silently renew embed tokens before they expire, preserving all user state.'),
    ];

    $form['token_refresh_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Minutes before expiry to refresh'),
      '#default_value' => $pwbi_settings->get('token_refresh_minutes') ?? 10,
      '#min' => 1,
      '#max' => 55,
      '#description' => $this->t('Request a new token this many minutes before the current one expires.'),
      '#states' => [
        'visible' => [':input[name="token_refresh_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['debug_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug logging'),
      '#default_value' => $pwbi_settings->get('debug_enabled') ?? FALSE,
      '#description' => $this->t('Log token refresh activity to the browser console and Drupal watchdog. Disable in production.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Only validate the minutes field when token refresh is enabled; the field
    // is hidden (via #states) when disabled, and the submitted value may be
    // empty or out-of-range from a previous save with a different setting.
    if ($form_state->getValue('token_refresh_enabled')) {
      $minutes = (int) $form_state->getValue('token_refresh_minutes');
      if ($minutes < 1 || $minutes > 55) {
        $form_state->setErrorByName('token_refresh_minutes', $this->t('Minutes must be between 1 and 55.'));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Existing: save workspaces to State API.
    $settings = ['pwbi_workspaces' => $form_state->getValue('pwbi_workspaces')];
    $this->pwbiEmbed->setEmbedConfiguration($settings);

    // Detect endpoint change before saving so we can compare old vs new.
    $old_endpoint = $this->config('pwbi.settings')->get('pwbi_api_endpoint')
      ?? 'https://api.powerbi.com';
    $new_endpoint = (string) $form_state->getValue('pwbi_api_endpoint');

    // New: save API settings to Config API.
    $this->config('pwbi.settings')
      ->set('pwbi_api_endpoint', $new_endpoint)
      ->set('token_refresh_enabled', (bool) $form_state->getValue('token_refresh_enabled'))
      ->set('token_refresh_minutes', (int) $form_state->getValue('token_refresh_minutes'))
      ->set('debug_enabled', (bool) $form_state->getValue('debug_enabled'))
      ->save();

    // Clear the cached Service Principal OAuth2 token when the cloud endpoint
    // changes so a fresh token is requested with the correct audience scope.
    if ($old_endpoint !== $new_endpoint) {
      $this->state->delete('oauth2_client_access_token-pwbi_service_principal');
      $this->messenger()->addWarning($this->t(
        'Power BI API endpoint changed — OAuth2 token cache cleared. A fresh token will be requested on next use.'
      ));
    }

    parent::submitForm($form, $form_state);
  }

}
