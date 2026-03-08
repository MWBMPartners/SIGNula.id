/**
 * 🌙 SIGNula Theme Toggle
 *
 * Manages dark/light mode switching with three states:
 * - 'light': Force light mode
 * - 'dark': Force dark mode
 * - 'auto': Follow system preference
 *
 * Persists preference in localStorage.
 * Applies theme before render to prevent flash of wrong theme (FOWT).
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-color-scheme
 * @see https://web.dev/prefers-color-scheme/
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd. All rights reserved.
 */

(function() {
    'use strict';

    var STORAGE_KEY = 'signula_theme';

    // Get stored preference or default to 'auto'
    function getStoredTheme() {
        try { return localStorage.getItem(STORAGE_KEY) || 'auto'; }
        catch (e) { return 'auto'; }
    }

    // Get effective theme (resolve 'auto' to actual theme)
    function getEffectiveTheme(preference) {
        if (preference === 'auto') {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return preference;
    }

    // Apply theme to document
    function applyTheme(theme) {
        var effective = getEffectiveTheme(theme);
        document.documentElement.setAttribute('data-theme', effective);

        // Update meta theme-color for PWA
        var metaTheme = document.querySelector('meta[name="theme-color"]');
        if (metaTheme) {
            metaTheme.content = effective === 'dark' ? '#1a1d23' : '#007bff';
        }

        // Update toggle button icon
        updateToggleIcons(theme, effective);

        // Dispatch event for other components
        document.dispatchEvent(new CustomEvent('themechange', {
            detail: { preference: theme, effective: effective }
        }));
    }

    // Update all toggle button icons
    function updateToggleIcons(preference, effective) {
        var toggles = document.querySelectorAll('.theme-toggle');
        toggles.forEach(function(toggle) {
            var icon = toggle.querySelector('i');
            if (!icon) return;

            if (preference === 'auto') {
                icon.className = 'fas fa-circle-half-stroke';
                toggle.title = 'Theme: Auto (System)';
            } else if (effective === 'dark') {
                icon.className = 'fas fa-moon';
                toggle.title = 'Theme: Dark';
            } else {
                icon.className = 'fas fa-sun';
                toggle.title = 'Theme: Light';
            }
        });
    }

    // Cycle through themes: light → dark → auto → light
    function cycleTheme() {
        var current = getStoredTheme();
        var next;

        if (current === 'light') { next = 'dark'; }
        else if (current === 'dark') { next = 'auto'; }
        else { next = 'light'; }

        // Add transition class briefly
        document.documentElement.setAttribute('data-theme-transitioning', '');

        try { localStorage.setItem(STORAGE_KEY, next); }
        catch (e) { /* localStorage unavailable */ }

        applyTheme(next);

        // Remove transition class after animation
        setTimeout(function() {
            document.documentElement.removeAttribute('data-theme-transitioning');
        }, 350);
    }

    // Apply theme immediately (before DOM renders to prevent flash)
    applyTheme(getStoredTheme());

    // Listen for system preference changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
        if (getStoredTheme() === 'auto') {
            applyTheme('auto');
        }
    });

    // Setup toggle buttons once DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Update icons for initial state
        var stored = getStoredTheme();
        updateToggleIcons(stored, getEffectiveTheme(stored));

        // Bind click handlers to all toggle buttons
        document.querySelectorAll('.theme-toggle').forEach(function(toggle) {
            toggle.addEventListener('click', cycleTheme);
        });
    });

    // Expose for programmatic use
    window.SIGNulaTheme = {
        toggle: cycleTheme,
        set: function(theme) {
            if (['light', 'dark', 'auto'].indexOf(theme) === -1) return;
            try { localStorage.setItem(STORAGE_KEY, theme); }
            catch (e) {}
            applyTheme(theme);
        },
        get: getStoredTheme,
        getEffective: function() { return getEffectiveTheme(getStoredTheme()); }
    };
})();
