<?php

declare(strict_types=1);

namespace Drupal\pwbi\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\pwbi\PowerBiEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form to access PowerBi.
 */
class PowerBiEmbedConfigForm extends FormBase {

  public function __construct(
    protected readonly PowerBiEmbed $pwbiEmbed,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('pwbi_embed.embed'),
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
    return 'pwbi_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = $this->pwbiEmbed->getEmbedConfiguration();
    $form['pwbi_workspaces'] = [
      '#type' => 'textarea',
      '#title' => $this->t('PowerBi Workspaces'),
      '#default_value' => $settings['pwbi_workspaces'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Add here a list of available workspaces with the form workspaceid|work space name, for example: <div>1234512345|My workspace</div><div>112345465767|Another workspace</div>'),
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = [
      'pwbi_workspaces',
    ];
    $settings = [];
    foreach ($values as $value) {
      $settings[$value] = $form_state->getValue($value);
    }
    $this->pwbiEmbed->setEmbedConfiguration($settings);
  }

}
