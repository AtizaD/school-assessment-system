<?php
/**
 * View Archived Students - School Assessment Management System
 * Display all archived students (alumni) with search and filter capabilities
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

$db = DatabaseConfig::getInstance()->getConnection();

// Get search and filter parameters
$searchTerm = $_GET['search'] ?? '';
$yearFilter = $_GET['year'] ?? '';

// Pagination settings
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50; // Students per page
$offset = ($page - 1) * $perPage;

// Build base query for counting
$countSql = "SELECT COUNT(*) FROM archived_students WHERE 1=1";
$countParams = [];

if (!empty($searchTerm)) {
    $countSql .= " AND (username LIKE :search OR first_name LIKE :search OR last_name LIKE :search)";
    $countParams[':search'] = '%' . $searchTerm . '%';
}

if (!empty($yearFilter)) {
    $countSql .= " AND graduation_year = :year";
    $countParams[':year'] = $yearFilter;
}

// Get total count
$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// Build query with filters and pagination
$sql = "
SELECT
    archive_id,
    username,
    first_name,
    last_name,
    class_name,
    graduation_year,
    archived_date
FROM archived_students
WHERE 1=1
";

$sqlParams = [];

if (!empty($searchTerm)) {
    $sql .= " AND (username LIKE :search OR first_name LIKE :search OR last_name LIKE :search)";
    $sqlParams[':search'] = '%' . $searchTerm . '%';
}

if (!empty($yearFilter)) {
    $sql .= " AND graduation_year = :year";
    $sqlParams[':year'] = $yearFilter;
}

$sql .= " ORDER BY archived_date DESC, username ASC LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);

// Bind parameters
foreach ($sqlParams as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$archivedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all available graduation years for filter
$yearsStmt = $db->query("SELECT DISTINCT graduation_year FROM archived_students ORDER BY graduation_year DESC");
$graduationYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$statsStmt = $db->query("
    SELECT
        COUNT(*) as total,
        COUNT(DISTINCT graduation_year) as years_count,
        MIN(graduation_year) as first_year,
        MAX(graduation_year) as latest_year
    FROM archived_students
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Archived Students';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="archived-main">
    <!-- Header Section -->
    <section class="archived-header">
        <div class="header-content">
            <div class="welcome-section">
                <h1 class="archived-title">
                    <i class="fas fa-graduation-cap"></i>
                    Archived Students (Alumni)
                </h1>
                <p class="archived-subtitle">
                    View and manage archived student records across <?php echo $stats['years_count']; ?> graduation years
                </p>
            </div>
            <div class="header-actions">
                <a href="archive_form3_students.php" class="btn-primary">
                    <i class="fas fa-archive"></i>
                    Archive Students
                </a>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="stats-section">
        <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                        <div class="stat-label">Total Alumni</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $stats['years_count']; ?></div>
                        <div class="stat-label">Graduation Years</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $stats['first_year']; ?> - <?php echo $stats['latest_year']; ?></div>
                        <div class="stat-label">Year Range</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-filter"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($totalRecords); ?></div>
                        <div class="stat-label">Filtered Results (Page <?php echo $page; ?> of <?php echo max(1, $totalPages); ?>)</div>
                    </div>
                </div>
            </div>
    </section>

    <!-- Content Section -->
    <section class="content-section">
        <div class="students-panel">
                <div class="panel-header">
                    <h2>
                        <i class="fas fa-search"></i>
                        Search & Filter Alumni
                    </h2>
                </div>
                <div class="panel-body">
                    <!-- Search and Filter Form -->
                    <form method="GET" class="filter-form" id="filterForm">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="search">
                                    <i class="fas fa-search"></i>
                                    Search by Name or Username
                                </label>
                                <input
                                    type="text"
                                    id="search"
                                    name="search"
                                    placeholder="Enter name or username..."
                                    value="<?php echo htmlspecialchars($searchTerm); ?>"
                                    class="filter-input"
                                >
                            </div>
                            <div class="filter-group">
                                <label for="year">
                                    <i class="fas fa-calendar"></i>
                                    Graduation Year
                                </label>
                                <select id="year" name="year" class="filter-select">
                                    <option value="">All Years</option>
                                    <?php foreach ($graduationYears as $year): ?>
                                        <option value="<?php echo $year; ?>" <?php echo ($yearFilter == $year) ? 'selected' : ''; ?>>
                                            <?php echo $year; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="btn-filter">
                                    <i class="fas fa-filter"></i>
                                    Apply Filters
                                </button>
                                <a href="view_archived_students.php" class="btn-reset">
                                    <i class="fas fa-redo"></i>
                                    Reset
                                </a>
                            </div>
                        </div>
                    </form>

                    <!-- Results Info -->
                    <?php if ($totalRecords > 0): ?>
                    <div class="filter-info">
                        <i class="fas fa-info-circle"></i>
                        Showing <?php echo number_format($offset + 1); ?> - <?php echo number_format(min($offset + $perPage, $totalRecords)); ?> of <?php echo number_format($totalRecords); ?> results
                        <?php if (!empty($searchTerm)): ?>
                            matching "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                        <?php endif; ?>
                        <?php if (!empty($yearFilter)): ?>
                            from graduation year <strong><?php echo htmlspecialchars($yearFilter); ?></strong>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Students Table -->
                    <div class="table-container">
                        <?php if (empty($archivedStudents)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h3>No Archived Students Found</h3>
                                <p>
                                    <?php if (!empty($searchTerm) || !empty($yearFilter)): ?>
                                        No students match your search criteria. Try adjusting your filters.
                                    <?php else: ?>
                                        No students have been archived yet.
                                    <?php endif; ?>
                                </p>
                                <div class="empty-actions">
                                    <?php if (!empty($searchTerm) || !empty($yearFilter)): ?>
                                        <a href="view_archived_students.php" class="btn-primary">
                                            <i class="fas fa-redo"></i>
                                            Clear Filters
                                        </a>
                                    <?php else: ?>
                                        <a href="archive_form3_students.php" class="btn-primary">
                                            <i class="fas fa-archive"></i>
                                            Archive Students
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="students-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Username</th>
                                            <th>First Name</th>
                                            <th>Last Name</th>
                                            <th>Class</th>
                                            <th>Graduation Year</th>
                                            <th>Archived Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($archivedStudents as $index => $student): ?>
                                        <tr>
                                            <td><?php echo $offset + $index + 1; ?></td>
                                            <td class="username-cell">
                                                <span class="username-badge">
                                                    <?php echo htmlspecialchars($student['username']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($student['first_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="year-badge">
                                                    <i class="fas fa-graduation-cap"></i>
                                                    <?php echo htmlspecialchars($student['graduation_year']); ?>
                                                </span>
                                            </td>
                                            <td class="date-cell">
                                                <?php echo date('M j, Y', strtotime($student['archived_date'])); ?>
                                                <span class="time-text"><?php echo date('h:i A', strtotime($student['archived_date'])); ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                            <div class="pagination-section">
                                <div class="pagination-info">
                                    Showing <?php echo number_format($offset + 1); ?> - <?php echo number_format(min($offset + $perPage, $totalRecords)); ?> of <?php echo number_format($totalRecords); ?>
                                </div>
                                <nav class="pagination">
                                    <?php
                                    // Build URL with existing parameters
                                    $urlParams = [];
                                    if (!empty($searchTerm)) $urlParams['search'] = $searchTerm;
                                    if (!empty($yearFilter)) $urlParams['year'] = $yearFilter;

                                    function buildPageUrl($page, $params) {
                                        $params['page'] = $page;
                                        return '?' . http_build_query($params);
                                    }

                                    // First page
                                    if ($page > 1): ?>
                                        <a href="<?php echo buildPageUrl(1, $urlParams); ?>" class="page-link" title="First Page">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                        <a href="<?php echo buildPageUrl($page - 1, $urlParams); ?>" class="page-link" title="Previous Page">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-link disabled">
                                            <i class="fas fa-angle-double-left"></i>
                                        </span>
                                        <span class="page-link disabled">
                                            <i class="fas fa-angle-left"></i>
                                        </span>
                                    <?php endif; ?>

                                    <?php
                                    // Calculate page range
                                    $range = 2;
                                    $startPage = max(1, $page - $range);
                                    $endPage = min($totalPages, $page + $range);

                                    // Show first page if not in range
                                    if ($startPage > 1): ?>
                                        <a href="<?php echo buildPageUrl(1, $urlParams); ?>" class="page-link">1</a>
                                        <?php if ($startPage > 2): ?>
                                            <span class="page-link disabled">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <?php if ($i == $page): ?>
                                            <span class="page-link active"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="<?php echo buildPageUrl($i, $urlParams); ?>" class="page-link"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php
                                    // Show last page if not in range
                                    if ($endPage < $totalPages): ?>
                                        <?php if ($endPage < $totalPages - 1): ?>
                                            <span class="page-link disabled">...</span>
                                        <?php endif; ?>
                                        <a href="<?php echo buildPageUrl($totalPages, $urlParams); ?>" class="page-link"><?php echo $totalPages; ?></a>
                                    <?php endif; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="<?php echo buildPageUrl($page + 1, $urlParams); ?>" class="page-link" title="Next Page">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                        <a href="<?php echo buildPageUrl($totalPages, $urlParams); ?>" class="page-link" title="Last Page">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-link disabled">
                                            <i class="fas fa-angle-right"></i>
                                        </span>
                                        <span class="page-link disabled">
                                            <i class="fas fa-angle-double-right"></i>
                                        </span>
                                    <?php endif; ?>
                                </nav>
                            </div>
                            <?php endif; ?>

                            <!-- Export Options -->
                            <div class="export-section">
                                <button onclick="exportToCSV()" class="btn-export">
                                    <i class="fas fa-file-csv"></i>
                                    Export to CSV
                                </button>
                                <button onclick="window.print()" class="btn-export">
                                    <i class="fas fa-print"></i>
                                    Print List
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
        </div>
    </section>
</main>

<style>
/* Black & Gold Professional Theme */
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
    --info: #17a2b8;
    --shadow: 0 2px 4px rgba(0,0,0,0.05), 0 8px 16px rgba(0,0,0,0.05);
    --shadow-hover: 0 4px 8px rgba(0,0,0,0.1), 0 12px 24px rgba(0,0,0,0.1);
    --border-radius: 12px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.archived-main {
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 100%);
    min-height: 100vh;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

