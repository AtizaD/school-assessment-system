<?php
/**
 * Admin Payment Settings
 * Configuration management for payment system
 * 
 * @author School Management System
 * @date July 24, 2025
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/SecurePaymentConfig.php';

// Require admin role
requireRole('admin');

$pageTitle = 'Payment Settings';
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_general':
                $enabled = isset($_POST['payment_enabled']) ? '1' : '0';
                $currency = trim($_POST['payment_currency'] ?? 'GHS');
                $gateway = trim($_POST['payment_gateway'] ?? 'expresspay');
                
                SecurePaymentConfig::set('payment_enabled', $enabled);
                SecurePaymentConfig::set('payment_currency', $currency);
                SecurePaymentConfig::set('payment_gateway', $gateway);
                
                $message = 'General settings updated successfully! Payment system is now ' . ($enabled === '1' ? 'enabled' : 'disabled') . '.';
                $messageType = 'success';
                break;
                
            case 'update_expresspay':
                $merchantId = trim($_POST['expresspay_merchant_id'] ?? '');
                $apiKey = trim($_POST['expresspay_api_key'] ?? '');
                $webhookSecret = trim($_POST['expresspay_webhook_secret'] ?? '');
                $environment = trim($_POST['expresspay_environment'] ?? 'sandbox');
                
                if (empty($merchantId) || empty($apiKey)) {
                    throw new Exception('Merchant ID and API key are required');
                }
                
                SecurePaymentConfig::set('expresspay_merchant_id', $merchantId);
                SecurePaymentConfig::set('expresspay_api_key', $apiKey);
                SecurePaymentConfig::set('expresspay_webhook_secret', $webhookSecret);
                SecurePaymentConfig::set('expresspay_environment', $environment);
                
                $message = 'ExpressPay settings updated successfully!';
                $messageType = 'success';
                break;
                
            case 'update_pricing':
                $passwordReset = floatval($_POST['password_reset_price'] ?? 5.00);
                $assessmentReview = floatval($_POST['assessment_review_price'] ?? 3.00);
                $assessmentRetake = floatval($_POST['assessment_retake_price'] ?? 15.00);
                
                $db = DatabaseConfig::getInstance()->getConnection();
                
                // Update pricing in database
                $stmt = $db->prepare("UPDATE ServicePricing SET amount = ? WHERE service_type = ?");
                $stmt->execute([$passwordReset, 'password_reset']);
                $stmt->execute([$assessmentReview, 'review_assessment']);
                $stmt->execute([$assessmentRetake, 'retake_assessment']);
                
                $message = 'Service pricing updated successfully!';
                $messageType = 'success';
                break;
                
            default:
                throw new Exception('Invalid action');
        }
        
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'danger';
        logError("Payment settings error: " . $e->getMessage());
    }
}

// Get current settings
$currentSettings = [
    'payment_enabled' => SecurePaymentConfig::get('payment_enabled') ?? '1',
    'payment_currency' => SecurePaymentConfig::get('payment_currency') ?? 'GHS',
    'payment_gateway' => SecurePaymentConfig::get('payment_gateway') ?? 'expresspay',
    'expresspay_merchant_id' => SecurePaymentConfig::get('expresspay_merchant_id') ?? '',
    'expresspay_api_key' => SecurePaymentConfig::get('expresspay_api_key') ?? '',
    'expresspay_webhook_secret' => SecurePaymentConfig::get('expresspay_webhook_secret') ?? '',
    'expresspay_environment' => SecurePaymentConfig::get('expresspay_environment') ?? 'sandbox'
];

// Get current pricing
try {
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT service_type, amount FROM ServicePricing WHERE is_active = TRUE");
    $stmt->execute();
    $pricing = [];
    while ($row = $stmt->fetch()) {
        $pricing[$row['service_type']] = $row['amount'];
    }
} catch (Exception $e) {
    logError("Error fetching pricing: " . $e->getMessage());
    $pricing = [
        'password_reset' => 5.00,
        'review_assessment' => 3.00,
        'retake_assessment' => 15.00
    ];
}

require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="dashboard-main">
    <!-- Header Section -->
    <section class="dashboard-header">
        <div class="header-content">
            <div class="welcome-section">
                <h1 class="dashboard-title">
                    <i class="fas fa-cog mr-2"></i>
                    Payment Settings
                </h1>
                <p class="dashboard-subtitle">
                    Configure payment gateway, pricing, and system preferences
                </p>
            </div>
            <div class="header-actions">
                <a href="payment-dashboard.php" class="btn btn-outline-light">
                    <i class="fas fa-arrow-left mr-1"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> mr-2"></i>
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Settings Forms -->
            <div class="row">
                <!-- General Settings -->
                <div class="col-lg-6 mb-4">
                    <div class="settings-card">
                        <div class="settings-header">
                            <div class="settings-icon">
                                <i class="fas fa-sliders-h"></i>
                            </div>
                            <div class="settings-title">
                                <h5>General Settings</h5>
                                <p>Core payment system configuration</p>
                            </div>
                        </div>
                        <div class="settings-body">
                            <form method="POST" class="settings-form">
                                <input type="hidden" name="action" value="update_general">
                                
                                <div class="form-group">
                                    <div class="custom-switch-wrapper">
                                        <input type="checkbox" class="custom-switch-input" id="payment_enabled" 
                                               name="payment_enabled" <?= $currentSettings['payment_enabled'] === '1' ? 'checked' : '' ?>>
                                        <label class="custom-switch-label" for="payment_enabled">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">
                                                <strong>Enable Payment System</strong>
                                                <small>When disabled, all payment features will be unavailable</small>
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="payment_currency" class="form-label">
                                        <i class="fas fa-coins mr-2"></i>Currency
                                    </label>
                                    <select class="form-select" id="payment_currency" name="payment_currency">
                                        <option value="GHS" <?= $currentSettings['payment_currency'] === 'GHS' ? 'selected' : '' ?>>GHS - Ghana Cedis</option>
                                        <option value="USD" <?= $currentSettings['payment_currency'] === 'USD' ? 'selected' : '' ?>>USD - US Dollars</option>
                                        <option value="NGN" <?= $currentSettings['payment_currency'] === 'NGN' ? 'selected' : '' ?>>NGN - Nigerian Naira</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="payment_gateway" class="form-label">
                                        <i class="fas fa-credit-card mr-2"></i>Payment Gateway
                                    </label>
                                    <select class="form-select" id="payment_gateway" name="payment_gateway">
                                        <option value="expresspay" <?= $currentSettings['payment_gateway'] === 'expresspay' ? 'selected' : '' ?>>ExpressPay</option>
                                    </select>
                                    <small class="form-help">Currently only ExpressPay is supported</small>
                                </div>

                                <button type="submit" class="btn btn-primary btn-save">
                                    <i class="fas fa-save mr-1"></i>Save General Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ExpressPay Configuration -->
                <div class="col-lg-6 mb-4">
                    <div class="settings-card">
                        <div class="settings-header">
                            <div class="settings-icon expresspay">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="settings-title">
                                <h5>ExpressPay Configuration</h5>
                                <p>Merchant ID, API key and environment settings</p>
                            </div>
                        </div>
                        <div class="settings-body">
                            <form method="POST" class="settings-form">
                                <input type="hidden" name="action" value="update_expresspay">
                                
                                <div class="form-group">
                                    <label for="expresspay_environment" class="form-label">
                                        <i class="fas fa-globe mr-2"></i>Environment
                                    </label>
                                    <select class="form-select" id="expresspay_environment" name="expresspay_environment">
                                        <option value="sandbox" <?= $currentSettings['expresspay_environment'] === 'sandbox' ? 'selected' : '' ?>>Sandbox (Test)</option>
                                        <option value="production" <?= $currentSettings['expresspay_environment'] === 'production' ? 'selected' : '' ?>>Production (Live)</option>
                                    </select>
                                    <small class="form-help">Use sandbox for testing, production for live payments</small>
                                </div>

                                <div class="form-group">
                                    <label for="expresspay_merchant_id" class="form-label">
                                        <i class="fas fa-id-card mr-2"></i>Merchant ID
                                    </label>
                                    <input type="text" class="form-control" id="expresspay_merchant_id" 
                                           name="expresspay_merchant_id" value="<?= htmlspecialchars($currentSettings['expresspay_merchant_id']) ?>"
                                           placeholder="Your ExpressPay Merchant ID">
                                </div>

                                <div class="form-group">
                                    <label for="expresspay_api_key" class="form-label">
                                        <i class="fas fa-lock mr-2"></i>API Key
                                    </label>
                                    <div class="password-input-wrapper">
                                        <input type="password" class="form-control" id="expresspay_api_key" 
                                               name="expresspay_api_key" value="<?= htmlspecialchars($currentSettings['expresspay_api_key']) ?>"
                                               placeholder="Your ExpressPay API Key">
                                        <button type="button" class="password-toggle" data-target="expresspay_api_key">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="form-help">Keep this key secure and never share it publicly</small>
                                </div>

                                <div class="form-group">
                                    <label for="expresspay_webhook_secret" class="form-label">
                                        <i class="fas fa-webhook mr-2"></i>Webhook Secret
                                    </label>
                                    <div class="password-input-wrapper">
                                        <input type="password" class="form-control" id="expresspay_webhook_secret" 
                                               name="expresspay_webhook_secret" value="<?= htmlspecialchars($currentSettings['expresspay_webhook_secret']) ?>"
                                               placeholder="Optional webhook verification secret">
                                        <button type="button" class="password-toggle" data-target="expresspay_webhook_secret">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="form-help">
                                        Set webhook URL: <code><?= BASE_URL ?>/api/payment-webhook.php</code>
                                    </small>
                                </div>

                                <button type="submit" class="btn btn-info btn-save">
                                    <i class="fas fa-save mr-1"></i>Save ExpressPay Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Service Pricing -->
            <div class="row">
                <div class="col-12 mb-4">
                    <div class="settings-card pricing-card">
                        <div class="settings-header">
                            <div class="settings-icon pricing">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                            <div class="settings-title">
                                <h5>Service Pricing</h5>
                                <p>Configure charges for different services</p>
                            </div>
                        </div>
                        <div class="settings-body">
                            <form method="POST" class="settings-form">
                                <input type="hidden" name="action" value="update_pricing">
                                
                                <div class="pricing-grid">
                                    <div class="pricing-item">
                                        <div class="pricing-icon password">
                                            <i class="fas fa-key"></i>
                                        </div>
                                        <div class="pricing-details">
                                            <label for="password_reset_price" class="pricing-label">Password Reset</label>
                                            <div class="pricing-input-group">
                                                <span class="pricing-currency"><?= $currentSettings['payment_currency'] ?></span>
                                                <input type="number" class="form-control pricing-input" id="password_reset_price" 
                                                       name="password_reset_price" step="0.01" min="0"
                                                       value="<?= number_format($pricing['password_reset'] ?? 5.00, 2, '.', '') ?>">
                                            </div>
                                            <small class="pricing-help">Amount charged for password reset service</small>
                                        </div>
                                    </div>

                                    <div class="pricing-item">
                                        <div class="pricing-icon review">
                                            <i class="fas fa-eye"></i>
                                        </div>
                                        <div class="pricing-details">
                                            <label for="assessment_review_price" class="pricing-label">Assessment Review</label>
                                            <div class="pricing-input-group">
                                                <span class="pricing-currency"><?= $currentSettings['payment_currency'] ?></span>
                                                <input type="number" class="form-control pricing-input" id="assessment_review_price" 
                                                       name="assessment_review_price" step="0.01" min="0"
                                                       value="<?= number_format($pricing['review_assessment'] ?? 3.00, 2, '.', '') ?>">
                                            </div>
                                            <small class="pricing-help">Amount charged for detailed result reviews</small>
                                        </div>
                                    </div>

                                    <div class="pricing-item">
                                        <div class="pricing-icon retake">
                                            <i class="fas fa-redo"></i>
                                        </div>
                                        <div class="pricing-details">
                                            <label for="assessment_retake_price" class="pricing-label">Assessment Retake</label>
                                            <div class="pricing-input-group">
                                                <span class="pricing-currency"><?= $currentSettings['payment_currency'] ?></span>
                                                <input type="number" class="form-control pricing-input" id="assessment_retake_price" 
                                                       name="assessment_retake_price" step="0.01" min="0"
                                                       value="<?= number_format($pricing['retake_assessment'] ?? 15.00, 2, '.', '') ?>">
                                            </div>
                                            <small class="pricing-help">Amount charged for assessment retakes</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="pricing-actions">
                                    <button type="submit" class="btn btn-success btn-save btn-lg">
                                        <i class="fas fa-save mr-1"></i>Update Service Pricing
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Configuration Status -->
            <div class="row">
                <div class="col-12">
                    <div class="settings-card status-card">
                        <div class="settings-header">
                            <div class="settings-icon status">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div class="settings-title">
                                <h5>Configuration Status</h5>
                                <p>Current system configuration overview</p>
                            </div>
                        </div>
                        <div class="settings-body">
                            <div class="status-grid">
                                <div class="status-item">
                                    <div class="status-icon-wrapper">
                                        <i class="fas fa-toggle-<?= $currentSettings['payment_enabled'] === '1' ? 'on' : 'off' ?> fa-3x text-<?= $currentSettings['payment_enabled'] === '1' ? 'success' : 'danger' ?>"></i>
                                    </div>
                                    <div class="status-content">
                                        <h6 class="status-title">Payment System</h6>
                                        <span class="status-badge badge-<?= $currentSettings['payment_enabled'] === '1' ? 'success' : 'danger' ?>">
                                            <i class="fas fa-circle fa-xs mr-1"></i>
                                            <?= $currentSettings['payment_enabled'] === '1' ? 'Enabled' : 'Disabled' ?>
                                        </span>
                                        <p class="status-description">
                                            <?= $currentSettings['payment_enabled'] === '1' ? 'Payment processing is active' : 'Payment processing is disabled' ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="status-item">
                                    <div class="status-icon-wrapper">
                                        <i class="fas fa-key fa-3x <?= !empty($currentSettings['expresspay_merchant_id']) && !empty($currentSettings['expresspay_api_key']) ? 'text-success' : 'text-warning' ?>"></i>
                                    </div>
                                    <div class="status-content">
                                        <h6 class="status-title">API Keys</h6>
                                        <span class="status-badge badge-<?= !empty($currentSettings['expresspay_merchant_id']) && !empty($currentSettings['expresspay_api_key']) ? 'success' : 'warning' ?>">
                                            <i class="fas fa-circle fa-xs mr-1"></i>
                                            <?= !empty($currentSettings['expresspay_merchant_id']) && !empty($currentSettings['expresspay_api_key']) ? 'Configured' : 'Missing' ?>
                                        </span>
                                        <p class="status-description">
                                            <?= !empty($currentSettings['expresspay_merchant_id']) && !empty($currentSettings['expresspay_api_key']) ? 'API credentials are properly set' : 'API credentials need configuration' ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="status-item">
                                    <div class="status-icon-wrapper">
                                        <i class="fas fa-webhook fa-3x <?= !empty($currentSettings['expresspay_webhook_secret']) ? 'text-success' : 'text-info' ?>"></i>
                                    </div>
                                    <div class="status-content">
                                        <h6 class="status-title">Webhook</h6>
                                        <span class="status-badge badge-<?= !empty($currentSettings['expresspay_webhook_secret']) ? 'success' : 'info' ?>">
                                            <i class="fas fa-circle fa-xs mr-1"></i>
                                            <?= !empty($currentSettings['expresspay_webhook_secret']) ? 'Secured' : 'Optional' ?>
                                        </span>
                                        <p class="status-description">
                                            <?= !empty($currentSettings['expresspay_webhook_secret']) ? 'Webhook verification is active' : 'Webhook security is optional' ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="status-item">
                                    <div class="status-icon-wrapper">
                                        <i class="fas fa-vial fa-3x text-<?= $currentSettings['expresspay_environment'] === 'sandbox' ? 'warning' : 'success' ?>"></i>
                                    </div>
                                    <div class="status-content">
                                        <h6 class="status-title">Environment</h6>
                                        <span class="status-badge badge-<?= $currentSettings['expresspay_environment'] === 'sandbox' ? 'warning' : 'success' ?>">
                                            <i class="fas fa-circle fa-xs mr-1"></i>
                                            <?= $currentSettings['expresspay_environment'] === 'sandbox' ? 'Sandbox' : 'Production' ?>
                                        </span>
                                        <p class="status-description">
                                            <?= $currentSettings['expresspay_environment'] === 'sandbox' ? 'Using sandbox environment' : 'Using production environment' ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Status Alerts -->
                            <div class="status-alerts">
                                <?php if ($currentSettings['expresspay_environment'] === 'sandbox'): ?>
                                    <div class="alert alert-warning">
                                        <div class="alert-icon">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </div>
                                        <div class="alert-content">
                                            <strong>Sandbox Mode Active:</strong> Use ExpressPay test credentials for testing payments.
                                            <br><small>All transactions will be simulated</small>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($currentSettings['payment_enabled'] === '1' && (empty($currentSettings['expresspay_merchant_id']) || empty($currentSettings['expresspay_api_key']))): ?>
                                    <div class="alert alert-danger">
                                        <div class="alert-icon">
                                            <i class="fas fa-times-circle"></i>
                                        </div>
                                        <div class="alert-content">
                                            <strong>Configuration Required:</strong> Payment system is enabled but API credentials are missing. Please configure ExpressPay credentials above.
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($currentSettings['payment_enabled'] === '1' && !empty($currentSettings['expresspay_merchant_id']) && !empty($currentSettings['expresspay_api_key']) && $currentSettings['expresspay_environment'] === 'production'): ?>
                                    <div class="alert alert-success">
                                        <div class="alert-icon">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                        <div class="alert-content">
                                            <strong>System Ready:</strong> Payment system is properly configured and ready for live transactions.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<style>
/* CSS Variables - Black & Gold Professional Theme */
:root {
    --black: #000000;
    --gold: #ffd700;
    --gold-dark: #b8a000;
    --gold-light: #fff9cc;
    --white: #ffffff;
    --gray-50: #f8f9fa;
    --gray-100: #e9ecef;
    --gray-200: #dee2e6;
    --gray-400: #6c757d;
    --gray-600: #495057;
    --gray-800: #343a40;
    --success: #28a745;
    --danger: #dc3545;
    --warning: #ffc107;
    --info: #17a2b8;
    --shadow: 0 2px 4px rgba(0,0,0,0.05), 0 8px 16px rgba(0,0,0,0.05);
    --shadow-hover: 0 4px 8px rgba(0,0,0,0.1), 0 12px 24px rgba(0,0,0,0.1);
    --border-radius: 12px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Global Styles */
* { box-sizing: border-box; }

body {
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 100%);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    margin: 0;
    padding: 0;
    color: var(--gray-800);
    line-height: 1.6;
}

