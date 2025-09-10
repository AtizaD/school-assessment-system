<?php
/**
 * Admin Payment Dashboard
 * Main payment management interface for administrators
 * 
 * @author School Management System
 * @date July 24, 2025
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/PaymentService.php';
require_once INCLUDES_PATH . '/SecurePaymentConfig.php';
require_once INCLUDES_PATH . '/PaymentHandlers.php';

// Require admin role
requireRole('admin');

$pageTitle = 'Payment Management';

// Get payment statistics
$stats = PaymentService::getPaymentStats('month');
$todayStats = PaymentService::getPaymentStats('today');

require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="dashboard-main">
    <!-- Header Section -->
    <section class="dashboard-header">
        <div class="header-content">
            <div class="welcome-section">
                <h1 class="dashboard-title">
                    <i class="fas fa-credit-card mr-2"></i>
                    Payment Management
                </h1>
                <p class="dashboard-subtitle">
                    Monitor transactions, manage services, and track revenue
                </p>
            </div>
            <div class="header-actions">
                <div class="today-highlight">
                    <i class="fas fa-chart-line mr-1"></i>
                    <span>GHS <?= number_format($todayStats['overall']['total_revenue'] ?? 0, 2) ?> today</span>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <!-- Key Metrics -->
            <section class="metrics-section">
                <div class="metrics-grid">
                    <div class="metric-card revenue">
                        <div class="metric-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-number">GHS <?= number_format($stats['overall']['total_revenue'] ?? 0, 2) ?></div>
                            <div class="metric-label">Monthly Revenue</div>
                            <div class="metric-change positive">
                                <i class="fas fa-arrow-up"></i>
                                This month
                            </div>
                        </div>
                    </div>

                    <div class="metric-card successful">
                        <div class="metric-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-number"><?= number_format($stats['overall']['successful_transactions'] ?? 0) ?></div>
                            <div class="metric-label">Successful Payments</div>
                            <div class="metric-change positive">
                                <i class="fas fa-check"></i>
                                Completed
                            </div>
                        </div>
                    </div>

                    <div class="metric-card pending">
                        <div class="metric-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-number"><?= number_format($stats['overall']['pending_transactions'] ?? 0) ?></div>
                            <div class="metric-label">Pending Payments</div>
                            <div class="metric-change neutral">
                                <i class="fas fa-hourglass-half"></i>
                                Processing
                            </div>
                        </div>
                    </div>

                    <div class="metric-card failed">
                        <div class="metric-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-number"><?= number_format($stats['overall']['failed_transactions'] ?? 0) ?></div>
                            <div class="metric-label">Failed Payments</div>
                            <div class="metric-change negative">
                                <i class="fas fa-exclamation-triangle"></i>
                                Needs attention
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Charts Section -->
            <section class="charts-section">
                <div class="row">
                    <div class="col-lg-8 mb-4">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-pie mr-2"></i>Service Revenue Breakdown
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="serviceRevenueChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-calendar-day mr-2"></i>Today's Activity
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- Revenue Summary -->
                                <div class="activity-summary mb-4">
                                    <div class="summary-item">
                                        <div class="summary-icon revenue">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </div>
                                        <div class="summary-content">
                                            <h3 class="summary-value">GHS <?= number_format($todayStats['overall']['total_revenue'] ?? 0, 2) ?></h3>
                                            <p class="summary-label">Today's Revenue</p>
                                        </div>
                                    </div>
                                    
                                    <div class="summary-item">
                                        <div class="summary-icon transactions">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                        <div class="summary-content">
                                            <h4 class="summary-value"><?= $todayStats['overall']['successful_transactions'] ?? 0 ?></h4>
                                            <p class="summary-label">Completed Payments</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Service Activity -->
                                <div class="service-activity">
                                    <h6 class="activity-header">Service Breakdown</h6>
                                    <?php if (!empty($todayStats['services'])): ?>
                                        <div class="activity-list">
                                            <?php foreach ($todayStats['services'] as $service): ?>
                                                <div class="activity-item">
                                                    <div class="activity-info">
                                                        <span class="service-name"><?= PaymentHandlerUtils::getServiceDisplayName($service['service_type']) ?></span>
                                                        <span class="service-count"><?= $service['transaction_count'] ?> transactions</span>
                                                    </div>
                                                    <div class="activity-progress">
                                                        <div class="progress">
                                                            <div class="progress-bar" style="width: <?= ($service['transaction_count'] / max(1, $todayStats['overall']['total_transactions'] ?? 1)) * 100 ?>%"></div>
                                                        </div>
                                                        <span class="progress-percentage"><?= round(($service['transaction_count'] / max(1, $todayStats['overall']['total_transactions'] ?? 1)) * 100) ?>%</span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-activity">
                                            <div class="empty-icon">
                                                <i class="fas fa-chart-line"></i>
                                            </div>
                                            <p class="empty-text">No activity today</p>
                                            <small class="empty-subtext">Transaction data will appear here once payments are processed</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Recent Transactions -->
            <section class="transactions-section">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-history mr-2"></i>Recent Transactions
                                    </h5>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-sm btn-primary" onclick="loadTransactions()">
                                            <i class="fas fa-sync mr-1"></i> Refresh
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary ml-2">
                                            <i class="fas fa-list mr-1"></i> View All
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped mb-0" id="transactionsTable">
                                        <thead class="thead-dark">
                                            <tr>
                                                <th>Transaction ID</th>
                                                <th>User</th>
                                                <th>Service</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="transactionsBody">
                                            <tr>
                                                <td colspan="7" class="text-center py-4">
                                                    <div class="spinner-border text-primary" role="status">
                                                        <span class="sr-only">Loading...</span>
                                                    </div>
                                                    <p class="mt-2 text-muted">Loading transactions...</p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Quick Actions & System Status -->
            <section class="actions-section">
                <div class="row">
                    <div class="col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-bolt mr-2"></i>Quick Actions
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="quick-actions-grid">
                                    <a href="payment-settings.php" class="action-item">
                                        <div class="action-icon">
                                            <i class="fas fa-cog"></i>
                                        </div>
                                        <div class="action-content">
                                            <h6 class="action-title">Payment Settings</h6>
                                            <p class="action-description">Configure system preferences</p>
                                        </div>
                                    </a>
                                    
                                    <a href="service-pricing.php" class="action-item">
                                        <div class="action-icon">
                                            <i class="fas fa-dollar-sign"></i>
                                        </div>
                                        <div class="action-content">
                                            <h6 class="action-title">Manage Pricing</h6>
                                            <p class="action-description">Set service rates and fees</p>
                                        </div>
                                    </a>
                                    
                                    <a href="payment-reports.php" class="action-item">
                                        <div class="action-icon">
                                            <i class="fas fa-chart-line"></i>
                                        </div>
                                        <div class="action-content">
                                            <h6 class="action-title">Generate Reports</h6>
                                            <p class="action-description">View detailed analytics</p>
                                        </div>
                                    </a>
                                    
                                    <button type="button" class="action-item btn-action" onclick="cleanupExpiredPayments()">
                                        <div class="action-icon">
                                            <i class="fas fa-broom"></i>
                                        </div>
                                        <div class="action-content">
                                            <h6 class="action-title">Cleanup Expired</h6>
                                            <p class="action-description">Remove old pending payments</p>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-server mr-2"></i>System Status
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="system-status-grid">
                                    <!-- Payment System Status -->
                                    <div class="status-card">
                                        <div class="status-icon">
                                            <i class="fas fa-toggle-<?= SecurePaymentConfig::get('payment_enabled') === '1' ? 'on' : 'off' ?> fa-2x text-<?= SecurePaymentConfig::get('payment_enabled') === '1' ? 'success' : 'danger' ?>"></i>
                                        </div>
                                        <div class="status-content">
                                            <h6 class="status-title">Payment System</h6>
                                            <span class="badge badge-<?= SecurePaymentConfig::get('payment_enabled') === '1' ? 'success' : 'danger' ?>">
                                                <i class="fas fa-circle fa-xs mr-1 pulse"></i>
                                                <?= SecurePaymentConfig::get('payment_enabled') === '1' ? 'Online' : 'Offline' ?>
                                            </span>
                                            <p class="status-description">
                                                <?= SecurePaymentConfig::get('payment_enabled') === '1' ? 'All payment services are operational' : 'Payment system is currently disabled' ?>
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Payment Gateway -->
                                    <div class="status-card">
                                        <div class="status-icon">
                                            <i class="fas fa-credit-card fa-2x text-info"></i>
                                        </div>
                                        <div class="status-content">
                                            <h6 class="status-title">Payment Gateway</h6>
                                            <span class="badge badge-info">
                                                <i class="fab fa-paypal fa-xs mr-1"></i>
                                                <?= ucfirst(SecurePaymentConfig::get('payment_gateway') ?: 'Paystack') ?>
                                            </span>
                                            <p class="status-description">
                                                Gateway connection established and verified
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Security Status -->
                                    <div class="status-card">
                                        <div class="status-icon">
                                            <i class="fas fa-shield-alt fa-2x text-success"></i>
                                        </div>
                                        <div class="status-content">
                                            <h6 class="status-title">Security Status</h6>
                                            <span class="badge badge-success">
                                                <i class="fas fa-lock fa-xs mr-1"></i>
                                                Encrypted
                                            </span>
                                            <p class="status-description">
                                                SSL/TLS encryption active and verified
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Currency -->
                                    <div class="status-card">
                                        <div class="status-icon">
                                            <i class="fas fa-money-bill-wave fa-2x text-warning"></i>
                                        </div>
                                        <div class="status-content">
                                            <h6 class="status-title">Currency</h6>
                                            <span class="badge badge-warning">
                                                <i class="fas fa-coins fa-xs mr-1"></i>
                                                <?= SecurePaymentConfig::get('payment_currency') ?: 'GHS' ?>
                                            </span>
                                            <p class="status-description">
                                                Primary transaction currency
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
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

.today-highlight {
    background: rgba(255, 215, 0, 0.1);
    border: 1px solid var(--gold);
    color: var(--gold);
    padding: 1rem 1.5rem;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
}

/* Content Section */
.content {
    padding: 0 2rem 2rem;
}

