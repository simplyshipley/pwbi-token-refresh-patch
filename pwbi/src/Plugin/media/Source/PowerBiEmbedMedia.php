<?php

namespace Drupal\pwbi\Plugin\media\Source;

use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;

/**
 * Media source type to manage PowerBi embedding.
 *
 * @see \Drupal\file\FileInterface
 *
 * @MediaSource(
 *   id = "pwbi_embed_visual",
 *   label = @Translation("PowerBi embed"),
 *   description = @Translation("Add reports to embed into the site."),
 *   allowed_field_types = {"pwbi_embed"},
 * )
 */
class PowerBiEmbedMedia extends MediaSourceBase {

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line return value type not defined.
   */
  public function getMetadataAttributes(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $attribute_name) {
  }

}
