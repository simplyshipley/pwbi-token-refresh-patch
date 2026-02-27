<?php

declare(strict_types=1);

namespace Drupal\pwbi\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\pwbi\PowerBiEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form to access PowerBi.
 */
class PowerBiEmbedConfigForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected readonly PowerBiEmbed $pwbiEmbed,
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Existing: save workspaces to State API.
    $settings = ['pwbi_workspaces' => $form_state->getValue('pwbi_workspaces')];
    $this->pwbiEmbed->setEmbedConfiguration($settings);

    // Save API endpoint to Config API.
    $this->config('pwbi.settings')
      ->set('pwbi_api_endpoint', $form_state->getValue('pwbi_api_endpoint'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