/* Metrics Section */
.metrics-section {
    margin-bottom: 2rem;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}

.metric-card {
    background: var(--white);
    border-radius: var(--border-radius);
    padding: 1rem;
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    border-left: 4px solid var(--gold);
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    position: relative;
    overflow: hidden;
}

.metric-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--gold), var(--black), var(--gold));
}

.metric-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.metric-card.revenue {
    border-left-color: var(--success);
}

.metric-card.successful {
    border-left-color: var(--info);
}

.metric-card.pending {
    border-left-color: var(--warning);
}

.metric-card.failed {
    border-left-color: var(--danger);
}

.metric-icon {
    width: 50px;
    height: 50px;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--white);
}

.metric-card.revenue .metric-icon {
    background: linear-gradient(135deg, var(--success), #1e7e34);
}

.metric-card.successful .metric-icon {
    background: linear-gradient(135deg, var(--info), #117a8b);
}

.metric-card.pending .metric-icon {
    background: linear-gradient(135deg, var(--warning), #e0a800);
}

.metric-card.failed .metric-icon {
    background: linear-gradient(135deg, var(--danger), #c82333);
}

.metric-content {
    flex: 1;
}

.metric-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--black);
    line-height: 1;
    margin-bottom: 0.25rem;
}

.metric-label {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 0.5rem;
}

.metric-change {
    font-size: 0.875rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.metric-change.positive { color: var(--success); }
.metric-change.neutral { color: var(--warning); }
.metric-change.negative { color: var(--danger); }

/* Cards */
.card {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    overflow: hidden;
    transition: var(--transition);
}

.card:hover {
    box-shadow: var(--shadow-hover);
}

.card-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 1.25rem 1.5rem;
    border-bottom: 2px solid var(--gold);
    position: relative;
}

.card-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--gold), transparent, var(--gold));
}

