// main.js

class UIManager {
    constructor() {
        // Core UI elements
        this.wrapper = document.getElementById('wrapper');
        this.menuToggle = document.getElementById('menu-toggle');
        this.sidebar = document.getElementById('sidebar-wrapper');
        this.navbarToggler = document.querySelector('.navbar-toggler');
        this.navbarCollapse = document.querySelector('.navbar-collapse');
        this.isMobile = window.innerWidth < 768;

        // Initialize all UI components
        this.initializeNavigationControls();
        this.initializeDropdowns();
        this.initializeFormValidation();
        this.initializeAlerts();
        this.initializeTableSorting();
        this.setupOutsideClickHandler();
    }

    // Navigation Controls
    initializeNavigationControls() {
        this.initializeSidebarToggle();
        this.initializeNavbarToggle();
        this.handleResponsiveNavigation();
    }

    initializeSidebarToggle() {
        if (this.menuToggle) {
            this.menuToggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.toggleSidebar();
            });
        }

        // Handle clicks inside sidebar
        if (this.sidebar) {
            this.sidebar.addEventListener('click', (e) => {
                if (this.isMobile) {
                    e.stopPropagation();
                }
            });
        }

        // Set initial state
        this.updateMenuState();
    }

    initializeNavbarToggle() {
        if (this.navbarToggler && this.navbarCollapse) {
            this.navbarToggler.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.toggleNavbar();
            });
        }
    }

    handleResponsiveNavigation() {
        let resizeTimer;
        window.addEventListener('resize', () => {
            // Debounce resize events
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                const wasMobile = this.isMobile;
                this.isMobile = window.innerWidth < 768;

                // Only update if switching between mobile and desktop
                if (wasMobile !== this.isMobile) {
                    this.updateMenuState();
                    this.resetNavbar();
                }
            }, 250);
        });
    }

    updateMenuState() {
        this.wrapper.classList.remove('toggled');
    }

    toggleSidebar() {
        if (this.wrapper) {
            this.wrapper.classList.toggle('toggled');
            
            // Close navbar collapse in mobile when toggling sidebar
            if (this.isMobile) {
                this.closeNavbar();
            }
        }
    }

    toggleNavbar() {
        if (this.navbarCollapse) {
            const isExpanded = this.navbarCollapse.classList.contains('show');
            isExpanded ? this.closeNavbar() : this.openNavbar();
            this.navbarToggler?.setAttribute('aria-expanded', (!isExpanded).toString());
        }
    }

    openNavbar() {
        if (this.navbarCollapse) {
            this.navbarCollapse.classList.add('show');
            this.navbarCollapse.setAttribute('aria-expanded', 'true');
        }
    }

    closeNavbar() {
        if (this.navbarCollapse) {
            this.navbarCollapse.classList.remove('show');
            this.navbarCollapse.setAttribute('aria-expanded', 'false');
        }
    }

    resetNavbar() {
        this.closeNavbar();
        if (this.navbarToggler) {
            this.navbarToggler.setAttribute('aria-expanded', 'false');
        }
    }

    // Dropdown Management
    initializeDropdowns() {
        const dropdowns = document.querySelectorAll('.dropdown-toggle');
        dropdowns.forEach(dropdown => {
            dropdown.addEventListener('click', (e) => {
                e.preventDefault();
                const dropdownMenu = dropdown.nextElementSibling;
                if (dropdownMenu) {
                    dropdownMenu.classList.toggle('show');
                    dropdown.setAttribute('aria-expanded', 
                        dropdownMenu.classList.contains('show').toString());
                }
            });
        });
    }

    // Form Validation
    initializeFormValidation() {
        const forms = document.querySelectorAll('form.needs-validation');
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                form.classList.add('was-validated');
            });
        });
    }

    // Alert Management
    initializeAlerts() {
        const alerts = document.querySelectorAll('.alert:not(.persistent)');
        alerts.forEach(alert => {
            setTimeout(() => {
                if (alert && alert.parentElement) {
                    alert.classList.add('fade');
                    setTimeout(() => alert.remove(), 150);
                }
            }, 5000);
        });
    }

    // Table Sorting
    initializeTableSorting() {
        const tables = document.querySelectorAll('table.sortable');
        tables.forEach(table => {
            const headers = table.querySelectorAll('th[data-sort]');
            headers.forEach(header => {
                header.addEventListener('click', () => {
                    this.sortTable(table, header);
                });
            });
        });
    }

    // Outside Click Handler
    setupOutsideClickHandler() {
        document.addEventListener('click', (e) => {
            // Handle sidebar clicks in mobile view
            if (this.isMobile && 
                this.wrapper?.classList.contains('toggled') && 
                !this.sidebar?.contains(e.target) && 
                !this.menuToggle?.contains(e.target)) {
                this.wrapper.classList.remove('toggled');
            }

            // Handle navbar collapse clicks
            if (this.navbarCollapse?.classList.contains('show') && 
                !this.navbarCollapse.contains(e.target) && 
                !this.navbarToggler?.contains(e.target)) {
                this.closeNavbar();
            }

            // Handle dropdown clicks
            const dropdowns = document.querySelectorAll('.dropdown-menu.show');
            dropdowns.forEach(dropdown => {
                if (!dropdown.contains(e.target) && 
                    !e.target.matches('.dropdown-toggle')) {
                    dropdown.classList.remove('show');
                    dropdown.previousElementSibling?.setAttribute('aria-expanded', 'false');
                }
            });
        });
    }

    // Utility Functions
    sortTable(table, header) {
        const sortDirection = header.getAttribute('data-sort-direction') === 'asc' ? 'desc' : 'asc';
        const columnIndex = Array.from(header.parentElement.children).indexOf(header);
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        // Sort rows
        rows.sort((a, b) => {
            const aValue = a.children[columnIndex].textContent.trim();
            const bValue = b.children[columnIndex].textContent.trim();
            return sortDirection === 'asc' ? 
                aValue.localeCompare(bValue) : 
                bValue.localeCompare(aValue);
        });

        // Update table
        rows.forEach(row => tbody.appendChild(row));
        header.setAttribute('data-sort-direction', sortDirection);
    }

    showAlert(message, type = 'info', persistent = false) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        if (!persistent) alertDiv.classList.add('auto-dismiss');
        
        alertDiv.innerHTML = `
            <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : 
                            type === 'success' ? 'check-circle' : 
                            type === 'warning' ? 'exclamation-triangle' : 
                            'info-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        const container = document.querySelector('.container-fluid');
        container?.insertBefore(alertDiv, container.firstChild);

        if (!persistent) {
            setTimeout(() => {
                alertDiv.classList.add('fade');
                setTimeout(() => alertDiv.remove(), 150);
            }, 5000);
        }
    }
}

// Initialize UI when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.uiManager = new UIManager();
});

// Handle dynamic content updates
document.addEventListener('contentUpdated', () => {
    window.uiManager.initializeDropdowns();
    window.uiManager.initializeFormValidation();
    window.uiManager.initializeAlerts();
    window.uiManager.initializeTableSorting();
});