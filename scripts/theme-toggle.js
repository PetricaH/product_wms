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
        toggleButton.setAttribute('aria-label', 'Toggle theme');
        toggleButton.title = 'Switch between dark and light theme';
        
        this.updateToggleButton(toggleButton);
        
        toggleButton.addEventListener('click', () => {
            this.toggle();
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
            <span class="material-symbols-outlined">
                ${isDark ? 'light_mode' : 'dark_mode'}
            </span>
            <span class="theme-text">
                ${isDark ? 'Light' : 'Dark'}
            </span>
        `;
        
        toggleButton.setAttribute('aria-label', 
            `Switch to ${isDark ? 'light' : 'dark'} theme`
        );
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
    background: var(--button-background);
    border: 1px solid var(--border-color-strong);
    color: var(--text-primary);
    padding: 0.5rem 0.75rem;
    border-radius: var(--border-radius);
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    font-weight: 500;
}

.theme-toggle:hover {
    background-color: var(--button-hover);
    transform: translateY(-1px);
}

.theme-toggle .material-symbols-outlined {
    font-size: 1.1rem;
}

.theme-text {
    display: none;
}

@media (min-width: 768px) {
    .theme-text {
        display: inline;
    }
}
`;

// Inject CSS if not already included in global.css
if (!document.querySelector('style[data-theme-toggle]')) {
    const style = document.createElement('style');
    style.setAttribute('data-theme-toggle', 'true');
    style.textContent = themeToggleCSS;
    document.head.appendChild(style);
}