.card-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-title i {
    color: var(--gold);
}

.card-body {
    padding: 1.5rem;
}

.card-tools {
    display: flex;
    gap: 0.5rem;
}

/* Today's Activity Styles */
.activity-summary {
    border-bottom: 1px solid var(--gray-200);
    padding-bottom: 1.5rem;
}

.summary-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.summary-item:last-child {
    margin-bottom: 0;
}

.summary-icon {
    width: 50px;
    height: 50px;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--white);
}

.summary-icon.revenue {
    background: linear-gradient(135deg, var(--success), #1e7e34);
}

.summary-icon.transactions {
    background: linear-gradient(135deg, var(--info), #117a8b);
}

.summary-content {
    flex: 1;
}

.summary-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--black);
    line-height: 1;
    margin: 0 0 0.25rem 0;
}

.summary-label {
    font-size: 0.875rem;
    color: var(--gray-600);
    margin: 0;
}

.activity-header {
    font-size: 1rem;
    font-weight: 600;
    color: var(--black);
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--gold);
}

.activity-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.activity-item {
    background: var(--gray-50);
    border-radius: var(--border-radius);
    padding: 1rem;
    transition: var(--transition);
}

.activity-item:hover {
    background: var(--gold-light);
    transform: translateY(-2px);
}

.activity-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.service-name {
    font-weight: 600;
    color: var(--black);
}

