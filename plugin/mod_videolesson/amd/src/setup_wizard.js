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
 * Setup Wizard functionality for videolesson plugin
 * UI enhancements only - state management is handled server-side via forms
 *
 * @module     mod_videolesson/setup_wizard
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Toast from 'core/toast';
import {get_string as getString} from 'core/str';

/**
 * Helper function to get element by ID
 *
 * @param {string} id Element ID
 * @returns {HTMLElement|null}
 */
const getElement = (id) => {
    return document.getElementById(id);
};

/**
 * Helper function to get elements by selector
 *
 * @param {string} selector CSS selector
 * @returns {NodeList}
 */
const getElements = (selector) => {
    return document.querySelectorAll(selector);
};

/**
 * Initialize the setup wizard
 * Only handles UI enhancements - form submissions handle navigation
 *
 * @param {number} initialStep The initial step to show
 */
export const init = (initialStep = 1) => {
    // Show the correct step on page load (server-side already handles this, but ensure it's visible)
    getElements('.step-content').forEach(el => {
        el.style.display = 'none';
    });
    const currentStepEl = getElement(`step-${initialStep}-content`);
    if (currentStepEl) {
        currentStepEl.style.display = '';
    }

    // Update step indicators
    getElements('.step-indicator').forEach(el => {
        el.classList.remove('current');
    });
    const currentIndicator = document.querySelector(`.step-indicator[data-step="${initialStep}"]`);
    if (currentIndicator) {
        currentIndicator.classList.add('current');
    }

    // Step 1: Hosting selection UI enhancements (card selection visual feedback)
    const hostingCards = getElements('.hosting-option-card');
    const hostingRadios = getElements('input[name="hosting-type"]');

    // Ensure the checked radio's card is visually selected on page load
    const checkedRadio = document.querySelector('input[name="hosting-type"]:checked');
    if (checkedRadio) {
        const checkedCard = checkedRadio.closest('.hosting-option-card');
        if (checkedCard) {
            const cardElement = checkedCard.querySelector('.hosting-card');
            if (cardElement) {
                cardElement.classList.add('selected');
            }
        }
    }

    // Handle card clicks for visual feedback
    hostingCards.forEach(card => {
        card.addEventListener('click', function() {
            const radio = this.querySelector('input[type="radio"]');

            // Unselect all cards
            hostingCards.forEach(c => {
                const cardElement = c.querySelector('.hosting-card');
                if (cardElement) {
                    cardElement.classList.remove('selected');
                }
            });

            // Select clicked card
            const clickedCard = this.querySelector('.hosting-card');
            if (clickedCard) {
                clickedCard.classList.add('selected');
            }
            if (radio) {
                radio.checked = true;
            }
        });
    });

    // Also handle radio button clicks directly
    hostingRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            const card = this.closest('.hosting-option-card');
            if (card) {
                // Trigger card click for visual feedback
                const cardElement = card.querySelector('.hosting-card');
                if (cardElement) {
                    // Unselect all cards
                    hostingCards.forEach(c => {
                        const cardEl = c.querySelector('.hosting-card');
                        if (cardEl) {
                            cardEl.classList.remove('selected');
                        }
                    });
                    // Select this card
                    cardElement.classList.add('selected');
                }
            }
        });
    });

    // Step 2: AWS Settings Form validation (enable/disable validate button)
    const step2ValidateBtn = getElement('step2-validate-btn');
    if (step2ValidateBtn) {
        // Enable/disable validate button based on form fields
        const checkFormValidity = () => {
            const apiKey = getElement('aws-api-key')?.value.trim();
            const apiSecret = getElement('aws-api-secret')?.value.trim();
            const s3InputBucket = getElement('aws-s3-input-bucket')?.value.trim();
            const s3OutputBucket = getElement('aws-s3-output-bucket')?.value.trim();

            // Enable button if all required fields are filled
            if (apiKey && apiSecret && s3InputBucket && s3OutputBucket) {
                step2ValidateBtn.disabled = false;
            } else {
                step2ValidateBtn.disabled = true;
            }
        };

        // Add event listeners to form fields
        const formFields = ['aws-api-key', 'aws-api-secret', 'aws-s3-input-bucket', 'aws-s3-output-bucket'];
        formFields.forEach(fieldId => {
            const field = getElement(fieldId);
            if (field) {
                field.addEventListener('input', checkFormValidity);
                field.addEventListener('change', checkFormValidity);
            }
        });

        // Check initial state
        checkFormValidity();

        // Handle form submission - show loading state for AWS validation
        const awsForm = document.getElementById('aws-settings-form');
        if (awsForm && step2ValidateBtn) {
            awsForm.addEventListener('submit', function() {
                // Show loading state - delay slightly to allow form submission to proceed
                setTimeout(() => {
                    step2ValidateBtn.disabled = true;
                    // Store original HTML if not already stored
                    if (!step2ValidateBtn.dataset.originalHtml) {
                        step2ValidateBtn.dataset.originalHtml = step2ValidateBtn.innerHTML;
                    }
                    // Extract text content (excluding icon)
                    const textNodes = Array.from(step2ValidateBtn.childNodes)
                        .filter(node => node.nodeType === Node.TEXT_NODE)
                        .map(node => node.textContent.trim())
                        .join(' ');
                    const buttonText = textNodes || 'Validating...';
                    // Add spinner before the icon
                    const spinnerHTML = '<span class="spinner-border spinner-border-sm mr-2" ' +
                        'role="status" aria-hidden="true"></span>';
                    step2ValidateBtn.innerHTML = spinnerHTML +
                        '<i class="fa fa-check-circle"></i> ' + buttonText;
                }, 10);
            });
        }
    }

    // Step 2 Hosted: Show/hide existing license field based on checkbox
    const hasExistingLicenseCheckbox = getElement('has-existing-license');
    const existingLicenseField = getElement('existing-license-field');
    const existingLicenseKeyInput = getElement('existing-license-key');
    const activateBtn = getElement('activate-btn');

    if (hasExistingLicenseCheckbox && existingLicenseField) {
        // Store original button HTML immediately (before any modifications)
        if (activateBtn && !activateBtn.dataset.originalHtml) {
            activateBtn.dataset.originalHtml = activateBtn.innerHTML;
        }

        // Handle checkbox change
        hasExistingLicenseCheckbox.addEventListener('change', function() {
            // Don't modify button if it's in loading state
            if (activateBtn && activateBtn.disabled) {
                return;
            }

            if (this.checked) {
                existingLicenseField.style.display = 'block';
                if (existingLicenseKeyInput) {
                    existingLicenseKeyInput.focus();
                }
                // Change button text to "Activate" - use original HTML to preserve structure
                if (activateBtn && activateBtn.dataset.originalHtml) {
                    const originalHTML = activateBtn.dataset.originalHtml;
                    // Extract icon and replace text with "Activate"
                    const iconMatch = originalHTML.match(/<i[^>]*>.*?<\/i>/);
                    const icon = iconMatch ? iconMatch[0] : '<i class="fa fa-rocket"></i>';
                    activateBtn.innerHTML = icon + ' Activate';
                }
            } else {
                existingLicenseField.style.display = 'none';
                if (existingLicenseKeyInput) {
                    existingLicenseKeyInput.value = '';
                }
                // Restore original button text
                if (activateBtn && activateBtn.dataset.originalHtml) {
                    activateBtn.innerHTML = activateBtn.dataset.originalHtml;
                }
            }
        });

        // Handle form submission - validate license key if checkbox is checked and show loading
        const form = document.getElementById('step2-hosted-form');
        if (form && activateBtn) {
            form.addEventListener('submit', function(e) {
                const action = activateBtn.getAttribute('value');
                if (action === 'activate_free_hosting') {
                    // Validate license key if checkbox is checked
                    if (hasExistingLicenseCheckbox.checked) {
                        const licenseKey = existingLicenseKeyInput?.value.trim();
                        if (!licenseKey) {
                            e.preventDefault();
                            getString('setup:step2:hosted:existing:license:key:required', 'mod_videolesson').then((message) => {
                                Toast.add(message, {type: 'error'});
                            });
                            if (existingLicenseKeyInput) {
                                existingLicenseKeyInput.focus();
                            }
                            return false;
                        }
                    }

                    // Show loading state - delay slightly to allow form submission to proceed
                    setTimeout(() => {
                        activateBtn.disabled = true;
                        // Store original HTML if not already stored
                        if (!activateBtn.dataset.originalHtml) {
                            activateBtn.dataset.originalHtml = activateBtn.innerHTML;
                        }
                        // Extract text content (excluding icon)
                        const textNodes = Array.from(activateBtn.childNodes)
                            .filter(node => node.nodeType === Node.TEXT_NODE)
                            .map(node => node.textContent.trim())
                            .join(' ');
                        const buttonText = textNodes || 'Activating...';
                        // Add spinner before the icon
                        const spinnerHTML = '<span class="spinner-border spinner-border-sm mr-2" ' +
                            'role="status" aria-hidden="true"></span>';
                        activateBtn.innerHTML = spinnerHTML +
                            '<i class="fa fa-rocket"></i> ' + buttonText;
                    }, 10);
                }
            });
        }
    }
};
