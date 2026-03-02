<?php

declare(strict_types=1);

namespace Drupal\pwbi\Oauth2Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\pwbi\Cert\CertificateManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Implements changes to pwbi oauth plugin form.
 */
class FormChanges implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected readonly CertificateManager $certificate_manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('pwbi.cert_manager'),
    );
  }

  /**
   * Add tenant input field to plugin configuration form.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function addFormChanges(array &$form, FormStateInterface $form_state): void {
    $this->addTenant($form, $form_state);
    $this->addUseCertFile($form, $form_state);
    $this->addUploadCertFileCertFile($form, $form_state);
    $form['#validate'][] = ['Drupal\pwbi\Oauth2Form\StaticOperations', 'validateCertFile'];
    $form['#entity_builders'][] = ['Drupal\pwbi\Oauth2Form\StaticOperations', 'entityFormBuilder'];
  }

  /**
   * Add tenant input field to plugin configuration form.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  private function addTenant(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\oauth2_client\Form\Oauth2ClientForm $oauth_form */
    $oauth_form = $form_state->getFormObject();
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client $oauth_entity */
    $oauth_entity = $oauth_form->getEntity();
    $tenant = $oauth_entity->getThirdPartySetting('pwbi', 'tenant');
    $form['plugin_settings']['oauth2_client']['tenant'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tenant'),
      '#default_value' => $tenant,
    ];
  }

  /**
   * Checkbox to control if using certificate plugin configuration form.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  private function addUseCertFile(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\oauth2_client\Form\Oauth2ClientForm $oauth_form */
    $oauth_form = $form_state->getFormObject();
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client $oauth_entity */
    $oauth_entity = $oauth_form->getEntity();
    $use_cert = $oauth_entity->getThirdPartySetting('pwbi', 'use_cert');
    $form['plugin_settings']['oauth2_client']['use_cert'] = [
      '#type' => 'select',
      '#title' => $this->t('Use certificate for login'),
      '#options' => [
        "0" => $this->t('Do not use certificate'),
        "1" => $this->t('Use certificate'),
      ],
      '#default_value' => $use_cert == 1 ? 1 : 0,
    ];
  }

  /**
   * Add certificate file upload to plugin configuration form.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  private function addUploadCertFileCertFile(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\oauth2_client\Form\Oauth2ClientForm $oauth_form */
    $oauth_form = $form_state->getFormObject();
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client $oauth_entity */
    $oauth_entity = $oauth_form->getEntity();
    if ($this->certificate_manager->definedInSettings($oauth_entity->getOriginalId())) {
      $form['plugin_settings']['oauth2_client']['cert_file_path'] = [
        '#type' => 'item',
        '#title' => $this->t('Certificate file configured in settings.php'),
        '#markup' => $this->certificate_manager->getCertificatePath($oauth_entity->getOriginalId()),
      ];
      return;
    }

    $allowed_ext = 'pem';
    $max_upload = 25600000;
    $cert_file_fid = $this->certificate_manager->getManagedCertificateFid($oauth_entity->getOriginalId());

    $form['plugin_settings']['oauth2_client']['cert_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Certificate file'),
      '#description' => $this->t('Valid extensions: @allowed_ext', ['@allowed_ext' => $allowed_ext]),
      '#upload_location' => 'private://pwbi_cert/',
      '#multiple' => FALSE,
      '#required' => FALSE,
      '#upload_validators' => [
        'FileExtension' => ['extensions' => $allowed_ext],
        'FileSizeLimit' => ['fileLimit' => $max_upload],
      ],
      '#default_value' => $cert_file_fid ? [$cert_file_fid] : '',
    ];
  }

}
