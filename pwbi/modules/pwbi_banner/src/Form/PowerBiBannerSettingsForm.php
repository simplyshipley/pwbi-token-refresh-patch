<?php

declare(strict_types=1);

namespace Drupal\pwbi_banner\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration for Power Bi disclaimer banner.
 */
class PowerBiBannerSettingsForm extends ConfigFormBase {

  const int DISPLAY_BANNER_AS_BLOCK = 0;
  const int DISPLAY_BANNER_TOP = 1;
  const int DISPLAY_BANNER_BLOCKING = 2;
  const int BANNER_TYPE_DISCLAIMER = 0;
  const int BANNER_TYPE_MUST_HAVE = 1;
  const string BANNER_DEFAULT_TEXT = 'This content is hosted by a third party. By showing the external content you accept the <a href="https://privacy.microsoft.com/en-US/privacystatement" title="Terms and conditions" target="_blank">terms and conditions</a> of app.powerbi.com.';

  /*
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'pwbi_banner.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'pwbi_banner_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);
    $form['banner_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Banner Type'),
      '#default_value' => $config->get('banner_type') ?? 0,
      '#options' => [
        self::BANNER_TYPE_DISCLAIMER => $this->t('Disclaimer'),
        self::BANNER_TYPE_MUST_HAVE => $this->t('Must accept'),
      ],
    ];
    $form['display_options'] = [
      '#type' => 'radios',
      '#title' => $this->t('Display Option'),
      '#default_value' => $config->get('display_options') ?? 0,
      '#options' => [
        self::DISPLAY_BANNER_AS_BLOCK => $this->t('Show as block'),
        self::DISPLAY_BANNER_TOP => $this->t('Show on every PowerBI embed as top overlay'),
        self::DISPLAY_BANNER_BLOCKING => $this->t('Show on every PowerBI embed as block overlay'),
      ],
    ];
    $form['banner_text'] = [
      '#type' => 'text_format',
      '#required' => TRUE,
      '#title' => $this->t('Banner text'),
      '#format' => $config->get('banner_text')['format'] ?? NULL,
      '#default_value' => $config->get('banner_text')['value'] ?? self::BANNER_DEFAULT_TEXT,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config(static::SETTINGS)
      ->set('banner_type', $form_state->getValue('banner_type'))
      ->set('display_options', $form_state->getValue('display_options'))
      ->set('banner_text', $form_state->getValue('banner_text'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
