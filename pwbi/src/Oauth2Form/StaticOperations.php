<?php

declare(strict_types=1);

namespace Drupal\pwbi\Oauth2Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\oauth2_client\Entity\Oauth2Client;
use Drupal\file\Entity\File;

/**
 * Static methods to alter pwbi oauth plugin form.
 */
class StaticOperations {

  /**
   * Check the certificate file is uploaded if needed.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function validateCertFile(array $form, FormStateInterface &$form_state): void {
    /** @var \Drupal\oauth2_client\Form\Oauth2ClientForm $oauth_form */
    $oauth_form = $form_state->getFormObject();
    /** @var \Drupal\oauth2_client\Entity\Oauth2Client $oauth_entity */
    $oauth_entity = $oauth_form->getEntity();
    /** @var \Drupal\pwbi\Cert\CertificateManager $certificate_manager */
    $certificate_manager = \Drupal::service('pwbi.cert_manager');

    if ($form_state->getValue('use_cert') == 1 && !$certificate_manager->definedInSettings($oauth_entity->getOriginalId()) && empty($form_state->getValue('cert_file'))) {
      $form_state->setError($form['plugin_settings']['oauth2_client']['cert_file'], t('The certificate file is missing.'));
    }
  }

  /**
   * Add fields to the oauth2_client config entity.
   *
   * @param string $entity_type
   *   The entity type name.
   * @param \Drupal\oauth2_client\Entity\Oauth2Client $oauth_config
   *   The entity type object.
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function entityFormBuilder(string $entity_type, Oauth2Client $oauth_config, array &$form, FormStateInterface $form_state): void {
    if ($entity_type !== 'oauth2_client') {
      return;
    }
    if ($form_state->getValue('tenant')) {
      $oauth_config->setThirdPartySetting('pwbi', 'tenant', $form_state->getValue('tenant'));
    }
    else {
      $oauth_config->unsetThirdPartySetting('pwbi', 'tenant');
    }

    if ($form_state->getValue('use_cert')) {
      $oauth_config->setThirdPartySetting('pwbi', 'use_cert', $form_state->getValue('use_cert'));
    }
    else {
      $oauth_config->unsetThirdPartySetting('pwbi', 'use_cert');
    }

    if (isset($form_state->getValue('cert_file')[0])) {
      $file = File::load($form_state->getValue('cert_file')[0]);
      /** @var \Drupal\file\FileUsage\DatabaseFileUsageBackend $file_usage */
      $file_usage = \Drupal::service('file.usage');
      $file_usage->add($file, 'pwbi', 'oauth2_client', $oauth_config->id());
      $oauth_config->setThirdPartySetting('pwbi', 'cert_file', $form_state->getValue('cert_file')[0]);
    }
    else {
      $oauth_config->unsetThirdPartySetting('pwbi', 'cert_file');
    }
  }

}