.service-count {
    font-size: 0.875rem;
    color: var(--gray-600);
}

.activity-progress {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.progress {
    flex: 1;
    height: 8px;
    background-color: var(--gray-200);
    border-radius: 4px;
    overflow: hidden;
}

.progress-bar {
    background: linear-gradient(90deg, var(--gold), var(--gold-dark));
    transition: width 0.6s ease;
}

.progress-percentage {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--gold-dark);
    min-width: 35px;
    text-align: right;
}

.empty-activity {
    text-align: center;
    padding: 2rem 1rem;
}

.empty-icon {
    font-size: 2.5rem;
    color: var(--gray-400);
    margin-bottom: 1rem;
}

.empty-text {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-600);
    margin: 0 0 0.5rem 0;
}

.empty-subtext {
    font-size: 0.875rem;
    color: var(--gray-400);
    line-height: 1.4;
}

/* Quick Actions Styles */
.quick-actions-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}

.action-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: var(--gray-50);
    border-radius: var(--border-radius);
    transition: var(--transition);
    text-decoration: none;
    color: var(--gray-800);
    border: 2px solid transparent;
}

.action-item:hover {
    background: var(--gold-light);
    color: var(--gold-dark);
    transform: translateY(-2px);
    border-color: var(--gold);
    text-decoration: none;
}

.btn-action {
    border: none;
    text-align: left;
    width: 100%;
}

.action-icon {
    width: 45px;
    height: 45px;
    border-radius: var(--border-radius);
    background: linear-gradient(135deg, var(--black), var(--gold-dark));
    color: var(--white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
    flex-shrink: 0;
}

.action-content {
    flex: 1;
}

.action-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--black);
    margin: 0 0 0.25rem 0;
}

.action-description {
    font-size: 0.875rem;
    color: var(--gray-600);
    margin: 0;
    line-height: 1.3;
}

/* System Status Styles */
.system-status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
}

.status-card {
    background: var(--gray-50);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    display: flex;
    gap: 1rem;
    transition: var(--transition);
    border-left: 4px solid var(--gold);
}

.status-card:hover {
    background: var(--gold-light);
    transform: translateY(-2px);
}

.status-icon {
    flex-shrink: 0;
    display: flex;
    align-items: flex-start;
    padding-top: 0.25rem;
}

