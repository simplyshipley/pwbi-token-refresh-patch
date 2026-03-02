<?php

declare(strict_types=1);

namespace Drupal\pwbi_purge\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\RedundantEditableConfigNamesTrait;

/**
 * Configuration for Power Bi purge.
 */
class PowerBiPurgeSettingsForm extends ConfigFormBase {

  use RedundantEditableConfigNamesTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'pwbi_purge_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['cron_window'] = [
      '#type' => 'number',
      '#min' => 1,
      '#max' => 3600,
      '#title' => $this->t('Cron window'),
      '#config_target' => 'pwbi_purge.settings:cron_window',
      '#description' => $this->t('The time elapsing between two cron runs in seconds. Add the time it takes to purge. Power BI access token have a maximum expiration of one hour.'),
    ];

    return parent::buildForm($form, $form_state);
  }

}
