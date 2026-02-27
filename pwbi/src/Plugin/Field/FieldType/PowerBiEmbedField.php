<?php

namespace Drupal\pwbi\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Field with the options to embed from PowerBi.
 *
 * @FieldType(
 *   id = "pwbi_embed",
 *   module = "pwbi_embed",
 *   label = @Translation("PowerBi Embed report"),
 *   category = "reference",
 *   description = @Translation("This field stores the report id and its workspace."),
 *   default_widget = "pwbi_embed_widget",
 *   default_formatter = "pwbi_embed_formatter",
 *   column_groups = {
 *     "report_id" = {
 *       "label" = @Translation("Report ID"),
 *       "translatable" = FALSE
 *     },
 *     "workspace_id" = {
 *       "label" = @Translation("The workspace of the report"),
 *       "translatable" = TRUE
 *     },
 *   },
 * )
 */
class PowerBiEmbedField extends FieldItemBase {

  /**
   * {@inheritDoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {

    $properties = [];

    $properties['report_id'] = DataDefinition::create('string')
      ->setLabel(t('Report Id'))
      ->setDescription(t('PowerBi Report Id'));

    $properties['workspace_id'] = DataDefinition::create('string')
      ->setLabel(t('Report width'))
      ->setDescription(t('The report workspace'));

    $properties['embed_type'] = DataDefinition::create('string')
      ->setLabel(t('Embed type'))
      ->setDescription(t("The kind of content that you're embedding."));

    $properties['token_type'] = DataDefinition::create('string')
      ->setLabel(t('Token type'))
      ->setDescription(t('The kind of token that gives you access to Power BI. Aad (embedding for your organization) or Embed (embedding for your customers)'));

    $properties['report_layout'] = DataDefinition::create('float')
      ->setLabel(t('Report layout'))
      ->setDescription(t('PowerBI Report layout'));

    $properties['report_width'] = DataDefinition::create('float')
      ->setLabel(t('Report width'))
      ->setDescription(t('PowerBI Report width'));

    $properties['report_height'] = DataDefinition::create('float')
      ->setLabel(t('Default Report height'))
      ->setDescription(t('Default PowerBI Report height'));

    $properties['report_height_units'] = DataDefinition::create('string')
      ->setLabel(t('Height Size units'))
      ->setDescription(t('PowerBI Report height units'));

    $properties['report_width_units'] = DataDefinition::create('string')
      ->setLabel(t('Width Size units'))
      ->setDescription(t('PowerBI Report width size'));

    $properties['report_breakpoints_height'] = DataDefinition::create('string')
      ->setLabel(t('Responsive height breakpoints'))
      ->setDescription(t('PowerBI Report height for different breakpoints'));

    return $properties;
  }

  /**
   * {@inheritDoc}
   *
   * @phpstan-ignore-next-line return value type not defined.
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    $columns = [
      'report_id' => [
        'type' => 'varchar',
        'length' => 36,
      ],
      'workspace_id' => [
        'type' => 'varchar',
        'length' => 36,
      ],
      'report_height' => [
        'type' => 'float',
      ],
      'report_layout' => [
        'type' => 'int',
      ],
      'report_width' => [
        'type' => 'float',
      ],
      'token_type' => [
        'type' => 'varchar',
        'length' => 10,
      ],
      'embed_type' => [
        'type' => 'varchar',
        'length' => 10,
      ],
      'report_height_units' => [
        'type' => 'varchar',
        'length' => 5,
      ],
      'report_width_units' => [
        'type' => 'varchar',
        'length' => 5,
      ],
      'report_breakpoints_height' => [
        'type' => 'varchar',
        'length' => 1024,
      ],
    ];

    return [
      'columns' => $columns,
      'indexes' => [],
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function isEmpty(): bool {
    $value = $this->get('report_id')->getValue();
    return $value === NULL;
  }

}
