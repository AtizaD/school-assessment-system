/**
 * PWA Install Prompt
 * Shows a custom install button for the Progressive Web App
 */

let deferredPrompt = null;
let installButton = null;

// Listen for the beforeinstallprompt event
window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent the default browser install prompt
    e.preventDefault();

    // Store the event for later use
    deferredPrompt = e;

    // Don't show custom install button - browser will show icon in address bar
    // showInstallButton();

    console.log('[PWA] Install prompt ready - use browser install icon');
});

// Show install button
function showInstallButton() {
    // Create install button if it doesn't exist
    if (!installButton) {
        installButton = document.createElement('div');
        installButton.id = 'pwaInstallPrompt';
        installButton.className = 'pwa-install-prompt';
        installButton.innerHTML = `
            <div class="pwa-install-content">
                <div class="pwa-install-icon">
                    <i class="fas fa-download"></i>
                </div>
                <div class="pwa-install-text">
                    <strong>Install App</strong>
                    <p>Install this app for offline access and better performance</p>
                </div>
                <button class="btn btn-sm btn-install" id="pwaInstallBtn">
                    <i class="fas fa-plus me-1"></i> Install
                </button>
                <button class="btn btn-sm btn-close-prompt" id="pwaCloseBtn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        document.body.appendChild(installButton);

        // Add event listeners
        document.getElementById('pwaInstallBtn').addEventListener('click', installPWA);
        document.getElementById('pwaCloseBtn').addEventListener('click', hideInstallButton);
    }

    // Show the button
    setTimeout(() => {
        installButton.classList.add('show');
    }, 1000);
}

// Hide install button
function hideInstallButton() {
    if (installButton) {
        installButton.classList.remove('show');

        // Store dismissal in localStorage
        localStorage.setItem('pwa_install_dismissed', Date.now());
    }
}

// Install PWA
async function installPWA() {
    if (!deferredPrompt) {
        console.log('[PWA] No install prompt available');
        return;
    }

    // Show the browser's install prompt
    deferredPrompt.prompt();

    // Wait for the user's response
    const { outcome } = await deferredPrompt.userChoice;

    console.log('[PWA] User response:', outcome);

    if (outcome === 'accepted') {
        console.log('[PWA] App installed successfully');
        hideInstallButton();
    } else {
        console.log('[PWA] App installation declined');
    }

    // Clear the deferredPrompt
    deferredPrompt = null;
}

// Listen for app installed event
window.addEventListener('appinstalled', (e) => {
    console.log('[PWA] App installed');

    // Hide the install button
    hideInstallButton();

    // Show success message
    showToast('App installed successfully!', 'success');

    // Clear dismissal flag
    localStorage.removeItem('pwa_install_dismissed');
});

// Check if already installed or recently dismissed
window.addEventListener('DOMContentLoaded', () => {
    // Check if running as installed PWA
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
        console.log('[PWA] Running as installed app');
        return;
    }

    // Check if recently dismissed (don't show for 7 days)
    const dismissedTime = localStorage.getItem('pwa_install_dismissed');
    if (dismissedTime) {
        const daysSinceDismissed = (Date.now() - parseInt(dismissedTime)) / (1000 * 60 * 60 * 24);
        if (daysSinceDismissed < 7) {
            console.log('[PWA] Install prompt dismissed recently');
            return;
        }
    }
});

// Helper function to show toast notifications
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `pwa-toast pwa-toast-${type}`;
    toast.innerHTML = `
        <div class="pwa-toast-content">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
            <span>${message}</span>
        </div>
    `;

    document.body.appendChild(toast);

    setTimeout(() => toast.classList.add('show'), 100);

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add CSS styles
const style = document.createElement('style');
style.textContent = `
    .pwa-install-prompt {
        position: fixed;
        bottom: -200px;
        left: 50%;
        transform: translateX(-50%);
        background: linear-gradient(135deg, #000000 0%, #222222 100%);
        color: #ffd700;
        padding: 15px 20px;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        z-index: 9999;
        transition: bottom 0.3s ease;
        max-width: 500px;
        width: calc(100% - 40px);
    }

    .pwa-install-prompt.show {
        bottom: 20px;
    }

    .pwa-install-content {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .pwa-install-icon {
        font-size: 2rem;
        color: #ffd700;
    }

    .pwa-install-text {
        flex: 1;
    }

    .pwa-install-text strong {
        display: block;
        color: #ffd700;
        margin-bottom: 4px;
    }

    .pwa-install-text p {
        margin: 0;
        font-size: 0.875rem;
        color: #ccc;
    }

    .btn-install {
        background: #ffd700;
        color: #000;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-weight: 600;
        white-space: nowrap;
        transition: all 0.2s;
    }

    .btn-install:hover {
        background: #ffed4e;
        transform: translateY(-2px);
    }

    .btn-close-prompt {
        background: transparent;
        border: 1px solid #666;
        color: #ccc;
        padding: 8px 12px;
        border-radius: 6px;
        transition: all 0.2s;
    }

    .btn-close-prompt:hover {
        background: #333;
        border-color: #ffd700;
        color: #ffd700;
    }

    .pwa-toast {
        position: fixed;
        top: -100px;
        right: 20px;
        background: #000;
        color: #fff;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 10000;
        transition: top 0.3s ease;
        border-left: 4px solid #ffd700;
    }

    .pwa-toast.show {
        top: 20px;
    }

    .pwa-toast-success {
        border-left-color: #28a745;
    }

    .pwa-toast-content {
        display: flex;
        align-items: center;
    }

    @media (max-width: 768px) {
        .pwa-install-content {
            flex-wrap: wrap;
        }

        .pwa-install-text {
            flex-basis: 100%;
            order: 2;
        }

        .pwa-install-icon {
            order: 1;
        }

        .btn-install {
            order: 3;
        }

        .btn-close-prompt {
            order: 4;
        }
    }
`;
document.head.appendChild(style);