/* Header */
.archived-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.archived-header::before {
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

.archived-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.archived-title i {
    color: var(--gold);
}

.archived-subtitle {
    font-size: 1.1rem;
    margin: 0.5rem 0 0 0;
    opacity: 0.9;
}

.header-actions {
    display: flex;
    gap: 1rem;
}

/* Statistics Section */
.stats-section {
    padding: 0 2rem;
    margin-bottom: 2rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
}

.stat-card {
    background: var(--white);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    border-left: 4px solid var(--gold);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: var(--transition);
    animation: slideInUp 0.6s ease-out;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.stat-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, var(--black), var(--gold-dark));
    color: var(--white);
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-content {
    flex: 1;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--black);
    line-height: 1;
}

.stat-label {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-600);
    margin: 0.25rem 0;
}

/* Content Section */
.content-section {
    padding: 0 2rem 2rem;
}

.students-panel {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    overflow: hidden;
    animation: slideInUp 0.6s ease-out;
    width: 100%;
}

.panel-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 1.25rem 1.5rem;
    border-bottom: 2px solid var(--gold);
}

.panel-header h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.panel-body {
    padding: 2rem;
}

/* Filter Form */
.filter-form {
    margin-bottom: 2rem;
}

.filter-grid {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 1.5rem;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-group label {
    font-weight: 600;
    color: var(--gray-800);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.filter-group label i {
    color: var(--gold-dark);
}

.filter-input,
.filter-select {
    padding: 0.75rem 1rem;
    border: 2px solid var(--gray-200);
    border-radius: var(--border-radius);
    font-size: 1rem;
    transition: var(--transition);
    background: var(--white);
    font-family: inherit;
}

.filter-input:focus,
.filter-select:focus {
    outline: none;
    border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
}

.filter-actions {
    display: flex;
    gap: 0.75rem;
}

/* Buttons */
.btn-primary,
.btn-secondary,
.btn-filter,
.btn-reset,
.btn-export {
    padding: 0.75rem 1.5rem;
    border-radius: var(--border-radius);
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: var(--transition);
    cursor: pointer;
    border: 2px solid;
    font-size: 1rem;
}

.btn-primary {
    background: var(--black);
    color: var(--white);
    border-color: var(--black);
}

.btn-primary:hover {
    background: var(--gold);
    color: var(--black);
    border-color: var(--gold);
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.btn-secondary {
    background: var(--white);
    color: var(--black);
    border-color: var(--gray-400);
}

.btn-secondary:hover {
    background: var(--gray-100);
    border-color: var(--black);
    transform: translateY(-2px);
}

.btn-filter {
    background: var(--gold);
    color: var(--black);
    border-color: var(--gold);
}

.btn-filter:hover {
    background: var(--gold-dark);
    border-color: var(--gold-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.btn-reset {
    background: var(--white);
    color: var(--gray-600);
    border-color: var(--gray-400);
}

.btn-reset:hover {
    background: var(--gray-100);
    color: var(--black);
    border-color: var(--gray-600);
}

.btn-export {
    background: var(--white);
    color: var(--black);
    border-color: var(--gray-400);
    padding: 0.625rem 1.25rem;
    font-size: 0.875rem;
}

.btn-export:hover {
    background: var(--black);
    color: var(--white);
    border-color: var(--black);
    transform: translateY(-2px);
}

/* Filter Info */
.filter-info {
    background: var(--gold-light);
    border: 1px solid var(--gold);
    border-radius: var(--border-radius);
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: var(--gray-800);
    font-size: 0.95rem;
}

.filter-info i {
    color: var(--gold-dark);
    font-size: 1.25rem;
}

/* Table */
.table-container {
    margin-top: 1.5rem;
}

.table-responsive {
    overflow-x: auto;
    border-radius: var(--border-radius);
    border: 1px solid var(--gray-200);
}

.students-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--white);
}

.students-table thead {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
}

.students-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.students-table td {
    padding: 1rem;
    border-bottom: 1px solid var(--gray-200);
    font-size: 0.95rem;
}

.students-table tbody tr {
    transition: var(--transition);
}

.students-table tbody tr:hover {
    background: var(--gray-50);
}

.students-table tbody tr:last-child td {
    border-bottom: none;
}

.username-badge {
    background: var(--gold-light);
    color: var(--gold-dark);
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    font-size: 0.875rem;
    display: inline-block;
}

.year-badge {
    background: var(--black);
    color: var(--white);
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.date-cell {
    color: var(--gray-800);
}

.time-text {
    display: block;
    font-size: 0.75rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
}

/* Pagination Section */
.pagination-section {
    margin-top: 1.5rem;
    padding: 1.5rem;
    background: var(--gray-50);
    border-radius: var(--border-radius);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.pagination-info {
    font-size: 0.9rem;
    color: var(--gray-600);
    font-weight: 500;
}

.pagination {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.page-link {
    min-width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.5rem 0.75rem;
    background: var(--white);
    border: 2px solid var(--gray-200);
    border-radius: 8px;
    color: var(--gray-800);
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
    cursor: pointer;
}

.page-link:hover:not(.disabled):not(.active) {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--black);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.page-link.active {
    background: var(--black);
    border-color: var(--black);
    color: var(--white);
    cursor: default;
}

.page-link.disabled {
    background: var(--gray-100);
    border-color: var(--gray-200);
    color: var(--gray-400);
    cursor: not-allowed;
    opacity: 0.5;
}

/* Export Section */
.export-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--gray-200);
    display: flex;
    gap: 1rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--gray-600);
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.3;
    color: var(--gray-400);
}

.empty-state h3 {
    font-size: 1.25rem;
    color: var(--gray-800);
    margin: 0 0 0.5rem 0;
    font-weight: 600;
}

.empty-state p {
    margin: 0 0 2rem 0;
    font-size: 0.875rem;
    line-height: 1.5;
}

.empty-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
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

/* Responsive */
@media (max-width: 968px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }

    .filter-actions {
        flex-direction: column;
    }

    .filter-actions .btn-filter,
    .filter-actions .btn-reset {
        justify-content: center;
    }

    .pagination-section {
        flex-direction: column;
        text-align: center;
    }

    .pagination {
        flex-wrap: wrap;
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .archived-header {
        padding: 1.5rem;
    }

    .header-content {
        flex-direction: column;
        gap: 1rem;
    }

    .archived-title {
        font-size: 2rem;
    }

    .header-actions {
        flex-direction: column;
        width: 100%;
    }

    .header-actions a {
        justify-content: center;
    }

    .content-section,
    .stats-section {
        padding: 0 1rem 2rem;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .export-section {
        flex-direction: column;
    }
}

/* Print Styles */
@media print {
    .archived-header,
    .filter-form,
    .pagination-section,
    .export-section,
    .header-actions,
    .btn-primary,
    .btn-secondary {
        display: none !important;
    }

    .students-panel {
        box-shadow: none;
        border: 1px solid var(--gray-400);
    }

    .students-table {
        font-size: 0.875rem;
    }

    .students-table th,
    .students-table td {
        padding: 0.5rem;
    }
}
</style>

<script>
// Export to CSV functionality - exports ALL filtered results, not just current page
function exportToCSV() {
    // Get current filter parameters
    const urlParams = new URLSearchParams(window.location.search);

    // Build export URL with current filters
    let exportUrl = 'export_archived_students.php?';
    if (urlParams.has('search')) {
        exportUrl += 'search=' + encodeURIComponent(urlParams.get('search')) + '&';
    }
    if (urlParams.has('year')) {
        exportUrl += 'year=' + encodeURIComponent(urlParams.get('year')) + '&';
    }

    // Open export in new window/download
    window.location.href = exportUrl;
}

// Auto-submit form on filter change (optional - for better UX)
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search');
    const yearSelect = document.getElementById('year');

    // Add debounce to search input
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            // Auto-submit is optional - remove if you want manual submit only
            // document.getElementById('filterForm').submit();
        }, 500);
    });

    // Add smooth scroll to top when pagination links are clicked
    const pageLinks = document.querySelectorAll('.page-link:not(.disabled)');
    pageLinks.forEach(link => {
        link.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>