.dashboard-main {
    padding: 0;
    min-height: 100vh;
}

/* Header Section */
.dashboard-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.dashboard-header::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 200px;
    background: radial-gradient(circle, var(--gold) 0%, transparent 70%);
    opacity: 0.1;
    border-radius: 50%;
    transform: translate(30%, -30%);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    z-index: 1;
}

.dashboard-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.dashboard-title i {
    color: var(--gold);
    font-size: 2rem;
}

.dashboard-subtitle {
    font-size: 1.1rem;
    margin: 0.5rem 0 0 0;
    opacity: 0.9;
}

/* Content Section */
.content {
    padding: 0 2rem 2rem;
}

/* Settings Cards */
.settings-card {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    overflow: hidden;
    transition: var(--transition);
    margin-bottom: 1.5rem;
}

.settings-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.settings-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    position: relative;
}

.settings-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--gold), transparent, var(--gold));
}

.settings-icon {
    width: 60px;
    height: 60px;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--white);
    background: linear-gradient(135deg, var(--gold), var(--gold-dark));
    flex-shrink: 0;
}

.settings-icon.expresspay {
    background: linear-gradient(135deg, var(--info), #117a8b);
}

.settings-icon.pricing {
    background: linear-gradient(135deg, var(--success), #1e7e34);
}

.settings-icon.status {
    background: linear-gradient(135deg, var(--warning), #e0a800);
}

.settings-title h5 {
    margin: 0 0 0.5rem 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.settings-title p {
    margin: 0;
    font-size: 0.875rem;
    opacity: 0.9;
}

.settings-body {
    padding: 2rem;
}

/* Form Styling */
.settings-form {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.form-label {
    font-weight: 600;
    color: var(--gray-800);
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    margin-bottom: 0.5rem;
}

.form-label i {
    color: var(--gold-dark);
    width: 20px;
}

.form-control, .form-select {
    border: 2px solid var(--gray-200);
    border-radius: var(--border-radius);
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    transition: var(--transition);
    background: var(--white);
}

.form-control:focus, .form-select:focus {
    border-color: var(--gold);
    box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.25);
    outline: none;
}

.form-help {
    font-size: 0.75rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
}

.form-help code {
    background: var(--gray-100);
    padding: 0.125rem 0.25rem;
    border-radius: 4px;
    font-size: 0.75rem;
}

/* Custom Switch */
.custom-switch-wrapper {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1rem;
    background: var(--gray-50);
    border-radius: var(--border-radius);
    border: 2px solid transparent;
    transition: var(--transition);
}

.custom-switch-wrapper:hover {
    background: var(--gold-light);
    border-color: var(--gold);
}

.custom-switch-input {
    display: none;
}

.custom-switch-label {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    cursor: pointer;
    margin: 0;
    width: 100%;
}

.custom-switch-indicator {
    width: 50px;
    height: 24px;
    background: var(--gray-400);
    border-radius: 12px;
    position: relative;
    transition: var(--transition);
    flex-shrink: 0;
    margin-top: 2px;
}

.custom-switch-indicator::before {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 20px;
    height: 20px;
    background: var(--white);
    border-radius: 50%;
    transition: var(--transition);
}

.custom-switch-input:checked + .custom-switch-label .custom-switch-indicator {
    background: var(--gold);
}

.custom-switch-input:checked + .custom-switch-label .custom-switch-indicator::before {
    transform: translateX(26px);
}

.custom-switch-description {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.custom-switch-description strong {
    font-size: 1rem;
    color: var(--gray-800);
}

.custom-switch-description small {
    font-size: 0.75rem;
    color: var(--gray-600);
    line-height: 1.3;
}

/* Password Input */
.password-input-wrapper {
    position: relative;
}

.password-toggle {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--gray-600);
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 4px;
    transition: var(--transition);
}

.password-toggle:hover {
    color: var(--gold-dark);
    background: var(--gold-light);
}

/* Pricing Grid */
.pricing-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 2rem;
    margin-bottom: 2rem;
}

.pricing-item {
    background: var(--gray-50);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    display: flex;
    gap: 1rem;
    transition: var(--transition);
    border: 2px solid transparent;
}

.pricing-item:hover {
    background: var(--gold-light);
    border-color: var(--gold);
    transform: translateY(-2px);
}

.pricing-icon {
    width: 50px;
    height: 50px;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--white);
    flex-shrink: 0;
}

.pricing-icon.password {
    background: linear-gradient(135deg, var(--warning), #e0a800);
}

.pricing-icon.review {
    background: linear-gradient(135deg, var(--info), #117a8b);
}

.pricing-icon.retake {
    background: linear-gradient(135deg, var(--success), #1e7e34);
}

.pricing-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.pricing-label {
    font-weight: 600;
    color: var(--gray-800);
    font-size: 1rem;
    margin: 0;
}

.pricing-input-group {
    display: flex;
    align-items: center;
    background: var(--white);
    border: 2px solid var(--gray-200);
    border-radius: var(--border-radius);
    overflow: hidden;
    transition: var(--transition);
}

.pricing-input-group:focus-within {
    border-color: var(--gold);
    box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.25);
}

.pricing-currency {
    background: var(--gray-100);
    padding: 0.75rem 1rem;
    font-weight: 600;
    color: var(--gray-800);
    border-right: 1px solid var(--gray-200);
}

.pricing-input {
    border: none;
    padding: 0.75rem 1rem;
    font-size: 1rem;
    font-weight: 600;
    flex: 1;
}

.pricing-input:focus {
    outline: none;
}

.pricing-help {
    font-size: 0.75rem;
    color: var(--gray-600);
    line-height: 1.3;
}

.pricing-actions {
    text-align: center;
    padding-top: 1rem;
    border-top: 1px solid var(--gray-200);
}

/* Status Grid */
.status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 2rem;
    margin-bottom: 2rem;
}

.status-item {
    background: var(--gray-50);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    text-align: center;
    transition: var(--transition);
    border: 2px solid transparent;
}

.status-item:hover {
    background: var(--gold-light);
    border-color: var(--gold);
    transform: translateY(-2px);
}

.status-icon-wrapper {
    margin-bottom: 1rem;
}

.status-content {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.status-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-800);
    margin: 0;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.badge-success {
    background: var(--success);
    color: var(--white);
}

.badge-warning {
    background: var(--warning);
    color: var(--black);
}

.badge-danger {
    background: var(--danger);
    color: var(--white);
}

.badge-info {
    background: var(--info);
    color: var(--white);
}

.status-description {
    font-size: 0.875rem;
    color: var(--gray-600);
    margin: 0;
    line-height: 1.3;
}

/* Status Alerts */
.status-alerts {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.alert {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-radius: var(--border-radius);
    border: 1px solid;
    font-size: 0.875rem;
}

.alert-success {
    background: rgba(40, 167, 69, 0.1);
    border-color: var(--success);
    color: #155724;
}

.alert-warning {
    background: rgba(255, 193, 7, 0.1);
    border-color: var(--warning);
    color: #856404;
}

.alert-danger {
    background: rgba(220, 53, 69, 0.1);
    border-color: var(--danger);
    color: #721c24;
}

.alert-icon {
    font-size: 1.25rem;
    flex-shrink: 0;
    margin-top: 0.125rem;
}

.alert-content {
    flex: 1;
}

.alert-content strong {
    font-weight: 600;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    border: 2px solid;
    border-radius: var(--border-radius);
    font-weight: 600;
    font-size: 0.875rem;
    text-decoration: none;
    cursor: pointer;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.btn-primary {
    background: linear-gradient(135deg, var(--black), var(--gold-dark));
    border-color: var(--gold);
    color: var(--white);
}

.btn-primary:hover {
    background: linear-gradient(135deg, var(--gold), var(--black));
    border-color: var(--gold-dark);
    color: var(--white);
    transform: translateY(-2px);
    text-decoration: none;
}

.btn-info {
    background: linear-gradient(135deg, var(--info), #117a8b);
    border-color: var(--info);
    color: var(--white);
}

.btn-info:hover {
    background: linear-gradient(135deg, #117a8b, var(--info));
    transform: translateY(-2px);
    text-decoration: none;
}

.btn-success {
    background: linear-gradient(135deg, var(--success), #1e7e34);
    border-color: var(--success);
    color: var(--white);
}

.btn-success:hover {
    background: linear-gradient(135deg, #1e7e34, var(--success));
    transform: translateY(-2px);
    text-decoration: none;
}

.btn-outline-light {
    background: transparent;
    border-color: var(--white);
    color: var(--white);
}

.btn-outline-light:hover {
    background: var(--white);
    color: var(--black);
    text-decoration: none;
}

.btn-save {
    min-width: 180px;
}

.btn-lg {
    padding: 1rem 2rem;
    font-size: 1rem;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .pricing-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .status-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .dashboard-header {
        padding: 1.5rem;
    }
    
    .header-content {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
    
    .dashboard-title {
        font-size: 2rem;
    }
    
    .content {
        padding: 0 1rem 2rem;
    }
    
    .settings-header {
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
    
    .settings-body {
        padding: 1.5rem;
    }
    
    .pricing-grid {
        grid-template-columns: 1fr;
    }
    
    .status-grid {
        grid-template-columns: 1fr;
    }
    
    .pricing-item {
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
    
    .custom-switch-label {
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
    
    .alert {
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
    }
}

@media (max-width: 480px) {
    .settings-card {
        margin-bottom: 1rem;
    }
    
    .settings-header {
        padding: 1rem;
    }
    
    .settings-body {
        padding: 1rem;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .pricing-input-group {
        flex-direction: column;
    }
    
    .pricing-currency {
        border-right: none;
        border-bottom: 1px solid var(--gray-200);
        text-align: center;
    }
}

/* Animations */
@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.settings-card {
    animation: slideInUp 0.6s ease-out;
}

.settings-card:nth-child(1) { animation-delay: 0.1s; }
.settings-card:nth-child(2) { animation-delay: 0.2s; }
.settings-card:nth-child(3) { animation-delay: 0.3s; }

/* Focus States for Accessibility */
.btn:focus,
.form-control:focus,
.form-select:focus,
.custom-switch-label:focus {
    outline: 2px solid var(--gold);
    outline-offset: 2px;
}

/* Print Styles */
@media print {
    .dashboard-header,
    .btn {
        display: none;
    }
    
    .settings-card {
        break-inside: avoid;
        box-shadow: none;
        border: 1px solid var(--gray-400);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    // Initialize settings page
    initializeSettingsPage();
    
    function initializeSettingsPage() {
        initializePasswordToggles();
        initializeFormValidation();
        initializeSwitchHandlers();
        initializeFormSubmission();
        initializeTooltips();
    }
    
    // Password toggle functionality
    function initializePasswordToggles() {
        const passwordToggles = document.querySelectorAll('.password-toggle');
        
        passwordToggles.forEach(toggle => {
            toggle.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const icon = this.querySelector('i');
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'fas fa-eye-slash';
                    this.setAttribute('aria-label', 'Hide password');
                } else {
                    input.type = 'password';
                    icon.className = 'fas fa-eye';
                    this.setAttribute('aria-label', 'Show password');
                }
            });
        });
    }
    
    // Form validation
    function initializeFormValidation() {
        const forms = document.querySelectorAll('.settings-form');
        
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = form.querySelector('input[name="action"]')?.value;
                
                if (action === 'update_expresspay') {
                    const merchantId = form.querySelector('#expresspay_merchant_id')?.value.trim();
                    const apiKey = form.querySelector('#expresspay_api_key')?.value.trim();
                    
                    if (!merchantId || !apiKey) {
                        e.preventDefault();
                        showNotification('Both Merchant ID and API Key are required', 'error');
                        return;
                    }
                    
                    // Basic validation for ExpressPay credentials
                    if (merchantId.length < 3) {
                        e.preventDefault();
                        showNotification('Merchant ID appears to be invalid', 'error');
                        return;
                    }
                    
                    if (apiKey.length < 10) {
                        e.preventDefault();
                        showNotification('API Key appears to be invalid', 'error');
                        return;
                    }
                }
                
                if (action === 'update_pricing') {
                    const priceInputs = form.querySelectorAll('input[type="number"]');
                    let hasInvalidPrice = false;
                    
                    priceInputs.forEach(input => {
                        const value = parseFloat(input.value);
                        if (isNaN(value) || value < 0) {
                            hasInvalidPrice = true;
                            input.style.borderColor = 'var(--danger)';
                        } else {
                            input.style.borderColor = '';
                        }
                    });
                    
                    if (hasInvalidPrice) {
                        e.preventDefault();
                        showNotification('Please enter valid prices (must be 0 or greater)', 'error');
                        return;
                    }
                }
                
                // Add loading state to submit button
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalContent = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Saving...';
                    submitBtn.disabled = true;
                    
                    // Restore button after 3 seconds as fallback
                    setTimeout(() => {
                        submitBtn.innerHTML = originalContent;
                        submitBtn.disabled = false;
                    }, 3000);
                }
            });
        });
    }
    
    // Switch handlers with confirmations
    function initializeSwitchHandlers() {
        const paymentEnabledSwitch = document.getElementById('payment_enabled');
        const environmentSelect = document.getElementById('expresspay_environment');
        
        if (paymentEnabledSwitch) {
            paymentEnabledSwitch.addEventListener('change', function() {
                if (!this.checked) {
                    showNotification('Payment system will be disabled when you save the settings. This will make all paid services unavailable.', 'warning');
                } else {
                    showNotification('Payment system will be enabled when you save the settings.', 'info');
                }
            });
        }
        
        if (environmentSelect) {
            environmentSelect.addEventListener('change', function() {
                if (this.value === 'production') {
                    showNotification('Production mode will be enabled when you save. Make sure to use live API credentials!', 'warning');
                } else {
                    showNotification('Sandbox mode will be enabled when you save. You can safely test payments.', 'info');
                }
            });
        }
    }
    
    // Form submission with better UX
    function initializeFormSubmission() {
        // Auto-save functionality for certain changes
        const autoSaveFields = ['payment_currency', 'payment_gateway'];
        
        autoSaveFields.forEach(fieldName => {
            const field = document.querySelector(`[name="${fieldName}"]`);
            if (field) {
                field.addEventListener('change', function() {
                    showNotification(`${fieldName.replace('_', ' ')} changed. Don't forget to save!`, 'info');
                });
            }
        });
        
        // Pricing input formatting
        const priceInputs = document.querySelectorAll('.pricing-input');
        priceInputs.forEach(input => {
            input.addEventListener('blur', function() {
                const value = parseFloat(this.value);
                if (!isNaN(value)) {
                    this.value = value.toFixed(2);
                }
            });
            
            input.addEventListener('input', function() {
                // Remove any non-numeric characters except decimal point
                this.value = this.value.replace(/[^0-9.]/g, '');
                
                // Ensure only one decimal point
                const parts = this.value.split('.');
                if (parts.length > 2) {
                    this.value = parts[0] + '.' + parts.slice(1).join('');
                }
            });
        });
    }
    
    // Initialize tooltips and help text
    function initializeTooltips() {
        // Add interactive help for webhook URL
        const webhookHelp = document.querySelector('.form-help code');
        if (webhookHelp) {
            webhookHelp.style.cursor = 'pointer';
            webhookHelp.title = 'Click to copy webhook URL';
            
            webhookHelp.addEventListener('click', function() {
                navigator.clipboard.writeText(this.textContent).then(() => {
                    showNotification('Webhook URL copied to clipboard!', 'success');
                }).catch(() => {
                    showNotification('Failed to copy URL', 'error');
                });
            });
        }
        
        // Add visual feedback for form interactions
        const formControls = document.querySelectorAll('.form-control, .form-select');
        formControls.forEach(control => {
            control.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            control.addEventListener('blur', function() {
                this.parentElement.classList.remove('focused');
            });
        });
    }
    
    // Notification system
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.notification-toast');
        existingNotifications.forEach(notification => notification.remove());
        
        const notification = document.createElement('div');
        notification.className = `notification-toast alert alert-${type === 'error' ? 'danger' : type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1060;
            min-width: 300px;
            max-width: 500px;
            animation: slideInRight 0.3s ease-out;
        `;
        
        const iconMap = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        
        notification.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas ${iconMap[type] || iconMap.info} mr-2"></i>
                <span>${message}</span>
                <button type="button" class="btn-close ml-auto" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOutRight 0.3s ease-in';
                setTimeout(() => notification.remove(), 300);
            }
        }, 5000);
    }
    
    // Auto-dismiss existing alerts
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            }
        }, 5000);
    });
    
    console.log('Payment settings page initialized successfully');
});

// Add notification animations
const notificationCSS = `
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(100%);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    @keyframes slideOutRight {
        from {
            opacity: 1;
            transform: translateX(0);
        }
        to {
            opacity: 0;
            transform: translateX(100%);
        }
    }
    
    .notification-toast {
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-hover);
        border: none;
    }
    
    .notification-toast .btn-close {
        background: none;
        border: none;
        color: inherit;
        opacity: 0.7;
        cursor: pointer;
        padding: 0.25rem;
        border-radius: 4px;
        transition: var(--transition);
    }
    
    .notification-toast .btn-close:hover {
        opacity: 1;
        background: rgba(0,0,0,0.1);
    }
    
    .focused {
        transform: scale(1.02);
        transition: var(--transition);
    }
`;

// Inject notification CSS
const style = document.createElement('style');
style.textContent = notificationCSS;
document.head.appendChild(style);
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>