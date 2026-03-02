<?php

declare(strict_types=1);

namespace Drupal\pwbi\Traits;

/**
 * Functions related to PowerBi languages.
 */
trait PowerBiLangTrait {

  /**
   * Get powerbi language code from drupal's.
   *
   * @param string $drupal_langcode
   *   Drupal langcode.
   *
   * @return string
   *   PowerBi langcode.
   */
  public function getPowerBiLangcode(string $drupal_langcode): string {
    $lang_mappping = [
      "ar" => "ar-SA",
      "bg" => "bg-BG",
      "ca" => "ca-ES",
      "cs" => "cs-CZ",
      "da" => "da-DK",
      "de" => "de-DE",
      "el" => "el-GR",
      "en" => "en-US",
      "es" => "es-ES",
      "et" => "et-EE",
      "eu" => "eU-ES",
      "fi" => "fi-FI",
      "fr" => "fr-FR",
      "gl" => "gl-ES",
      "he" => "he-IL",
      "hi" => "hi-IN",
      "hr" => "hr-HR",
      "hu" => "hu-HU",
      "id" => "id-ID",
      "it" => "it-IT",
      "ja" => "ja-JP",
      "kk" => "kk-KZ",
      "ko" => "ko-KR",
      "lt" => "lt-LT",
      "lv" => "lv-LV",
      "ms" => "ms-MY",
      "nb" => "nb-NO",
      "nl" => "nl-NL",
      "pl" => "pl-PL",
      "pt-br" => "pt-BR",
      "pt-pt" => "pt-PT",
      "ro" => "ro-RO",
      "ru" => "ru-RU",
      "sk" => "sk-SK",
      "sl" => "sl-SI",
      "sr" => "sr-Cyrl-RS",
      "sv" => "sv-SE",
      "th" => "th-TH",
      "tr" => "tr-TR",
      "uk" => "uk-UA",
      "vi" => "vi-VN",
      "zn-hans" => "zh-CN",
      "zn-hant" => "zh-TW",
    ];
    return $lang_mappping[$drupal_langcode] ?? "en-US";
  }

}
