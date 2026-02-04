/**
 * ============================================================================
 * 🎯 SIGNula - Main JavaScript
 * ============================================================================
 *
 * Purpose: Core JavaScript functionality for SIGNula web interface
 * Version: 1.0.0
 * Dependencies: None (vanilla JavaScript)
 * ============================================================================
 */

(function() {
    'use strict';

    // ========================================================================
    // 🔧 Utility Functions
    // ========================================================================

    /**
     * 🔍 Select DOM Element
     * @param {string} selector - CSS selector
     * @returns {Element|null}
     */
    const $ = (selector) => document.querySelector(selector);

    /**
     * 🔍 Select Multiple DOM Elements
     * @param {string} selector - CSS selector
     * @returns {NodeList}
     */
    const $$ = (selector) => document.querySelectorAll(selector);

    /**
     * ⏱️ Debounce Function
     * @param {Function} func - Function to debounce
     * @param {number} wait - Wait time in milliseconds
     * @returns {Function}
     */
    const debounce = (func, wait = 300) => {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    };

    // ========================================================================
    // 📝 Form Validation
    // ========================================================================

    const FormValidator = {
        /**
         * ✅ Validate Email Format
         * @param {string} email - Email address
         * @returns {boolean}
         */
        isValidEmail(email) {
            const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return regex.test(email);
        },

        /**
         * 🔑 Validate Password Strength
         * @param {string} password - Password
         * @returns {Object} - {valid: boolean, errors: array}
         */
        validatePassword(password) {
            const errors = [];
            const minLength = 12;

            if (password.length < minLength) {
                errors.push(`Password must be at least ${minLength} characters long`);
            }
            if (!/[A-Z]/.test(password)) {
                errors.push('Password must contain at least one uppercase letter');
            }
            if (!/[a-z]/.test(password)) {
                errors.push('Password must contain at least one lowercase letter');
            }
            if (!/[0-9]/.test(password)) {
                errors.push('Password must contain at least one number');
            }
            if (!/[^A-Za-z0-9]/.test(password)) {
                errors.push('Password must contain at least one special character');
            }

            return {
                valid: errors.length === 0,
                errors: errors
            };
        },

        /**
         * 📏 Validate Required Field
         * @param {string} value - Field value
         * @returns {boolean}
         */
        isRequired(value) {
            return value !== null && value !== undefined && value.trim() !== '';
        },

        /**
         * 🔢 Validate Min Length
         * @param {string} value - Field value
         * @param {number} min - Minimum length
         * @returns {boolean}
         */
        minLength(value, min) {
            return value && value.length >= min;
        },

        /**
         * 🔢 Validate Max Length
         * @param {string} value - Field value
         * @param {number} max - Maximum length
         * @returns {boolean}
         */
        maxLength(value, max) {
            return value && value.length <= max;
        },

        /**
         * 🔄 Validate Field Match
         * @param {string} value1 - First value
         * @param {string} value2 - Second value
         * @returns {boolean}
         */
        matches(value1, value2) {
            return value1 === value2;
        },

        /**
         * ✅ Set Field Valid State
         * @param {Element} field - Input field
         * @param {string} message - Success message
         */
        setValid(field, message = '') {
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');

            const feedback = field.parentElement.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.style.display = 'none';
            }

            const validFeedback = field.parentElement.querySelector('.valid-feedback');
            if (validFeedback) {
                validFeedback.textContent = message;
                validFeedback.style.display = 'block';
            }
        },

        /**
         * ❌ Set Field Invalid State
         * @param {Element} field - Input field
         * @param {string} message - Error message
         */
        setInvalid(field, message) {
            field.classList.remove('is-valid');
            field.classList.add('is-invalid');

            const validFeedback = field.parentElement.querySelector('.valid-feedback');
            if (validFeedback) {
                validFeedback.style.display = 'none';
            }

            const feedback = field.parentElement.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.textContent = message;
                feedback.style.display = 'block';
            }
        },

        /**
         * 🧹 Clear Field Validation
         * @param {Element} field - Input field
         */
        clearValidation(field) {
            field.classList.remove('is-valid', 'is-invalid');

            const feedback = field.parentElement.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.style.display = 'none';
            }

            const validFeedback = field.parentElement.querySelector('.valid-feedback');
            if (validFeedback) {
                validFeedback.style.display = 'none';
            }
        }
    };

    // ========================================================================
    // 🚨 Alert System
    // ========================================================================

    const AlertManager = {
        /**
         * 📢 Show Alert
         * @param {string} message - Alert message
         * @param {string} type - Alert type (success, danger, warning, info)
         * @param {number} duration - Auto-dismiss duration (0 = no auto-dismiss)
         */
        show(message, type = 'info', duration = 5000) {
            const alertContainer = $('#alert-container') || this.createContainer();

            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible`;
            alert.innerHTML = `
                <i class="fas fa-${this.getIcon(type)}"></i>
                <span>${message}</span>
                <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;

            alertContainer.appendChild(alert);

            // Auto-dismiss
            if (duration > 0) {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => alert.remove(), 300);
                }, duration);
            }

            return alert;
        },

        /**
         * 🏗️ Create Alert Container
         * @returns {Element}
         */
        createContainer() {
            const container = document.createElement('div');
            container.id = 'alert-container';
            container.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                max-width: 400px;
                width: 100%;
            `;
            document.body.appendChild(container);
            return container;
        },

        /**
         * 🎨 Get Icon for Alert Type
         * @param {string} type - Alert type
         * @returns {string}
         */
        getIcon(type) {
            const icons = {
                success: 'check-circle',
                danger: 'exclamation-circle',
                warning: 'exclamation-triangle',
                info: 'info-circle'
            };
            return icons[type] || 'info-circle';
        }
    };

    // ========================================================================
    // 🔐 Password Visibility Toggle
    // ========================================================================

    function initPasswordToggles() {
        $$('.password-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const input = this.previousElementSibling;
                const icon = this.querySelector('i');

                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });
    }

    // ========================================================================
    // 📊 Password Strength Meter
    // ========================================================================

    function initPasswordStrengthMeter() {
        const passwordInputs = $$('input[type="password"][data-strength-meter]');

        passwordInputs.forEach(input => {
            const meter = document.createElement('div');
            meter.className = 'password-strength-meter';
            meter.style.cssText = `
                height: 4px;
                background: #e5e7eb;
                border-radius: 2px;
                margin-top: 8px;
                overflow: hidden;
            `;

            const bar = document.createElement('div');
            bar.className = 'password-strength-bar';
            bar.style.cssText = `
                height: 100%;
                width: 0;
                transition: width 0.3s, background-color 0.3s;
            `;
            meter.appendChild(bar);

            const label = document.createElement('small');
            label.className = 'password-strength-label text-muted';
            label.style.display = 'block';
            label.style.marginTop = '4px';

            input.parentElement.appendChild(meter);
            input.parentElement.appendChild(label);

            input.addEventListener('input', debounce(function() {
                const strength = calculatePasswordStrength(this.value);
                updateStrengthMeter(bar, label, strength);
            }, 300));
        });
    }

    function calculatePasswordStrength(password) {
        let strength = 0;

        if (!password) return strength;

        // Length
        if (password.length >= 8) strength += 20;
        if (password.length >= 12) strength += 20;
        if (password.length >= 16) strength += 10;

        // Character variety
        if (/[a-z]/.test(password)) strength += 10;
        if (/[A-Z]/.test(password)) strength += 10;
        if (/[0-9]/.test(password)) strength += 10;
        if (/[^A-Za-z0-9]/.test(password)) strength += 20;

        return Math.min(strength, 100);
    }

    function updateStrengthMeter(bar, label, strength) {
        bar.style.width = strength + '%';

        let color, text;
        if (strength < 30) {
            color = '#ef4444';
            text = 'Weak';
        } else if (strength < 60) {
            color = '#f59e0b';
            text = 'Fair';
        } else if (strength < 80) {
            color = '#3b82f6';
            text = 'Good';
        } else {
            color = '#10b981';
            text = 'Strong';
        }

        bar.style.backgroundColor = color;
        label.textContent = text;
        label.style.color = color;
    }

    // ========================================================================
    // 📤 AJAX Form Submission
    // ========================================================================

    function initAjaxForms() {
        $$('form[data-ajax="true"]').forEach(form => {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;

                // Disable submit button and show loading
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner"></span> Processing...';

                // Get form data
                const formData = new FormData(this);
                const data = {};
                formData.forEach((value, key) => data[key] = value);

                try {
                    const response = await fetch(this.action, {
                        method: this.method || 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(data)
                    });

                    const result = await response.json();

                    if (result.success) {
                        AlertManager.show(result.message, 'success');

                        // Redirect if specified
                        if (result.redirect) {
                            setTimeout(() => {
                                window.location.href = result.redirect;
                            }, 1000);
                        } else {
                            // Reset form
                            this.reset();
                            // Clear validation
                            $$('.form-control', this).forEach(field => {
                                FormValidator.clearValidation(field);
                            });
                        }
                    } else {
                        AlertManager.show(result.message, 'danger');

                        // Show field errors if provided
                        if (result.errors) {
                            Object.keys(result.errors).forEach(fieldName => {
                                const field = this.querySelector(`[name="${fieldName}"]`);
                                if (field) {
                                    FormValidator.setInvalid(field, result.errors[fieldName]);
                                }
                            });
                        }
                    }
                } catch (error) {
                    console.error('Form submission error:', error);
                    AlertManager.show('An error occurred. Please try again.', 'danger');
                } finally {
                    // Re-enable submit button
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            });
        });
    }

    // ========================================================================
    // 🔍 Real-time Field Validation
    // ========================================================================

    function initFieldValidation() {
        // Email validation
        $$('input[type="email"]').forEach(input => {
            input.addEventListener('blur', function() {
                if (this.value) {
                    if (FormValidator.isValidEmail(this.value)) {
                        FormValidator.setValid(this);
                    } else {
                        FormValidator.setInvalid(this, 'Please enter a valid email address');
                    }
                }
            });
        });

        // Password validation
        $$('input[type="password"][data-validate-password]').forEach(input => {
            input.addEventListener('blur', function() {
                if (this.value) {
                    const result = FormValidator.validatePassword(this.value);
                    if (result.valid) {
                        FormValidator.setValid(this);
                    } else {
                        FormValidator.setInvalid(this, result.errors[0]);
                    }
                }
            });
        });

        // Confirm password validation
        $$('input[data-match]').forEach(input => {
            input.addEventListener('input', debounce(function() {
                const matchField = $(this.dataset.match);
                if (matchField && this.value) {
                    if (FormValidator.matches(this.value, matchField.value)) {
                        FormValidator.setValid(this, 'Passwords match');
                    } else {
                        FormValidator.setInvalid(this, 'Passwords do not match');
                    }
                }
            }, 500));
        });

        // Required field validation
        $$('input[required], textarea[required], select[required]').forEach(input => {
            input.addEventListener('blur', function() {
                if (!FormValidator.isRequired(this.value)) {
                    FormValidator.setInvalid(this, 'This field is required');
                } else if (!this.classList.contains('is-invalid')) {
                    // Only set valid if no other validation has marked it invalid
                    FormValidator.setValid(this);
                }
            });
        });
    }

    // ========================================================================
    // 📱 Mobile Menu Toggle
    // ========================================================================

    function initMobileMenu() {
        const menuToggle = $('#mobile-menu-toggle');
        const sidebar = $('.dashboard-sidebar');

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-open');
            });

            // Close menu when clicking outside
            document.addEventListener('click', function(e) {
                if (sidebar.classList.contains('mobile-open') &&
                    !sidebar.contains(e.target) &&
                    e.target !== menuToggle) {
                    sidebar.classList.remove('mobile-open');
                }
            });
        }
    }

    // ========================================================================
    // 🚀 Initialize on DOM Ready
    // ========================================================================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        initPasswordToggles();
        initPasswordStrengthMeter();
        initAjaxForms();
        initFieldValidation();
        initMobileMenu();
    }

    // ========================================================================
    // 🌐 Expose Public API
    // ========================================================================

    window.SIGNula = {
        FormValidator,
        AlertManager,
        debounce,
        $,
        $$
    };

})();
