<?php
// admin/performance_dashboard.php
ob_start(); // Start output buffering at the very beginning

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/vendor/autoload.php';
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Only admin and teachers can access this page
requireRole(['admin', 'teacher']);

// Handle filters
$filters = [];
$filterApplied = false;
$exportRequested = false;

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get all classes for dropdown
    $stmt = $db->prepare("SELECT c.class_id, c.class_name, c.level, p.program_name 
                         FROM classes c JOIN programs p ON c.program_id = p.program_id 
                         ORDER BY p.program_name, c.level, c.class_name");
    $stmt->execute();
    $classes = $stmt->fetchAll();

    // Get all unique levels for dropdown
    $stmt = $db->prepare("SELECT DISTINCT level FROM classes ORDER BY level");
    $stmt->execute();
    $levels = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get all subjects for dropdown
    $stmt = $db->prepare("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name");
    $stmt->execute();
    $subjects = $stmt->fetchAll();

    // Get all semesters for dropdown
    $stmt = $db->prepare("SELECT semester_id, semester_name FROM semesters ORDER BY start_date DESC");
    $stmt->execute();
    $semesters = $stmt->fetchAll();

    // Get current/active semester
    $stmt = $db->prepare(
        "SELECT semester_id, semester_name FROM semesters 
         WHERE CURDATE() BETWEEN start_date AND end_date 
         ORDER BY start_date DESC LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();
    $currentSemesterId = $currentSemester ? $currentSemester['semester_id'] : null;
} catch (Exception $e) {
    logError("Performance dashboard error: " . $e->getMessage());
    $error = "Error loading data: " . $e->getMessage();
}

$pageTitle = 'Student Performance Dashboard';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 text-warning mb-0">Student Performance Dashboard</h1>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">Filter Options</h5>
        </div>
        <div class="card-body">
            <form method="get" action="" id="filterForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="level" class="form-label">Level</label>
                        <select name="level" id="level" class="form-select">
                            <option value="">All Levels</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?php echo htmlspecialchars($level); ?>">
                                    <?php echo htmlspecialchars($level); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="class_id" class="form-label">Class</label>
                        <select name="class_id" id="class_id" class="form-select">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['class_id']; ?>" 
                                        data-level="<?php echo htmlspecialchars($class['level']); ?>">
                                    <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="subject_id" class="form-label">Subject</label>
                        <select name="subject_id" id="subject_id" class="form-select">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="semester_id" class="form-label">Semester</label>
                        <select name="semester_id" id="semester_id" class="form-select">
                            <option value="">All Semesters</option>
                            <?php foreach ($semesters as $semester): ?>
                                <option value="<?php echo $semester['semester_id']; ?>"
                                    <?php if ($currentSemesterId == $semester['semester_id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($semester['semester_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <button type="button" id="applyFilters" class="btn btn-warning">
                            <i class="fas fa-filter me-1"></i>Apply Filters
                        </button>
                        <button type="button" class="btn btn-outline-secondary ms-2" id="resetBtn">
                            <i class="fas fa-undo me-1"></i>Reset Filters
                        </button>
                        <button type="button" class="btn btn-success ms-2" id="exportPdfBtn">
                            <i class="fas fa-file-pdf me-1"></i>Export to PDF
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row" id="summaryStats">
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-users me-2"></i>Total Students</h5>
                    <h2 class="display-4 mb-0" id="totalStudentsCount">-</h2>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-book me-2"></i>Subjects</h5>
                    <h2 class="display-4 mb-0" id="totalSubjectsCount">-</h2>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-file-alt me-2"></i>Assessments</h5>
                    <h2 class="display-4 mb-0" id="totalAssessmentsCount">-</h2>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm bg-warning text-dark">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-chart-line me-2"></i>Avg. Score</h5>
                    <h2 class="display-4 mb-0" id="overallAvgScore">-</h2>
                </div>
            </div>
        </div>
        <div class="col-lg-12 mb-4">
            <div class="card border-0 shadow-sm bg-secondary text-white">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-tasks me-2"></i>Assessment Types Active</h5>
                    <h2 class="display-4 mb-0" id="totalAssessmentTypesCount">-</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Performers Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">Top Performers by Subject</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive" id="topPerformersContainer">
                <p class="text-center py-3">Select filters and apply to see top performers.</p>
            </div>
        </div>
    </div>

    <!-- Class Performance Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">Class Performance by Subject</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive" id="classPerformanceContainer">
                <p class="text-center py-3">Select filters and apply to see class performance data.</p>
            </div>
        </div>
    </div>

    <!-- Assessment Type Performance Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">Performance by Assessment Type</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive" id="assessmentTypeContainer">
                <p class="text-center py-3">Select filters and apply to see assessment type performance.</p>
            </div>
        </div>
    </div>

    <!-- Assessment Stats Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">Assessment Participation Statistics</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive" id="assessmentStatsContainer">
                <p class="text-center py-3">Select filters and apply to see assessment statistics.</p>
            </div>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const levelSelect = document.getElementById('level');
        const classSelect = document.getElementById('class_id');
        const applyFiltersBtn = document.getElementById('applyFilters');
        const resetBtn = document.getElementById('resetBtn');

        // Level filter functionality - filter classes based on selected level
        levelSelect.addEventListener('change', function() {
            const selectedLevel = this.value;
            
            // Reset class selection
            classSelect.value = '';
            
            // Show/hide class options based on selected level
            Array.from(classSelect.options).forEach(option => {
                if (option.value === '') return; // Skip "All Classes" option
                
                const optionLevel = option.getAttribute('data-level');
                if (!selectedLevel || optionLevel === selectedLevel) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
        });

        // Apply filters button handler
        applyFiltersBtn.addEventListener('click', function() {
            loadPerformanceData();
        });

        // Reset button handler
        resetBtn.addEventListener('click', function() {
            document.getElementById('level').value = '';
            document.getElementById('class_id').value = '';
            document.getElementById('subject_id').value = '';
            document.getElementById('semester_id').value = '<?php echo $currentSemesterId; ?>';
            
            // Reset class options visibility
            Array.from(classSelect.options).forEach(option => {
                option.style.display = '';
            });
        });

        // Load data based on current filters
        function loadPerformanceData() {
            const level = document.getElementById('level').value;
            const classId = document.getElementById('class_id').value;
            const subjectId = document.getElementById('subject_id').value;
            const semesterId = document.getElementById('semester_id').value;

            // Show loading indicators
            document.getElementById('topPerformersContainer').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading top performers data...</p></div>';
            document.getElementById('classPerformanceContainer').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-success" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading class performance data...</p></div>';
            document.getElementById('assessmentTypeContainer').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-warning" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading assessment type data...</p></div>';
            document.getElementById('assessmentStatsContainer').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-info" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading assessment statistics...</p></div>';

            // Build query string
            let queryString = '';
            if (level) queryString += `&level=${encodeURIComponent(level)}`;
            if (classId) queryString += `&class_id=${classId}`;
            if (subjectId) queryString += `&subject_id=${subjectId}`;
            if (semesterId) queryString += `&semester_id=${semesterId}`;

            // Fetch data from API
            fetch(`../api/get_student_performance.php?${queryString.substring(1)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    // Update summary stats
                    document.getElementById('totalStudentsCount').textContent = data.summary.total_students || 0;
                    document.getElementById('totalSubjectsCount').textContent = data.summary.total_subjects || 0;
                    document.getElementById('totalAssessmentsCount').textContent = data.summary.total_assessments || 0;
                    document.getElementById('totalAssessmentTypesCount').textContent = data.summary.total_assessment_types || 0;
                    document.getElementById('overallAvgScore').textContent = data.summary.overall_avg_score || 0;

                    // Render top performers
                    renderTopPerformers(data.top_performers);

                    // Render class performance
                    renderClassPerformance(data.class_performance);

                    // Render assessment type breakdown
                    renderAssessmentTypeBreakdown(data.assessment_type_breakdown);

                    // Render assessment stats
                    renderAssessmentStats(data.assessment_stats);
                })
                .catch(error => {
                    console.error('Error fetching performance data:', error);
                    document.getElementById('topPerformersContainer').innerHTML = '<div class="alert alert-danger">Error loading top performers data. Please try again.</div>';
                    document.getElementById('classPerformanceContainer').innerHTML = '<div class="alert alert-danger">Error loading class performance data. Please try again.</div>';
                    document.getElementById('assessmentTypeContainer').innerHTML = '<div class="alert alert-danger">Error loading assessment type data. Please try again.</div>';
                    document.getElementById('assessmentStatsContainer').innerHTML = '<div class="alert alert-danger">Error loading assessment statistics. Please try again.</div>';
                });
        }

        // Render top performers data
        function renderTopPerformers(topPerformers) {
            const container = document.getElementById('topPerformersContainer');

            if (!topPerformers || topPerformers.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No top performers data available for the selected filters.</div>';
                return;
            }

            let html = '';

            topPerformers.forEach(subject => {
                html += `
                <h4 class="mt-3 mb-3">${subject.subject_name}</h4>
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Rank</th>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Level</th>
                            <th>Program</th>
                            <th>Average Score</th>
                            <th>Best Completion Time</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

                if (subject.students.length === 0) {
                    html += `<tr><td colspan="7" class="text-center">No students data available for this subject.</td></tr>`;
                } else {
                    subject.students.forEach((student, index) => {
                        html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${student.student_name}</td>
                            <td>${student.class_name}</td>
                            <td><span class="badge bg-info">${student.level || 'N/A'}</span></td>
                            <td>${student.program_name}</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="progress flex-grow-1 me-2" style="height: 10px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: ${student.avg_score}%"></div>
                                    </div>
                                    <span>${student.avg_score}</span>
                                </div>
                            </td>
                            <td>${student.best_completion_time}</td>
                        </tr>
                    `;
                    });
                }

                html += `
                    </tbody>
                </table>
            `;
            });

            container.innerHTML = html;
        }

        // Render class performance data
        function renderClassPerformance(classPerformance) {
            const container = document.getElementById('classPerformanceContainer');

            if (!classPerformance || classPerformance.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No class performance data available for the selected filters.</div>';
                return;
            }

            let html = '';

            classPerformance.forEach(subject => {
                html += `
                <h4 class="mt-3 mb-3">${subject.subject_name}</h4>
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Rank</th>
                            <th>Class</th>
                            <th>Level</th>
                            <th>Program</th>
                            <th>Students</th>
                            <th>Average Score</th>
                            <th>Min Score</th>
                            <th>Max Score</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

                if (subject.classes.length === 0) {
                    html += `<tr><td colspan="8" class="text-center">No class data available for this subject.</td></tr>`;
                } else {
                    subject.classes.forEach((classItem, index) => {
                        html += `
                        <tr>
                        <td>${index + 1}</td>
                        <td>${classItem.class_name}</td>
                        <td><span class="badge bg-info">${classItem.level || 'N/A'}</span></td>
                        <td>${classItem.program_name}</td>
                        <td>${classItem.total_students}</td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="progress flex-grow-1 me-2" style="height: 10px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: ${classItem.avg_score}%"></div>
                                </div>
                                <span>${classItem.avg_score}</span>
                            </div>
                        </td>
                        <td>${classItem.min_score}</td>
                        <td>${classItem.max_score}</td>
                    </tr>
                `;
                    });
                }

                html += `
                    </tbody>
                </table>
            `;
            });

            container.innerHTML = html;
        }

        // Render assessment type breakdown data
        function renderAssessmentTypeBreakdown(assessmentTypeData) {
            const container = document.getElementById('assessmentTypeContainer');

            if (!assessmentTypeData || assessmentTypeData.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No assessment type data available for the selected filters.</div>';
                return;
            }

            let html = `
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Assessment Type</th>
                            <th>Weight (%)</th>
                            <th>Total Assessments</th>
                            <th>Completed Results</th>
                            <th>Students Attempted</th>
                            <th>Average Score</th>
                            <th>Min Score</th>
                            <th>Max Score</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            assessmentTypeData.forEach(typeData => {
                const scoreClass = typeData.average_score >= 70 ? 'success' : (typeData.average_score >= 50 ? 'warning' : 'danger');
                
                html += `
                    <tr>
                        <td>
                            <strong>${typeData.type_name}</strong>
                            ${typeData.weight_percentage > 0 ? `<br><small class="text-muted">Weight: ${typeData.weight_percentage}%</small>` : ''}
                        </td>
                        <td>
                            ${typeData.weight_percentage > 0 ? 
                                `<span class="badge bg-info">${typeData.weight_percentage}%</span>` : 
                                '<span class="text-muted">-</span>'
                            }
                        </td>
                        <td>${typeData.total_assessments}</td>
                        <td>${typeData.completed_results}</td>
                        <td>${typeData.students_attempted}</td>
                        <td>
                            ${typeData.average_score ? 
                                `<div class="d-flex align-items-center">
                                    <div class="progress flex-grow-1 me-2" style="height: 10px;">
                                        <div class="progress-bar bg-${scoreClass}" role="progressbar" style="width: ${typeData.average_score}%"></div>
                                    </div>
                                    <span>${typeData.average_score}%</span>
                                </div>` : 
                                '<span class="text-muted">-</span>'
                            }
                        </td>
                        <td>${typeData.min_score || '-'}</td>
                        <td>${typeData.max_score || '-'}</td>
                    </tr>
                `;
            });

            html += `
                    </tbody>
                </table>
            `;

            container.innerHTML = html;
        }

        // Render assessment statistics data
        function renderAssessmentStats(assessmentStats) {
            const container = document.getElementById('assessmentStatsContainer');

            if (!assessmentStats || assessmentStats.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No assessment statistics available for the selected filters.</div>';
                return;
            }

            let html = '';

            assessmentStats.forEach(subject => {
                html += `
                <h4 class="mt-3 mb-3">${subject.subject_name}</h4>
                <div class="mb-3">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h5 class="card-title">Subject Summary</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <p><strong>Total Students:</strong> ${subject.total_students}</p>
                                </div>
                                <div class="col-md-4">
                                    <p><strong>Students Taken:</strong> ${subject.students_taken}</p>
                                </div>
                                <div class="col-md-4">
                                    <p><strong>Students Not Taken:</strong> ${subject.students_not_taken}</p>
                                </div>
                            </div>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: ${(subject.students_taken / subject.total_students) * 100}%">
                                    ${Math.round((subject.students_taken / subject.total_students) * 100)}% Completed
                                </div>
                                <div class="progress-bar bg-danger" role="progressbar" 
                                     style="width: ${(subject.students_not_taken / subject.total_students) * 100}%">
                                    ${Math.round((subject.students_not_taken / subject.total_students) * 100)}% Pending
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Class</th>
                            <th>Level</th>
                            <th>Program</th>
                            <th>Total Students</th>
                            <th>Students Taken</th>
                            <th>Students Not Taken</th>
                            <th>Total Assessments</th>
                            <th>Completion Rate</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

                if (subject.class_stats.length === 0) {
                    html += `<tr><td colspan="8" class="text-center">No class statistics available for this subject.</td></tr>`;
                } else {
                    subject.class_stats.forEach(classStats => {
                        html += `
                        <tr>
                            <td>${classStats.class_name}</td>
                            <td><span class="badge bg-info">${classStats.level || 'N/A'}</span></td>
                            <td>${classStats.program_name}</td>
                            <td>${classStats.total_students}</td>
                            <td>${classStats.students_taken}</td>
                            <td>${classStats.students_not_taken}</td>
                            <td>${classStats.total_assessments}</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="progress flex-grow-1 me-2" style="height: 10px;">
                                        <div class="progress-bar ${parseInt(classStats.completion_rate) < 50 ? 'bg-danger' : 'bg-success'}" 
                                             role="progressbar" style="width: ${classStats.completion_rate}"></div>
                                    </div>
                                    <span>${classStats.completion_rate}</span>
                                </div>
                            </td>
                        </tr>
                    `;
                    });
                }

                html += `
                    </tbody>
                </table>
            `;
            });

            container.innerHTML = html;
        }

        // Load initial data if filters are pre-selected
        if (document.getElementById('semester_id').value) {
            loadPerformanceData();
        }

        // Export to PDF button handler
        document.getElementById('exportPdfBtn').addEventListener('click', function() {
            const level = document.getElementById('level').value;
            const classId = document.getElementById('class_id').value;
            const subjectId = document.getElementById('subject_id').value;
            const semesterId = document.getElementById('semester_id').value;

            // Build query string
            let queryString = '';
            if (level) queryString += `&level=${encodeURIComponent(level)}`;
            if (classId) queryString += `&class_id=${classId}`;
            if (subjectId) queryString += `&subject_id=${subjectId}`;
            if (semesterId) queryString += `&semester_id=${semesterId}`;

            // Redirect to export PDF script
            window.location.href = `export_performance_pdf.php?${queryString.substring(1)}`;
        });
    });
</script>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>