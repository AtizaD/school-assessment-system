// menu-toggle.js
class MenuToggle {
    constructor() {
        this.wrapper = document.getElementById('wrapper');
        this.menuToggle = document.getElementById('menu-toggle');
        this.sidebar = document.getElementById('sidebar-wrapper');
        this.pageContent = document.getElementById('page-content-wrapper');
        this.isMobile = window.innerWidth < 768;
        
        this.initialize();
    }

    initialize() {
        // Initialize menu state based on screen size
        this.updateMenuState();
        
        // Add click event listener to menu toggle button
        if (this.menuToggle) {
            this.menuToggle.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleMenu();
            });
        }

        // Handle window resize
        window.addEventListener('resize', () => {
            const wasMobile = this.isMobile;
            this.isMobile = window.innerWidth < 768;
            
            // Reset layout if switching between mobile and desktop
            if (wasMobile !== this.isMobile) {
                this.updateMenuState();
            }
        });

        // Handle clicks outside sidebar in mobile view
        document.addEventListener('click', (e) => {
            if (this.isMobile && this.wrapper.classList.contains('toggled')) {
                if (!this.sidebar.contains(e.target) && 
                    !this.menuToggle.contains(e.target)) {
                    this.wrapper.classList.remove('toggled');
                }
            }
        });

        // Prevent sidebar clicks from closing menu
        this.sidebar.addEventListener('click', (e) => {
            if (this.isMobile) {
                e.stopPropagation();
            }
        });
    }

    toggleMenu() {
        if (this.wrapper) {
            this.wrapper.classList.toggle('toggled');
        }
    }

    updateMenuState() {
        if (this.wrapper) {
            // On mobile: sidebar starts hidden
            // On desktop: sidebar starts visible
            if (this.isMobile) {
                this.wrapper.classList.remove('toggled');
            } else {
                this.wrapper.classList.add('toggled');
            }
        }
    }
}

// Initialize menu toggle functionality when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.menuToggle = new MenuToggle();
});
