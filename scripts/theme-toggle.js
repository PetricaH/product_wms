/**
 * Theme Toggle System
 * Handles switching between dark and light themes
 */

class ThemeToggle {
    constructor() {
        this.currentTheme = localStorage.getItem('wms-theme') || 'dark';
        this.init();
    }

    init() {
        // Apply saved theme on page load
        this.applyTheme(this.currentTheme);
        
        // Create theme toggle button if it doesn't exist
        this.createToggleButton();
        
        // Listen for system theme changes
        this.watchSystemTheme();
    }

    applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        this.currentTheme = theme;
        localStorage.setItem('wms-theme', theme);
        
        // Update toggle button icon if it exists
        this.updateToggleButton();
        
        // Dispatch custom event for other components to listen to
        window.dispatchEvent(new CustomEvent('themeChange', { 
            detail: { theme } 
        }));
    }

    toggle() {
        const newTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
        this.applyTheme(newTheme);
    }

    createToggleButton() {
        // Check if toggle button already exists
        if (document.getElementById('theme-toggle')) return;

        const toggleButton = document.createElement('button');
        toggleButton.id = 'theme-toggle';
        toggleButton.className = 'theme-toggle';
        toggleButton.type = 'button';

        this.updateToggleButton(toggleButton);

        toggleButton.addEventListener('click', () => {
            this.toggle();
            this.setManualPreference();
        });

        // Add to navbar profile section or header
        const targetContainer = document.querySelector('.sidebar__profile') || 
                               document.querySelector('.header-actions') ||
                               document.querySelector('.page-header');
        
        if (targetContainer) {
            targetContainer.appendChild(toggleButton);
        }
    }

    updateToggleButton(button = null) {
        const toggleButton = button || document.getElementById('theme-toggle');
        if (!toggleButton) return;

        const isDark = this.currentTheme === 'dark';
        
        toggleButton.innerHTML = `
            <span class="material-symbols-outlined" aria-hidden="true">
                ${isDark ? 'light_mode' : 'dark_mode'}
            </span>
        `;

        const label = `Comută la tema ${isDark ? 'luminoasă' : 'întunecată'}`;
        toggleButton.setAttribute('aria-label', label);
        toggleButton.setAttribute('title', label);
    }

    watchSystemTheme() {
        // Listen for system theme changes
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        
        mediaQuery.addEventListener('change', (e) => {
            // Only auto-switch if user hasn't manually set a preference
            if (!localStorage.getItem('wms-theme-manual')) {
                const systemTheme = e.matches ? 'dark' : 'light';
                this.applyTheme(systemTheme);
            }
        });
    }

    setManualPreference() {
        // Mark that user has manually chosen a theme
        localStorage.setItem('wms-theme-manual', 'true');
    }

    resetToSystemPreference() {
        // Remove manual preference and use system theme
        localStorage.removeItem('wms-theme-manual');
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        this.applyTheme(systemTheme);
    }
}

// Auto-initialize theme system when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.themeToggle = new ThemeToggle();
});

// Keyboard shortcut for theme toggle (Ctrl/Cmd + Shift + T)
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'T') {
        e.preventDefault();
        if (window.themeToggle) {
            window.themeToggle.toggle();
            window.themeToggle.setManualPreference();
        }
    }
});

// CSS for theme toggle button (can be added to global.css)
const themeToggleCSS = `
.theme-toggle {
    background: var(--sidebar-toggle-background);
    border: 1px solid var(--sidebar-toggle-border);
    color: var(--sidebar-toggle-color);
    width: 38px;
    height: 38px;
    border-radius: 50%;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    box-shadow: var(--sidebar-toggle-shadow);
}

.theme-toggle:hover,
.theme-toggle:focus-visible {
    background: var(--sidebar-link-hover-bg);
    color: var(--sidebar-link-hover-color);
    transform: translateY(-2px);
}

.theme-toggle .material-symbols-outlined {
    font-size: 1.25rem;
}
`;

// Inject CSS if not already included in global.css
if (!document.querySelector('style[data-theme-toggle]')) {
    const style = document.createElement('style');
    style.setAttribute('data-theme-toggle', 'true');
    style.textContent = themeToggleCSS;
    document.head.appendChild(style);
}