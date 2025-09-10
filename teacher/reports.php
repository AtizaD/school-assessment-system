<?php
// teacher/reports.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

// Ensure user is logged in and has teacher role
requireRole('teacher');

// Start output buffering
ob_start();

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get teacher information
    $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();

    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }

    // Get current/selected semester using shared component
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);
    $selectedSemester = $currentSemester['semester_id'];

    // Get all classes taught by this teacher (assignments are permanent)
    $stmt = $db->prepare(
        "SELECT DISTINCT c.class_id, c.class_name, p.program_name
         FROM teacherclassassignments tca
         JOIN classes c ON tca.class_id = c.class_id
         JOIN programs p ON c.program_id = p.program_id
         WHERE tca.teacher_id = ?
         ORDER BY p.program_name, c.class_name"
    );
    $stmt->execute([$teacherInfo['teacher_id']]);
    $teacherClasses = $stmt->fetchAll();

    // Get selected class or default to first class
    $selectedClass = isset($_GET['class']) ? intval($_GET['class']) : 
                    (!empty($teacherClasses) ? $teacherClasses[0]['class_id'] : null);

    // Get all subjects taught by this teacher to the selected class
    $subjects = [];
    if ($selectedClass) {
        $stmt = $db->prepare(
            "SELECT DISTINCT s.subject_id, s.subject_name
             FROM teacherclassassignments tca
             JOIN subjects s ON tca.subject_id = s.subject_id
             WHERE tca.teacher_id = ? AND tca.class_id = ?
             ORDER BY s.subject_name"
        );
        $stmt->execute([$teacherInfo['teacher_id'], $selectedClass]);
        $subjects = $stmt->fetchAll();
    }

    // Get selected subject or default to first subject
    $selectedSubject = isset($_GET['subject']) ? intval($_GET['subject']) : 
                      (!empty($subjects) ? $subjects[0]['subject_id'] : null);

    // Get report type from request
    $reportType = isset($_GET['report_type']) ? sanitizeInput($_GET['report_type']) : 'class_performance';
    $validReportTypes = ['class_performance', 'student_performance', 'assessment_analysis', 'subject_comparison'];
    
    if (!in_array($reportType, $validReportTypes)) {
        $reportType = 'class_performance';
    }

    // Initialize report data
    $reportData = [];
    $chartData = [];
    $selectedClassName = '';
    $selectedSubjectName = '';

    // Get class and subject names if selected
    if ($selectedClass) {
        foreach ($teacherClasses as $class) {
            if ($class['class_id'] == $selectedClass) {
                $selectedClassName = $class['class_name'];
                break;
            }
        }
    }

    if ($selectedSubject) {
        foreach ($subjects as $subject) {
            if ($subject['subject_id'] == $selectedSubject) {
                $selectedSubjectName = $subject['subject_name'];
                break;
            }
        }
    }

    // Process report based on type
    if ($selectedClass && $selectedSubject && $selectedSemester) {
        switch ($reportType) {
            case 'class_performance':
                // Get class performance report
                $stmt = $db->prepare(
                    "SELECT 
                        s.student_id,
                        s.first_name,
                        s.last_name,
                        COUNT(DISTINCT a.assessment_id) as total_assessments,
                        COUNT(DISTINCT aa.assessment_id) as attempted_assessments,
                        COALESCE(AVG(r.score), 0) as average_score,
                        MAX(r.score) as highest_score,
                        MIN(CASE WHEN r.score > 0 THEN r.score END) as lowest_score
                     FROM students s
                     JOIN assessmentclasses ac ON ac.class_id = s.class_id
                     JOIN assessments a ON ac.assessment_id = a.assessment_id 
                        AND a.semester_id = ?
                     LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
                     LEFT JOIN assessmentattempts aa ON a.assessment_id = aa.assessment_id 
                        AND aa.student_id = s.student_id
                     LEFT JOIN results r ON a.assessment_id = r.assessment_id 
                        AND r.student_id = s.student_id 
                        AND r.status = 'completed'
                     WHERE s.class_id = ? 
                        AND ac.subject_id = ?
                     GROUP BY s.student_id, s.first_name, s.last_name
                     ORDER BY average_score DESC"
                );
                $stmt->execute([$selectedSemester, $selectedClass, $selectedSubject]);
                $reportData = $stmt->fetchAll();

                // Get assessment type breakdown for this class and subject
                $stmt = $db->prepare(
                    "SELECT 
                        COALESCE(at.type_name, 'Unassigned') as type_name,
                        COUNT(DISTINCT a.assessment_id) as total_assessments,
                        COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_results,
                        COALESCE(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 0) as average_score
                     FROM assessments a
                     JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                     LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
                     LEFT JOIN results r ON a.assessment_id = r.assessment_id
                     LEFT JOIN students s ON r.student_id = s.student_id AND s.class_id = ?
                     WHERE a.semester_id = ? AND ac.class_id = ? AND ac.subject_id = ?
                     GROUP BY COALESCE(at.type_name, 'Unassigned'), at.type_id
                     ORDER BY COALESCE(at.type_name, 'Unassigned')"
                );
                $stmt->execute([$selectedClass, $selectedSemester, $selectedClass, $selectedSubject]);
                $assessmentTypeBreakdown = $stmt->fetchAll();

                // Prepare chart data
                $chartData = [
                    'labels' => [],
                    'scores' => [],
                    'assessment_types' => [
                        'labels' => [],
                        'scores' => []
                    ]
                ];
                
                foreach ($reportData as $student) {
                    $chartData['labels'][] = $student['first_name'] . ' ' . $student['last_name'];
                    $chartData['scores'][] = number_format($student['average_score'], 1);
                }

                // Add assessment type data to chart
                foreach ($assessmentTypeBreakdown as $typeData) {
                    $chartData['assessment_types']['labels'][] = $typeData['type_name'];
                    $chartData['assessment_types']['scores'][] = number_format($typeData['average_score'], 1);
                }
                break;

            case 'student_performance':
                // Get list of students in class
                $stmt = $db->prepare(
                    "SELECT student_id, first_name, last_name 
                     FROM students 
                     WHERE class_id = ? 
                     ORDER BY last_name, first_name"
                );
                $stmt->execute([$selectedClass]);
                $students = $stmt->fetchAll();

                // Get selected student or default to first
                $selectedStudent = isset($_GET['student']) ? intval($_GET['student']) : 
                                  (!empty($students) ? $students[0]['student_id'] : null);

                // Get student performance over time
                if ($selectedStudent) {
                    $stmt = $db->prepare(
                        "SELECT 
                            a.assessment_id,
                            a.title,
                            a.date,
                            r.score,
                            aa.start_time,
                            aa.end_time,
                            TIMESTAMPDIFF(MINUTE, aa.start_time, COALESCE(aa.end_time, NOW())) as duration_minutes,
                            at.type_name,
                            at.type_id
                         FROM assessments a
                         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                         LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
                         LEFT JOIN assessmentattempts aa ON a.assessment_id = aa.assessment_id 
                            AND aa.student_id = ?
                         LEFT JOIN results r ON a.assessment_id = r.assessment_id 
                            AND r.student_id = ?
                         WHERE a.semester_id = ? 
                            AND ac.class_id = ? 
                            AND ac.subject_id = ?
                         ORDER BY a.date"
                    );
                    $stmt->execute([$selectedStudent, $selectedStudent, $selectedSemester, $selectedClass, $selectedSubject]);
                    $reportData = $stmt->fetchAll();

                    // Get student info
                    $stmt = $db->prepare(
                        "SELECT first_name, last_name 
                         FROM students 
                         WHERE student_id = ?"
                    );
                    $stmt->execute([$selectedStudent]);
                    $studentInfo = $stmt->fetch();
                    
                    // Prepare chart data
                    $chartData = [
                        'labels' => [],
                        'scores' => [],
                        'durations' => [],
                        'types' => []
                    ];
                    
                    foreach ($reportData as $assessment) {
                        $chartData['labels'][] = $assessment['title'];
                        $chartData['scores'][] = $assessment['score'] ?? 0;
                        $chartData['durations'][] = $assessment['duration_minutes'] ?? 0;
                        $chartData['types'][] = $assessment['type_name'] ?? 'Unassigned';
                    }
                    
                    // Add student info to chart data
                    $chartData['student_name'] = $studentInfo['first_name'] . ' ' . $studentInfo['last_name'];
                    $chartData['students'] = $students;
                    $chartData['selected_student'] = $selectedStudent;
                }
                break;

            case 'assessment_analysis':
                // Get list of assessments for this subject
                $stmt = $db->prepare(
                    "SELECT 
                        a.assessment_id, 
                        a.title,
                        a.date,
                        at.type_name
                     FROM assessments a
                     JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                     LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
                     WHERE a.semester_id = ? 
                        AND ac.class_id = ? 
                        AND ac.subject_id = ?
                     ORDER BY a.date DESC"
                );
                $stmt->execute([$selectedSemester, $selectedClass, $selectedSubject]);
                $assessments = $stmt->fetchAll();

                // Get selected assessment or default to first
                $selectedAssessment = isset($_GET['assessment']) ? intval($_GET['assessment']) : 
                                     (!empty($assessments) ? $assessments[0]['assessment_id'] : null);

                // Get assessment analysis
                if ($selectedAssessment) {
                    // Get assessment details
                    $stmt = $db->prepare(
                        "SELECT 
                            a.title,
                            a.description,
                            a.date,
                            a.start_time,
                            a.end_time,
                            a.status,
                            COUNT(DISTINCT q.question_id) as total_questions,
                            SUM(q.max_score) as total_points,
                            at.type_name,
                            at.weight_percentage
                         FROM assessments a
                         LEFT JOIN questions q ON a.assessment_id = q.assessment_id
                         LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
                         WHERE a.assessment_id = ?"
                    );
                    $stmt->execute([$selectedAssessment]);
                    $assessmentDetails = $stmt->fetch();

                    // Get question-level analysis
                    $stmt = $db->prepare(
                        "SELECT 
                            q.question_id,
                            q.question_text,
                            q.max_score,
                            COUNT(DISTINCT sa.student_id) as attempts,
                            AVG(sa.score) as avg_score,
                            MIN(sa.score) as min_score,
                            MAX(sa.score) as max_score
                         FROM questions q
                         LEFT JOIN studentanswers sa ON q.question_id = sa.question_id
                         WHERE q.assessment_id = ?
                         GROUP BY q.question_id
                         ORDER BY q.question_id"
                    );
                    $stmt->execute([$selectedAssessment]);
                    $questionAnalysis = $stmt->fetchAll();

                    // Get student performance on this assessment
                    $stmt = $db->prepare(
                        "SELECT 
                            s.student_id,
                            s.first_name,
                            s.last_name,
                            r.score,
                            aa.start_time,
                            aa.end_time,
                            TIMESTAMPDIFF(MINUTE, aa.start_time, COALESCE(aa.end_time, NOW())) as duration_minutes
                         FROM students s
                         LEFT JOIN assessmentattempts aa ON s.student_id = aa.student_id 
                            AND aa.assessment_id = ?
                         LEFT JOIN results r ON s.student_id = r.student_id 
                            AND r.assessment_id = ?
                         WHERE s.class_id = ?
                         ORDER BY r.score DESC"
                    );
                    $stmt->execute([$selectedAssessment, $selectedAssessment, $selectedClass]);
                    $studentPerformance = $stmt->fetchAll();

                    // Compile report data
                    $reportData = [
                        'assessment_details' => $assessmentDetails,
                        'question_analysis' => $questionAnalysis,
                        'student_performance' => $studentPerformance,
                        'assessments' => $assessments,
                        'selected_assessment' => $selectedAssessment
                    ];

                    // Prepare chart data
                    $chartData = [
                        'score_distribution' => [
                            'labels' => ['0-19', '20-39', '40-59', '60-79', '80-100'],
                            'counts' => [0, 0, 0, 0, 0]
                        ],
                        'question_scores' => [
                            'labels' => [],
                            'avg_scores' => [],
                            'max_scores' => []
                        ]
                    ];
                    
                    // Score distribution
                    foreach ($studentPerformance as $student) {
                        if ($student['score'] !== null) {
                            $score = $student['score'];
                            if ($score < 20) $chartData['score_distribution']['counts'][0]++;
                            else if ($score < 40) $chartData['score_distribution']['counts'][1]++;
                            else if ($score < 60) $chartData['score_distribution']['counts'][2]++;
                            else if ($score < 80) $chartData['score_distribution']['counts'][3]++;
                            else $chartData['score_distribution']['counts'][4]++;
                        }
                    }
                    
                    // Question scores
                    foreach ($questionAnalysis as $idx => $question) {
                        $chartData['question_scores']['labels'][] = 'Q' . ($idx + 1);
                        $chartData['question_scores']['avg_scores'][] = number_format($question['avg_score'], 1);
                        $chartData['question_scores']['max_scores'][] = $question['max_score'];
                    }
                }
                break;

            case 'subject_comparison':
                // Get all subjects for this class
                $stmt = $db->prepare(
                    "SELECT 
                        s.subject_id, 
                        s.subject_name
                     FROM subjects s
                     JOIN classsubjects cs ON s.subject_id = cs.subject_id
                     WHERE cs.class_id = ?
                     ORDER BY s.subject_name"
                );
                $stmt->execute([$selectedClass]);
                $allSubjects = $stmt->fetchAll();

                // Get overall class performance across subjects
                $stmt = $db->prepare(
                    "SELECT 
                        s.subject_id,
                        s.subject_name,
                        COUNT(DISTINCT a.assessment_id) as total_assessments,
                        COUNT(DISTINCT aa.assessment_id) as attempted_assessments,
                        COALESCE(AVG(r.score), 0) as average_score,
                        COUNT(DISTINCT CASE WHEN r.score >= 50 THEN r.result_id END) as passed_assessments
                     FROM subjects s
                     JOIN assessmentclasses ac ON s.subject_id = ac.subject_id
                     JOIN assessments a ON ac.assessment_id = a.assessment_id 
                        AND a.semester_id = ?
                     LEFT JOIN assessmentattempts aa ON a.assessment_id = aa.assessment_id
                     LEFT JOIN results r ON a.assessment_id = r.assessment_id 
                        AND r.status = 'completed'
                     LEFT JOIN students st ON r.student_id = st.student_id
                     WHERE ac.class_id = ? AND st.class_id = ?
                     GROUP BY s.subject_id, s.subject_name
                     ORDER BY s.subject_name"
                );
                $stmt->execute([$selectedSemester, $selectedClass, $selectedClass]);
                $reportData = $stmt->fetchAll();

                // Prepare chart data
                $chartData = [
                    'labels' => [],
                    'avg_scores' => [],
                    'completion_rates' => []
                ];
                
                foreach ($reportData as $subject) {
                    $chartData['labels'][] = $subject['subject_name'];
                    $chartData['avg_scores'][] = number_format($subject['average_score'], 1);
                    $completionRate = $subject['total_assessments'] > 0 ? 
                        ($subject['attempted_assessments'] / $subject['total_assessments']) * 100 : 0;
                    $chartData['completion_rates'][] = number_format($completionRate, 1);
                }
                break;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Reports page error: " . $e->getMessage());
}

$pageTitle = 'Academic Reports';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    /* Custom styles for Reports page */
    :root {
        --primary-yellow: #ffd700;
        --dark-yellow: #ffcc00;
        --light-yellow: #fff9e6;
        --primary-black: #000000;
        --primary-white: #ffffff;
    }

    .report-container {
        padding: 1rem;
        max-width: 100%;
        margin: 0 auto;
    }

    .report-header {
        background: linear-gradient(90deg, var(--primary-black) 0%, var(--primary-yellow) 100%);
        padding: 2rem;
        border-radius: 10px;
        color: var(--primary-white);
        margin-bottom: 2rem;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .filter-container {
        background-color: var(--primary-white);
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        border-left: 4px solid var(--primary-yellow);
    }

    .report-card {
        background-color: var(--primary-white);
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .btn-report-type {
        padding: 0.5rem 1rem;
        border: 1px solid rgba(0, 0, 0, 0.1);
        background-color: var(--primary-white);
        border-radius: 5px;
        transition: all 0.2s ease;
    }

    .btn-report-type.active {
        background-color: var(--primary-yellow);
        color: var(--primary-black);
        font-weight: 500;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .table-report {
        width: 100%;
        border-collapse: collapse;
    }

    .table-report th {
        background-color: var(--light-yellow);
        font-weight: 500;
        text-align: left;
        padding: 0.75rem;
        border-bottom: 2px solid var(--primary-yellow);
    }

    .table-report td {
        padding: 0.75rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .table-report tr:hover {
        background-color: rgba(255, 215, 0, 0.05);
    }

    .chart-container {
        position: relative;
        height: 300px;
        margin-bottom: 2rem;
    }

    .score-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 500;
    }

    .score-badge.high {
        background-color: #d4edda;
        color: #155724;
    }

    .score-badge.mid {
        background-color: #fff3cd;
        color: #856404;
    }

    .score-badge.low {
        background-color: #f8d7da;
        color: #721c24;
    }

    .btn-custom {
        background: linear-gradient(45deg, var(--primary-black), var(--primary-yellow));
        border: none;
        color: var(--primary-white);
        padding: 0.5rem 1.5rem;
        border-radius: 5px;
        transition: all 0.2s ease;
    }

    .btn-custom:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        color: var(--primary-white);
    }

    @media (max-width: 768px) {
        .report-container {
            padding: 0.5rem;
        }

        .report-header {
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .filter-container,
        .report-card {
            padding: 1rem;
        }

        .chart-container {
            height: 250px;
        }
    }
</style>

<div class="report-container">
    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>
        <div class="report-header">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h3 mb-0">Academic Reports</h1>
                <a href="dashboard.php" class="btn btn-custom btn-sm">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
            <p class="mb-0">Generate and analyze student performance reports</p>
            <p class="mb-0 mt-2">
                <i class="fas fa-calendar me-1"></i>
                Semester: <?php echo htmlspecialchars($currentSemester['semester_name']); ?>
            </p>
        </div>

        <!-- Report Filters -->
        <div class="filter-container">
            <form id="reportForm" method="GET" action="reports.php" class="row g-3 align-items-end">
                <!-- Hidden semester field to maintain semester context from global header -->
                <input type="hidden" name="semester" value="<?php echo $selectedSemester; ?>">
                
                <div class="col-md-4">
                    <label for="class" class="form-label">Class</label>
                    <select class="form-select" name="class" id="class" onchange="this.form.submit()">
                        <?php foreach ($teacherClasses as $class): ?>
                            <option value="<?php echo $class['class_id']; ?>" 
                                <?php echo ($class['class_id'] == $selectedClass) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="subject" class="form-label">Subject</label>
                    <select class="form-select" name="subject" id="subject" onchange="this.form.submit()">
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['subject_id']; ?>" 
                                <?php echo ($subject['subject_id'] == $selectedSubject) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="report_type" class="form-label">Report Type</label>
                    <select class="form-select" name="report_type" id="report_type" onchange="this.form.submit()">
                        <option value="class_performance" <?php echo ($reportType == 'class_performance') ? 'selected' : ''; ?>>
                            Class Performance
                        </option>
                        <option value="student_performance" <?php echo ($reportType == 'student_performance') ? 'selected' : ''; ?>>
                            Student Performance
                        </option>
                        <option value="assessment_analysis" <?php echo ($reportType == 'assessment_analysis') ? 'selected' : ''; ?>>
                            Assessment Analysis
                        </option>
                        <option value="subject_comparison" <?php echo ($reportType == 'subject_comparison') ? 'selected' : ''; ?>>
                            Subject Comparison
                        </option>
                    </select>
                </div>
                
                <?php if ($reportType == 'student_performance' && !empty($chartData['students'])): ?>
                <div class="col-md-3">
                    <label for="student" class="form-label">Student</label>
                    <select class="form-select" name="student" id="student" onchange="this.form.submit()">
                        <?php foreach ($chartData['students'] as $student): ?>
                            <option value="<?php echo $student['student_id']; ?>" 
                                <?php echo ($student['student_id'] == $chartData['selected_student']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if ($reportType == 'assessment_analysis' && !empty($reportData['assessments'])): ?>
                <div class="col-md-3">
                    <label for="assessment" class="form-label">Assessment</label>
                    <select class="form-select" name="assessment" id="assessment" onchange="this.form.submit()">
                        <?php foreach ($reportData['assessments'] as $assessment): ?>
                            <option value="<?php echo $assessment['assessment_id']; ?>" 
                                <?php echo ($assessment['assessment_id'] == $reportData['selected_assessment']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($assessment['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Report Content -->
        <?php if (!empty($reportData) || !empty($chartData)): ?>
            <?php if ($reportType == 'class_performance'): ?>
                <div class="report-card">
                    <h5 class="mb-4">
                        <i class="fas fa-users me-2"></i>
                        Class Performance: <?php echo htmlspecialchars($selectedClassName); ?> - <?php echo htmlspecialchars($selectedSubjectName); ?>
                    </h5>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="classPerformanceChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="assessmentTypeChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($assessmentTypeBreakdown)): ?>
                    <div class="mb-4">
                        <h6 class="mb-3">Performance by Assessment Type</h6>
                        <div class="table-responsive">
                            <table class="table-report">
                                <thead>
                                    <tr>
                                        <th>Assessment Type</th>
                                        <th>Total Assessments</th>
                                        <th>Completed Results</th>
                                        <th>Average Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assessmentTypeBreakdown as $typeData): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($typeData['type_name']); ?></td>
                                            <td><?php echo $typeData['total_assessments']; ?></td>
                                            <td><?php echo $typeData['completed_results']; ?></td>
                                            <td>
                                                <?php
                                                $score = $typeData['average_score'];
                                                $scoreClass = $score >= 70 ? 'high' : ($score >= 50 ? 'mid' : 'low');
                                                ?>
                                                <span class="score-badge <?php echo $scoreClass; ?>">
                                                    <?php echo number_format($score, 1); ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="table-responsive">
                        <table class="table-report">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Assessments</th>
                                    <th>Completed</th>
                                    <th>Average Score</th>
                                    <th>Highest Score</th>
                                    <th>Lowest Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData as $student): ?>
                                    <tr>
                                        <td>
                                            <a href="student_profile.php?id=<?php echo $student['student_id']; ?>">
                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo $student['total_assessments']; ?></td>
                                        <td><?php echo $student['attempted_assessments']; ?></td>
                                        <td>
                                            <?php
                                            $score = $student['average_score'];
                                            $scoreClass = $score >= 70 ? 'high' : ($score >= 50 ? 'mid' : 'low');
                                            ?>
                                            <span class="score-badge <?php echo $scoreClass; ?>">
                                                <?php echo number_format($score, 1); ?>%
                                            </span>
                                        </td>
                                        <td><?php echo is_null($student['highest_score']) ? 'N/A' : number_format($student['highest_score'], 1) . '%'; ?></td>
                                        <td><?php echo is_null($student['lowest_score']) ? 'N/A' : number_format($student['lowest_score'], 1) . '%'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-end mt-4">
                        <button class="btn btn-custom" onclick="exportReport('class-performance')">
                            <i class="fas fa-file-export me-2"></i>Export Report
                        </button>
                    </div>
                </div>
            <?php elseif ($reportType == 'student_performance'): ?>
                <div class="report-card">
                    <h5 class="mb-4">
                        <i class="fas fa-user-graduate me-2"></i>
                        Student Performance: <?php echo htmlspecialchars($chartData['student_name']); ?> - <?php echo htmlspecialchars($selectedSubjectName); ?></h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="studentScoreChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="studentTimeChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive mt-4">
                        <table class="table-report">
                            <thead>
                                <tr>
                                    <th>Assessment</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Score</th>
                                    <th>Duration (min)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData as $assessment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($assessment['title']); ?></td>
                                        <td>
                                            <?php if ($assessment['type_name']): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($assessment['type_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($assessment['date'])); ?></td>
                                        <td>
                                            <?php if (isset($assessment['score'])): ?>
                                                <?php
                                                $score = $assessment['score'];
                                                $scoreClass = $score >= 70 ? 'high' : ($score >= 50 ? 'mid' : 'low');
                                                ?>
                                                <span class="score-badge <?php echo $scoreClass; ?>">
                                                    <?php echo number_format($score, 1); ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">Not submitted</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (isset($assessment['duration_minutes'])): ?>
                                                <?php echo $assessment['duration_minutes']; ?> min
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (isset($assessment['score'])): ?>
                                                <span class="badge bg-success">Completed</span>
                                            <?php elseif (isset($assessment['start_time']) && !isset($assessment['end_time'])): ?>
                                                <span class="badge bg-warning">In Progress</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Not Attempted</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-end mt-4">
                        <button class="btn btn-custom" onclick="exportReport('student-performance')">
                            <i class="fas fa-file-export me-2"></i>Export Report
                        </button>
                    </div>
                </div>
            <?php elseif ($reportType == 'assessment_analysis'): ?>
                <div class="report-card">
                    <h5 class="mb-4">
                        <i class="fas fa-clipboard-check me-2"></i>
                        Assessment Analysis: <?php echo htmlspecialchars($reportData['assessment_details']['title']); ?>
                    </h5>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Assessment Details</h6>
                                    <table class="table">
                                        <tr>
                                            <td><strong>Date:</strong></td>
                                            <td><?php echo date('M d, Y', strtotime($reportData['assessment_details']['date'])); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Time:</strong></td>
                                            <td>
                                                <?php 
                                                echo date('h:i A', strtotime($reportData['assessment_details']['start_time'])); 
                                                echo ' - ';
                                                echo date('h:i A', strtotime($reportData['assessment_details']['end_time'])); 
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Status:</strong></td>
                                            <td>
                                                <?php 
                                                $statusClass = '';
                                                switch($reportData['assessment_details']['status']) {
                                                    case 'pending': $statusClass = 'bg-warning'; break;
                                                    case 'completed': $statusClass = 'bg-success'; break;
                                                    case 'archived': $statusClass = 'bg-secondary'; break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>">
                                                    <?php echo ucfirst($reportData['assessment_details']['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Questions:</strong></td>
                                            <td><?php echo $reportData['assessment_details']['total_questions']; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Total Points:</strong></td>
                                            <td><?php echo $reportData['assessment_details']['total_points']; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Type:</strong></td>
                                            <td>
                                                <?php if ($reportData['assessment_details']['type_name']): ?>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($reportData['assessment_details']['type_name']); ?></span>
                                                    <?php if ($reportData['assessment_details']['weight_percentage']): ?>
                                                        <small class="text-muted ms-2">(<?php echo $reportData['assessment_details']['weight_percentage']; ?>% weight)</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Score Distribution</h6>
                                    <div class="chart-container" style="position: relative; height:200px;">
                                        <canvas id="scoreDistributionChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Question Analysis</h6>
                                    <div class="chart-container">
                                        <canvas id="questionAnalysisChart"></canvas>
                                    </div>
                                    
                                    <div class="table-responsive mt-4">
                                        <table class="table-report">
                                            <thead>
                                                <tr>
                                                    <th>Question</th>
                                                    <th>Max Score</th>
                                                    <th>Avg Score</th>
                                                    <th>Min Score</th>
                                                    <th>Max Score</th>
                                                    <th>Attempts</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reportData['question_analysis'] as $idx => $question): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="text-truncate" style="max-width: 300px;" title="<?php echo htmlspecialchars($question['question_text']); ?>">
                                                                Q<?php echo ($idx + 1); ?>: <?php echo htmlspecialchars(substr($question['question_text'], 0, 50)) . (strlen($question['question_text']) > 50 ? '...' : ''); ?>
                                                            </div>
                                                        </td>
                                                        <td><?php echo $question['max_score']; ?></td>
                                                        <td>
                                                            <?php 
                                                            $avgScore = $question['avg_score'];
                                                            $percentage = ($avgScore / $question['max_score']) * 100;
                                                            $scoreClass = $percentage >= 70 ? 'high' : ($percentage >= 50 ? 'mid' : 'low');
                                                            ?>
                                                            <span class="score-badge <?php echo $scoreClass; ?>">
                                                                <?php echo number_format($avgScore, 1); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo number_format($question['min_score'], 1); ?></td>
                                                        <td><?php echo number_format($question['max_score'], 1); ?></td>
                                                        <td><?php echo $question['attempts']; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Student Performance</h6>
                                    
                                    <div class="table-responsive">
                                        <table class="table-report">
                                            <thead>
                                                <tr>
                                                    <th>Student Name</th>
                                                    <th>Score</th>
                                                    <th>Duration (min)</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reportData['student_performance'] as $student): ?>
                                                    <tr>
                                                        <td>
                                                            <a href="student_profile.php?id=<?php echo $student['student_id']; ?>">
                                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                            </a>
                                                        </td>
                                                        <td>
                                                            <?php if (isset($student['score'])): ?>
                                                                <?php
                                                                $score = $student['score'];
                                                                $scoreClass = $score >= 70 ? 'high' : ($score >= 50 ? 'mid' : 'low');
                                                                ?>
                                                                <span class="score-badge <?php echo $scoreClass; ?>">
                                                                    <?php echo number_format($score, 1); ?>%
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="text-muted">Not submitted</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (isset($student['duration_minutes'])): ?>
                                                                <?php echo $student['duration_minutes']; ?> min
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (isset($student['score'])): ?>
                                                                <span class="badge bg-success">Completed</span>
                                                            <?php elseif (isset($student['start_time']) && !isset($student['end_time'])): ?>
                                                                <span class="badge bg-warning">In Progress</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Not Attempted</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end mt-4">
                        <button class="btn btn-custom" onclick="exportReport('assessment-analysis')">
                            <i class="fas fa-file-export me-2"></i>Export Report
                        </button>
                    </div>
                </div>
            <?php elseif ($reportType == 'subject_comparison'): ?>
                <div class="report-card">
                    <h5 class="mb-4">
                        <i class="fas fa-chart-line me-2"></i>
                        Subject Comparison: <?php echo htmlspecialchars($selectedClassName); ?>
                    </h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="subjectScoreChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="subjectCompletionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive mt-4">
                        <table class="table-report">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Total Assessments</th>
                                    <th>Attempted Assessments</th>
                                    <th>Completion Rate</th>
                                    <th>Average Score</th>
                                    <th>Pass Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData as $subject): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                        <td><?php echo $subject['total_assessments']; ?></td>
                                        <td><?php echo $subject['attempted_assessments']; ?></td>
                                        <td>
                                            <?php
                                            $completionRate = $subject['total_assessments'] > 0 ? 
                                                ($subject['attempted_assessments'] / $subject['total_assessments']) * 100 : 0;
                                            $completionClass = $completionRate >= 70 ? 'high' : ($completionRate >= 50 ? 'mid' : 'low');
                                            ?>
                                            <span class="score-badge <?php echo $completionClass; ?>">
                                                <?php echo number_format($completionRate, 1); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $score = $subject['average_score'];
                                            $scoreClass = $score >= 70 ? 'high' : ($score >= 50 ? 'mid' : 'low');
                                            ?>
                                            <span class="score-badge <?php echo $scoreClass; ?>">
                                                <?php echo number_format($score, 1); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $passRate = $subject['attempted_assessments'] > 0 ? 
                                                ($subject['passed_assessments'] / $subject['attempted_assessments']) * 100 : 0;
                                            $passClass = $passRate >= 70 ? 'high' : ($passRate >= 50 ? 'mid' : 'low');
                                            ?>
                                            <span class="score-badge <?php echo $passClass; ?>">
                                                <?php echo number_format($passRate, 1); ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-end mt-4">
                        <button class="btn btn-custom" onclick="exportReport('subject-comparison')">
                            <i class="fas fa-file-export me-2"></i>Export Report
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Select a class and subject to view reports.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- JavaScript for Charts -->
<script src="<?php echo BASE_URL; ?>/assets/js/external/chart-4.4.1.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Class Performance Chart
    <?php if ($reportType == 'class_performance' && !empty($chartData)): ?>
    var classCtx = document.getElementById('classPerformanceChart').getContext('2d');
    var classChart = new Chart(classCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartData['labels']); ?>,
            datasets: [{
                label: 'Average Score (%)',
                data: <?php echo json_encode($chartData['scores']); ?>,
                backgroundColor: 'rgba(255, 215, 0, 0.7)',
                borderColor: 'rgba(255, 215, 0, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            },
            barThickness: 20
        }
    });

    // Assessment Type Performance Chart
    <?php if (!empty($chartData['assessment_types']['labels'])): ?>
    var typeCtx = document.getElementById('assessmentTypeChart').getContext('2d');
    var typeChart = new Chart(typeCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartData['assessment_types']['labels']); ?>,
            datasets: [{
                label: 'Average Score (%)',
                data: <?php echo json_encode($chartData['assessment_types']['scores']); ?>,
                backgroundColor: 'rgba(0, 0, 0, 0.7)',
                borderColor: 'rgba(0, 0, 0, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Performance by Assessment Type'
                }
            },
            barThickness: 30
        }
    });
    <?php endif; ?>
    <?php endif; ?>

    // Student Performance Charts
    <?php if ($reportType == 'student_performance' && !empty($chartData['labels'])): ?>
    var scoreCtx = document.getElementById('studentScoreChart').getContext('2d');
    var scoreChart = new Chart(scoreCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartData['labels']); ?>,
            datasets: [{
                label: 'Score (%)',
                data: <?php echo json_encode($chartData['scores']); ?>,
                backgroundColor: 'rgba(255, 215, 0, 0.2)',
                borderColor: 'rgba(255, 215, 0, 1)',
                borderWidth: 2,
                tension: 0.1,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Assessment Scores'
                }
            }
        }
    });

    var timeCtx = document.getElementById('studentTimeChart').getContext('2d');
    var timeChart = new Chart(timeCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartData['labels']); ?>,
            datasets: [{
                label: 'Time Spent (minutes)',
                data: <?php echo json_encode($chartData['durations']); ?>,
                backgroundColor: 'rgba(0, 0, 0, 0.7)',
                borderColor: 'rgba(0, 0, 0, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value + ' min';
                        }
                    }
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Time Spent on Assessments'
                }
            },
            barThickness: 20
        }
    });
    <?php endif; ?>

    // Assessment Analysis Charts
    <?php if ($reportType == 'assessment_analysis' && !empty($chartData)): ?>
    var distributionCtx = document.getElementById('scoreDistributionChart').getContext('2d');
    var distributionChart = new Chart(distributionCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartData['score_distribution']['labels']); ?>,
            datasets: [{
                label: 'Number of Students',
                data: <?php echo json_encode($chartData['score_distribution']['counts']); ?>,
                backgroundColor: 'rgba(255, 215, 0, 0.7)',
                borderColor: 'rgba(255, 215, 0, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Score Range (%)'
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            },
            barThickness: 30
        }
    });

    var questionCtx = document.getElementById('questionAnalysisChart').getContext('2d');
    var questionChart = new Chart(questionCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartData['question_scores']['labels']); ?>,
            datasets: [
                {
                    label: 'Average Score',
                    data: <?php echo json_encode($chartData['question_scores']['avg_scores']); ?>,
                    backgroundColor: 'rgba(255, 215, 0, 0.7)',
                    borderColor: 'rgba(255, 215, 0, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Maximum Possible',
                    data: <?php echo json_encode($chartData['question_scores']['max_scores']); ?>,
                    backgroundColor: 'rgba(0, 0, 0, 0.2)',
                    borderColor: 'rgba(0, 0, 0, 1)',
                    borderWidth: 1,
                    type: 'line'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Score'
                    }
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Question Performance Analysis'
                }
            },
            barThickness: 20
        }
    });
    <?php endif; ?>

    // Subject Comparison Charts
    <?php if ($reportType == 'subject_comparison' && !empty($chartData)): ?>
    var scoreCtx = document.getElementById('subjectScoreChart').getContext('2d');
    var scoreChart = new Chart(scoreCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartData['labels']); ?>,
            datasets: [{
                label: 'Average Score (%)',
                data: <?php echo json_encode($chartData['avg_scores']); ?>,
                backgroundColor: 'rgba(255, 215, 0, 0.7)',
                borderColor: 'rgba(255, 215, 0, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Average Score by Subject'
                }
            },
            indexAxis: 'y',
            barThickness: 20
        }
    });

    var completionCtx = document.getElementById('subjectCompletionChart').getContext('2d');
    var completionChart = new Chart(completionCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartData['labels']); ?>,
            datasets: [{
                label: 'Completion Rate (%)',
                data: <?php echo json_encode($chartData['completion_rates']); ?>,
                backgroundColor: 'rgba(0, 0, 0, 0.7)',
                borderColor: 'rgba(0, 0, 0, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Assessment Completion Rate by Subject'
                }
            },
            indexAxis: 'y',
            barThickness: 20
        }
    });
    <?php endif; ?>
});

// Export Report Function
function exportReport(reportType) {
    // Get the current form values
    const semester = document.getElementById('semester').value;
    const classId = document.getElementById('class').value;
    const subject = document.getElementById('subject').value;
    
    // Create export URL based on report type
    let exportUrl = `export_report.php?report_type=${reportType}&semester=${semester}&class=${classId}&subject=${subject}`;
    
    // Add additional parameters if needed
    if (reportType === 'student_performance' && document.getElementById('student')) {
        exportUrl += `&student=${document.getElementById('student').value}`;
    }
    
    if (reportType === 'assessment_analysis' && document.getElementById('assessment')) {
        exportUrl += `&assessment=${document.getElementById('assessment').value}`;
    }
    
    // Open in new window
    window.open(exportUrl, '_blank');
}
</script>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
// End output buffering and send response
ob_end_flush();
?>