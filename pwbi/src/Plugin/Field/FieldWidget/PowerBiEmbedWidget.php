<?php

declare(strict_types=1);

namespace Drupal\pwbi\Plugin\Field\FieldWidget;

use Drupal\breakpoint\BreakpointManager;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\pwbi\Plugin\Field\FieldType\PowerBiEmbedField;
use Drupal\pwbi\PowerBiEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Widget to for the PowerBi embed field.
 *
 * @FieldWidget(
 *   id = "pwbi_embed_widget",
 *   label = @Translation("PowerBi Embed report id"),
 *   description = @Translation("The report id to embed."),
 *   field_types = {
 *     "pwbi_embed",
 *   }
 * )
 */
class PowerBiEmbedWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line $settings and $third_party_settings value type not defined.
   */
  public function __construct(
    protected string $plugin_id,
    protected mixed $plugin_definition,
    protected FieldDefinitionInterface $field_definition,
    protected $settings,
    protected array $third_party_settings,
    protected PowerBiEmbed $pwbiEmbed,
    protected BreakpointManager $breakpointManager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line $configuration value type not defined.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    // @phpstan-ignore-next-line
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('pwbi_embed.embed'),
      $container->get('breakpoint.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line return value type not defined.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $settings = $this->pwbiEmbed->getEmbedConfiguration();
    $workspaceOptions = [];
    /** @var \Drupal\pwbi\Plugin\Field\FieldType\PowerBiEmbedField $pwbi_field */
    $pwbi_field = $items[$delta];

    if (isset($settings['pwbi_workspaces'])) {
      foreach (explode(PHP_EOL, $settings['pwbi_workspaces']) as $workspace) {
        $workspaceElements = explode('|', $workspace);
        $workspaceOptions[$workspaceElements[0]] = $workspaceElements[1] ?? $workspaceElements[0];
      }
    }
    $element += [
      '#type' => 'fieldset',
    ];
    $element['report_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report ID'),
      '#description' => $this->t('PowerBi Report ID'),
      '#required' => TRUE,
      '#multiple' => FALSE,
      '#default_value' => $pwbi_field->report_id ?? NULL,
      '#size' => 255,
    ];
    $element['workspace_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Report workspace'),
      '#description' => $this->t("PowerBI report's workspace"),
      '#required' => TRUE,
      '#default_value' => $pwbi_field->workspace_id ?? NULL,
      '#options' => $workspaceOptions,
      '#selection_handler' => 'default',
      '#multiple' => FALSE,
    ];
    $element['embed_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Embed type'),
      '#description' => $this->t("The kind of content that you're embedding."),
      '#default_value' => $pwbi_field->embed_type ?? 'report',
      '#options' => [
        'visual' => $this->t('Visual'),
        'report' => $this->t('Report'),
      ],
    ];
    $element['token_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Token type'),
      '#description' => $this->t('The kind of token that gives you access to Power BI. Aad (embedding for your organization) or Embed (embedding for your customers)'),
      '#default_value' => $pwbi_field->token_type ?? 'Embed',
      '#options' => [
        'Embed' => $this->t('Embed'),
        'Aad' => $this->t('Aad'),
      ],
    ];
    $element['report_layout'] = [
      '#type' => 'select',
      '#title' => $this->t('Report layout'),
      '#description' => $this->t('The report layout type.'),
      '#default_value' => $pwbi_field->report_layout ?? 3,
      '#options' => [
        0 => $this->t('Master'),
        1 => $this->t('Custom'),
        2 => $this->t('MobilePortrait'),
        3 => $this->t('MobileLandscape'),
      ],
    ];
    $element['report_width'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report width'),
      '#description' => $this->t('The container width.'),
      '#default_value' => $pwbi_field->report_width ?? 100,
    ];
    $element['report_width_units'] = [
      '#type' => 'select',
      '#title' => $this->t('Units'),
      '#description' => $this->t('The container width units.'),
      '#default_value' => $pwbi_field->report_width_units ?? '%',
      '#options' => [
        'px' => $this->t('px'),
        '%' => $this->t('%'),
      ],
    ];

    $element['report_breakpoints_height'] = [
      '#title' => $this->t('Units'),
      '#type' => 'table',
      '#header' => [
        "breakpoint" => $this->t('Breakpoint'),
        "height" => $this->t('Height'),
      ],
      '#element_validate' => [
        [$this, 'validateBreakpoints'],
      ],
    ];
    $breakPoints = $this->breakpointManager->getBreakpointsByGroup('pwbi');
    $reportHeights = is_string($pwbi_field->get('report_breakpoints_height')->getValue()) ? unserialize($pwbi_field->get('report_breakpoints_height')->getValue(), ['allowed_classes' => FALSE]) : [];
    $this->widgetBreakpoints($breakPoints, $reportHeights, $element, $pwbi_field);
    return $element;
  }

  /**
   * Serialize the breakpoints heights of the report container.
   *
   * @param array <mixed> $element
   *   The form element with the configuration.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateBreakpoints(array &$element, FormStateInterface $form_state): void {
    if (!empty($form_state->getValue('field_media_pwbi_embed_visual'))) {
      $embedValues = $form_state->getValue('field_media_pwbi_embed_visual');
      $embedValues[0]['report_breakpoints_height'] = serialize($embedValues[0]['report_breakpoints_height']);
      $form_state->setValue('field_media_pwbi_embed_visual', $embedValues);
    }
  }

  /**
   * Create elements for height size configuration.
   *
   * @param array <\Drupal\breakpoint\BreakpointInterface> $breakPoints
   *   The height breakpoints.
   * @param array <array<array<string>>> $reportHeights
   *   The height values for each breakpoint.
   * @param array <mixed> $element
   *   A form element array containing. From self:formElement().
   * @param \Drupal\pwbi\Plugin\Field\FieldType\PowerBiEmbedField $pwbi_field
   *   Pwbi field with the display configuration.
   */
  protected function widgetBreakpoints(array $breakPoints, array $reportHeights, array &$element, PowerBiEmbedField $pwbi_field): void {
    foreach ($breakPoints as $key => $value) {
      $element['report_breakpoints_height'][$key]['breakpoint'] = [
        '#type' => 'label',
        '#title' => $value->getLabel() . "-" . $value->getMediaQuery(),
      ];
      $element['report_breakpoints_height'][$key]['height'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Height'),
        '#default_value' => $reportHeights[$key]['height'] ?? 100,
      ];
    }

    $element['report_height_units'] = [
      '#type' => 'select',
      '#title' => $this->t('Units'),
      '#description' => $this->t('The container height units.'),
      '#default_value' => $pwbi_field->report_height_units ?? '%',
      '#options' => [
        'px' => $this->t('px'),
        '%' => $this->t('%'),
      ],
    ];
  }

}
