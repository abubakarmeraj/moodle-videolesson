<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AWS Translate supported languages for subtitle generation
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

/**
 * Class containing AWS Translate supported languages
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subtitle_languages {
    /**
     * Get all AWS Translate supported languages
     *
     * @return array Array of language codes => display names
     */
    public static function get_supported_languages(): array {
        return [
            'af' => 'Afrikaans',
            'sq' => 'Albanian',
            'am' => 'Amharic',
            'ar' => 'Arabic',
            'hy' => 'Armenian',
            'az' => 'Azerbaijani',
            'bn' => 'Bengali',
            'bs' => 'Bosnian',
            'bg' => 'Bulgarian',
            'ca' => 'Catalan',
            'zh' => 'Chinese (Simplified)',
            'zh-TW' => 'Chinese (Traditional)',
            'hr' => 'Croatian',
            'cs' => 'Czech',
            'da' => 'Danish',
            'fa-AF' => 'Dari',
            'nl' => 'Dutch',
            'en' => 'English',
            'et' => 'Estonian',
            'fa' => 'Persian (Farsi)',
            'tl' => 'Filipino, Tagalog',
            'fi' => 'Finnish',
            'fr' => 'French',
            'fr-CA' => 'French (Canada)',
            'ka' => 'Georgian',
            'de' => 'German',
            'el' => 'Greek',
            'gu' => 'Gujarati',
            'ht' => 'Haitian Creole',
            'ha' => 'Hausa',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'hu' => 'Hungarian',
            'is' => 'Icelandic',
            'id' => 'Indonesian',
            'ga' => 'Irish',
            'it' => 'Italian',
            'ja' => 'Japanese',
            'kn' => 'Kannada',
            'kk' => 'Kazakh',
            'ko' => 'Korean',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'mk' => 'Macedonian',
            'ms' => 'Malay',
            'ml' => 'Malayalam',
            'mt' => 'Maltese',
            'mr' => 'Marathi',
            'mn' => 'Mongolian',
            'no' => 'Norwegian',
            'ps' => 'Pashto',
            'pl' => 'Polish',
            'pt' => 'Portuguese (Brazil)',
            'pt-PT' => 'Portuguese (Portugal)',
            'pa' => 'Punjabi',
            'ro' => 'Romanian',
            'ru' => 'Russian',
            'sr' => 'Serbian',
            'si' => 'Sinhala',
            'sk' => 'Slovak',
            'sl' => 'Slovenian',
            'so' => 'Somali',
            'es' => 'Spanish',
            'es-MX' => 'Spanish (Mexico)',
            'sw' => 'Swahili',
            'sv' => 'Swedish',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'th' => 'Thai',
            'tr' => 'Turkish',
            'uk' => 'Ukrainian',
            'ur' => 'Urdu',
            'uz' => 'Uzbek',
            'vi' => 'Vietnamese',
            'cy' => 'Welsh',
            'original' => 'Original Language',
        ];
    }

    /**
     * Check if a language code is supported
     *
     * @param string $langcode Language code to check
     * @return bool True if supported, false otherwise
     */
    public static function is_supported(string $langcode): bool {
        $languages = self::get_supported_languages();
        return isset($languages[$langcode]);
    }

    /**
     * Get display name for a language code
     *
     * @param string $langcode Language code
     * @return string Display name or empty string if not found
     */
    public static function get_display_name(string $langcode): string {
        $languages = self::get_supported_languages();
        return $languages[$langcode] ?? '';
    }
}
