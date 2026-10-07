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
 * Admin settings: show/hide AWS fields based on hosting type.
 *
 * @module     mod_videolesson/settings_hosting_toggle
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Wire hosting type select and free-hosting checkbox to dependent field rows.
 */
const toggleFieldsBasedOnHostingType = () => {
    const hostingType = document.querySelector('select[name="s_mod_videolesson_hosting_type"]');
    const freeHostingCheckbox = document.querySelector(
        'input[name="s_mod_videolesson_use_free_hosting"][type="checkbox"]'
    );
    const licenseKeyField = document.querySelector('input[name="s_mod_videolesson_license_key"]');

    if (!hostingType) {
        return;
    }

    const fieldsToToggle = [
        'input[name="s_mod_videolesson_api_key"]',
        'input[name="s_mod_videolesson_api_secret"]',
        'input[name="s_mod_videolesson_s3_input_bucket"]',
        'input[name="s_mod_videolesson_s3_output_bucket"]',
        'select[name="s_mod_videolesson_api_region"]',
        'input[name="s_mod_videolesson_sns_topic_arn"]',
        'input[name="s_mod_videolesson_cloudfrontdomain"]',
        'input[name="s_mod_videolesson_dynamodb_table_name"]',
    ];

    const setFieldsState = (isDisabled) => {
        fieldsToToggle.forEach((selector) => {
            const field = document.querySelector(selector);
            const fieldRow = field ? field.closest('.form-item.row') : null;

            if (field) {
                field.disabled = isDisabled;
            }

            if (fieldRow) {
                fieldRow.style.display = isDisabled ? 'none' : '';
            }
        });
    };

    const setFreeHostingFieldState = (isHidden) => {
        if (!freeHostingCheckbox) {
            return;
        }
        freeHostingCheckbox.disabled = isHidden;

        const freeHostingCheckboxRow = freeHostingCheckbox.closest('.form-item.row');
        if (freeHostingCheckboxRow) {
            freeHostingCheckboxRow.style.display = isHidden ? 'none' : '';
        }
    };

    const setLicenseKeyFieldState = (isHidden) => {
        if (licenseKeyField) {
            const licenseKeyFieldRow = licenseKeyField.closest('.form-item.row');
            if (licenseKeyFieldRow) {
                licenseKeyFieldRow.style.display = isHidden ? 'none' : '';
            }
        }
    };

    const refreshFieldsState = () => {
        const hostingTypeValue = hostingType.value;
        const isSelfManaged = hostingTypeValue === 'self';
        const isHosted = hostingTypeValue === 'hosted';
        const useFreeHosting = !!(freeHostingCheckbox && freeHostingCheckbox.checked);

        setFieldsState(!isSelfManaged);
        setFreeHostingFieldState(!isHosted);
        setLicenseKeyFieldState(!isHosted || useFreeHosting);
    };

    refreshFieldsState();

    hostingType.addEventListener('change', () => {
        refreshFieldsState();
    });

    if (freeHostingCheckbox) {
        freeHostingCheckbox.addEventListener('change', () => {
            refreshFieldsState();
        });
    }
};

/**
 * Initialise hosting-dependent field visibility on the plugin admin settings page.
 */
export const init = () => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', toggleFieldsBasedOnHostingType);
    } else {
        toggleFieldsBasedOnHostingType();
    }
};