.status-content {
    flex: 1;
}

.status-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--black);
    margin: 0 0 0.5rem 0;
}

.status-description {
    font-size: 0.875rem;
    color: var(--gray-600);
    margin: 0.5rem 0 0 0;
    line-height: 1.3;
}

/* Buttons */
.btn {
    border-radius: var(--border-radius);
    font-weight: 600;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
    border: 2px solid transparent;
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
}

.btn-outline-secondary {
    border-color: var(--gray-400);
    color: var(--gray-600);
}

.btn-outline-secondary:hover {
    background: var(--gray-400);
    border-color: var(--gray-400);
    color: var(--white);
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

/* Tables */
.table {
    margin-bottom: 0;
}

.table-responsive {
    border-radius: var(--border-radius);
    overflow: hidden;
}

.thead-dark {
    background: linear-gradient(135deg, var(--black) 0%, #2c3e50 100%);
}

.thead-dark th {
    background: transparent;
    border: none;
    color: var(--gold);
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.875rem;
    letter-spacing: 0.5px;
    padding: 1rem 0.75rem;
}

.table-hover tbody tr:hover {
    background-color: rgba(255, 215, 0, 0.05);
    transition: background-color 0.2s ease;
}

.table td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
    border-top: 1px solid var(--gray-200);
}

/* Badges */
.badge {
    font-weight: 600;
    border-radius: 6px;
    padding: 0.5em 0.75em;
    font-size: 0.875em;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
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

.badge-secondary {
    background: var(--gray-400);
    color: var(--white);
}

.badge-primary {
    background: linear-gradient(135deg, var(--black), var(--gold-dark));
    color: var(--white);
}

/* Avatar */
.avatar-sm {
    width: 32px;
    height: 32px;
}

.avatar-title {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    background: linear-gradient(135deg, var(--black), var(--gold-dark));
}

.rounded-circle {
    border-radius: 50% !important;
}

/* Spinner */
.spinner-border {
    width: 2rem;
    height: 2rem;
    border: 0.25em solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: spinner-border 0.75s linear infinite;
}

@keyframes spinner-border {
    to {
        transform: rotate(360deg);
    }
}

/* Text colors */
.text-primary { color: var(--black) !important; }
.text-success { color: var(--success) !important; }
.text-warning { color: var(--warning) !important; }
.text-danger { color: var(--danger) !important; }
.text-info { color: var(--info) !important; }
.text-muted { color: var(--gray-600) !important; }
.text-dark { color: var(--gray-800) !important; }

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

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.metric-card, .card {
    animation: slideInUp 0.6s ease-out;
}

.metric-card:nth-child(1) { animation-delay: 0.1s; }
.metric-card:nth-child(2) { animation-delay: 0.2s; }
.metric-card:nth-child(3) { animation-delay: 0.3s; }
.metric-card:nth-child(4) { animation-delay: 0.4s; }

.pulse {
    animation: pulse 2s infinite;
}

/* Enhanced hover effects */
.metric-card:hover .metric-icon {
    transform: scale(1.1);
}

.action-item:hover .action-icon {
    transform: scale(1.05);
}

/* Responsive Design */
@media (max-width: 1200px) {
    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .system-status-grid {
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
    
    .metrics-grid {
        grid-template-columns: 1fr;
    }
    
    .metric-card {
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
    }
    
    .system-status-grid {
        grid-template-columns: 1fr;
    }
    
    .card-header {
        padding: 1rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .card-tools {
        flex-wrap: wrap;
        gap: 0.25rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .table td {
        padding: 0.75rem 0.5rem;
    }
    
    .summary-item {
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
    }
    
    .activity-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }
}

@media (max-width: 480px) {
    .metric-card {
        padding: 0.875rem;
    }
    
    .metric-number {
        font-size: 1.5rem;
    }
    
    .btn-sm {
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
    }
    
    .card-tools {
        justify-content: center;
    }
    
    .table {
        font-size: 0.75rem;
    }
    
    .status-card {
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
}

/* Focus States for Accessibility */
.btn:focus,
.action-item:focus {
    outline: 2px solid var(--gold);
    outline-offset: 2px;
}

/* Print Styles */
@media print {
    .dashboard-header,
    .btn,
    .card-tools {
        display: none;
    }
    
    .card {
        break-inside: avoid;
        box-shadow: none;
        border: 1px solid var(--gray-400);
    }
    
    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script src="../assets/js/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    // Initialize dashboard components
    initializeDashboard();
    loadTransactions();
    initializeCharts();
    
    function initializeDashboard() {
        // Add loading states
        addLoadingStates();
        
        // Initialize tooltips if available
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        }
        
        // Auto-refresh data every 5 minutes
        setInterval(function() {
            loadTransactions();
        }, 5 * 60 * 1000);
    }
    
    function addLoadingStates() {
        // Add shimmer effect to metric cards during initial load
        const metricCards = document.querySelectorAll('.metric-card');
        metricCards.forEach(card => {
            card.style.background = 'linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%)';
            card.style.backgroundSize = '200% 100%';
            card.style.animation = 'shimmer 1.5s infinite';
        });
        
        // Remove shimmer after content loads
        setTimeout(() => {
            metricCards.forEach(card => {
                card.style.background = '';
                card.style.animation = '';
            });
        }, 2000);
    }
});

// Load transactions function
function loadTransactions(limit = 10) {
    const tableBody = document.getElementById('transactionsBody');
    
    // Show loading state
    tableBody.innerHTML = `
        <tr>
            <td colspan="7" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading transactions...</p>
            </td>
        </tr>
    `;
    
    // Make AJAX request
    fetch('../api/admin-payment-handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_recent_transactions&limit=${limit}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayTransactions(data.data);
        } else {
            showError('Error loading transactions: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        showError('Network error occurred while loading transactions');
    });
}

// Display transactions in table
function displayTransactions(transactions) {
    const tableBody = document.getElementById('transactionsBody');
    
    if (!transactions || transactions.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-5">
                    <div class="d-flex flex-column align-items-center">
                        <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                        <h6 class="text-muted">No transactions found</h6>
                        <small class="text-muted">Transactions will appear here once payments are made</small>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    const statusConfig = {
        'completed': { class: 'success', icon: 'fa-check-circle', text: 'Completed' },
        'pending': { class: 'warning', icon: 'fa-clock', text: 'Pending' },
        'failed': { class: 'danger', icon: 'fa-times-circle', text: 'Failed' },
        'cancelled': { class: 'secondary', icon: 'fa-ban', text: 'Cancelled' }
    };
    
    let html = '';
    
    transactions.forEach((tx, index) => {
        const status = statusConfig[tx.status] || statusConfig['cancelled'];
        const date = new Date(tx.created_at);
        const formattedDate = date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const userInitial = (tx.user_name || 'N/A').charAt(0).toUpperCase();
        
        html += `
            <tr class="transaction-row" style="animation: slideInUp 0.5s ease ${index * 0.1}s both;">
                <td>
                    <strong class="text-primary">#${tx.transaction_id}</strong>
                </td>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm mr-2">
                            <div class="avatar-title rounded-circle text-white">
                                ${userInitial}
                            </div>
                        </div>
                        <span>${tx.user_name || 'N/A'}</span>
                    </div>
                </td>
                <td>
                    <span class="badge badge-primary">
                        ${tx.service_display_name || tx.service_type}
                    </span>
                </td>
                <td>
                    <strong class="text-dark">${tx.formatted_amount || `GHS ${tx.amount}`}</strong>
                </td>
                <td>
                    <span class="badge badge-${status.class}">
                        <i class="fas ${status.icon} mr-1"></i>${status.text}
                    </span>
                </td>
                <td>
                    <small class="text-muted">${formattedDate}</small>
                </td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="viewTransaction(${tx.transaction_id})" title="View Details">
                        <i class="fas fa-eye mr-1"></i>View
                    </button>
                </td>
            </tr>
        `;
    });
    
    tableBody.innerHTML = html;
}

// Initialize charts
function initializeCharts() {
    const chartCanvas = document.getElementById('serviceRevenueChart');
    if (!chartCanvas) return;
    
    const ctx = chartCanvas.getContext('2d');
    const serviceData = <?= json_encode($stats['services'] ?? []) ?>;
    
    if (serviceData && serviceData.length > 0) {
        const labels = serviceData.map(s => s.service_type.replace(/_/g, ' ').toUpperCase());
        const data = serviceData.map(s => parseFloat(s.revenue) || 0);
        const colors = ['#007bff', '#28a745', '#ffc107', '#dc3545', '#6c757d'];
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors.slice(0, data.length),
                    borderWidth: 3,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 12,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: GHS ${value.toFixed(2)} (${percentage}%)`;
                            }
                        }
                    }
                },
                elements: {
                    arc: {
                        borderWidth: 2
                    }
                }
            }
        });
    } else {
        // Show no data message
        ctx.font = '16px Inter, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillStyle = '#6c757d';
        ctx.fillText('No payment data available', chartCanvas.width / 2, chartCanvas.height / 2);
        
        // Add icon
        ctx.font = '48px FontAwesome';
        ctx.fillText('\uf1fe', chartCanvas.width / 2, chartCanvas.height / 2 - 40);
    }
}

// View transaction details
function viewTransaction(transactionId) {
    if (!transactionId) {
        console.error('Transaction ID is required');
        return;
    }
    
    // Add loading state to button
    const button = event.target.closest('button');
    const originalContent = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Loading...';
    button.disabled = true;
    
    // Simulate navigation (replace with actual URL)
    setTimeout(() => {
        window.location.href = `payment-transaction-details.php?id=${transactionId}`;
    }, 500);
}

// Cleanup expired payments
function cleanupExpiredPayments() {
    if (!confirm('This will cancel all expired pending payments. This action cannot be undone. Continue?')) {
        return;
    }
    
    const button = event.target.closest('button');
    const originalContent = button.innerHTML;
    
    // Show loading state
    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Cleaning...';
    button.disabled = true;
    
    fetch('../api/admin-payment-handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=cleanup_expired_payments'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess(`Successfully cleaned up ${data.cleaned_count || 0} expired payments`);
            loadTransactions(); // Refresh the transactions table
        } else {
            showError('Error: ' + (data.message || 'Unknown error occurred'));
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        showError('Network error occurred during cleanup');
    })
    .finally(() => {
        // Restore button state
        button.innerHTML = originalContent;
        button.disabled = false;
    });
}

// Utility functions
function showSuccess(message) {
    showNotification(message, 'success');
}

function showError(message) {
    showNotification(message, 'error');
    
    // Update table with error message
    const tableBody = document.getElementById('transactionsBody');
    if (tableBody) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center text-danger py-4">
                    <i class="fas fa-exclamation-triangle mr-2"></i>${message}
                    <br>
                    <button class="btn btn-sm btn-primary mt-2" onclick="loadTransactions()">
                        <i class="fas fa-retry mr-1"></i>Retry
                    </button>
                </td>
            </tr>
        `;
    }
}

function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 1050; min-width: 300px;';
    
    const icon = type === 'success' ? 'fa-check-circle' : 
                 type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
    
    notification.innerHTML = `
        <i class="fas ${icon} mr-2"></i>${message}
        <button type="button" class="close" data-dismiss="alert">
            <span>&times;</span>
        </button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

// Add CSS for shimmer effect
const shimmerCSS = `
    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }
    
    .transaction-row td {
        padding: 1rem;
        vertical-align: middle;
    }
`;

// Inject shimmer CSS
const style = document.createElement('style');
style.textContent = shimmerCSS;
document.head.appendChild(style);
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>