<?php
// student/report_card.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('student');

$error = '';
$reportCardData = null;
$selectedSemester = null;

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get student ID from session
    $studentId = $_SESSION['user_id'];
    
    // Get student information with proper join
    $stmt = $db->prepare(
        "SELECT s.student_id FROM students s WHERE s.user_id = ?"
    );
    $stmt->execute([$studentId]);
    $studentInfo = $stmt->fetch();
    
    if (!$studentInfo) {
        throw new Exception('Student record not found');
    }
    
    $actualStudentId = $studentInfo['student_id'];

    // Get all semesters this student has participated in (regular class + special enrollments)
    $stmt = $db->prepare(
        "SELECT DISTINCT sem.semester_id, sem.semester_name, sem.start_date, sem.end_date
         FROM semesters sem
         JOIN assessments a ON sem.semester_id = a.semester_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         LEFT JOIN students s ON ac.class_id = s.class_id AND s.student_id = ?
         LEFT JOIN special_class sc ON ac.class_id = sc.class_id 
                                   AND ac.subject_id = sc.subject_id 
                                   AND sc.student_id = ? 
                                   AND sc.status = 'active'
         WHERE (s.student_id IS NOT NULL OR sc.sp_id IS NOT NULL)
         ORDER BY sem.start_date DESC"
    );
    $stmt->execute([$actualStudentId, $actualStudentId]);
    $semesters = $stmt->fetchAll();

    // Get current semester
    $stmt = $db->prepare(
        "SELECT semester_id, semester_name FROM semesters 
         WHERE CURDATE() BETWEEN start_date AND end_date 
         ORDER BY start_date DESC LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();

    // Process semester selection
    $selectedSemesterId = null;
    if (isset($_GET['semester'])) {
        $selectedSemesterId = intval($_GET['semester']);
    } elseif ($currentSemester) {
        $selectedSemesterId = $currentSemester['semester_id'];
    } elseif (!empty($semesters)) {
        $selectedSemesterId = $semesters[0]['semester_id'];
    }

    if ($selectedSemesterId) {
        $reportCardData = generateStudentReportCard($db, $actualStudentId, $selectedSemesterId);
        $selectedSemester = $selectedSemesterId;
    }

} catch (Exception $e) {
    logError("Student report card error: " . $e->getMessage());
    $error = "Error loading report card: " . $e->getMessage();
}

/**
 * Generate report card data for student
 */
function generateStudentReportCard($db, $studentId, $semesterId) {
    // Get student information
    $stmt = $db->prepare(
        "SELECT s.*, c.class_name, c.level, p.program_name
         FROM students s
         JOIN classes c ON s.class_id = c.class_id
         JOIN programs p ON c.program_id = p.program_id
         WHERE s.student_id = ?"
    );
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    // Get semester information
    $stmt = $db->prepare("SELECT * FROM semesters WHERE semester_id = ?");
    $stmt->execute([$semesterId]);
    $semester = $stmt->fetch();

    // Get subjects with assessment data (regular class + special enrollments)
    $stmt = $db->prepare(
        "SELECT DISTINCT 
            s.subject_id,
            s.subject_name,
            CASE 
                WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                ELSE ?
            END as assessment_class_id,
            CASE 
                WHEN sc.sp_id IS NOT NULL THEN 'special'
                ELSE 'regular'
            END as enrollment_type,
            sc.notes as special_notes,
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_assessments
         FROM subjects s
         LEFT JOIN special_class sc ON s.subject_id = sc.subject_id 
                                   AND sc.student_id = ? 
                                   AND sc.status = 'active'
         JOIN assessmentclasses ac ON s.subject_id = ac.subject_id 
                                  AND ac.class_id = CASE 
                                      WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                                      ELSE ?
                                  END
         JOIN assessments a ON ac.assessment_id = a.assessment_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
         WHERE (ac.class_id = ? OR sc.sp_id IS NOT NULL) AND a.semester_id = ?
         GROUP BY s.subject_id, s.subject_name, assessment_class_id, enrollment_type, sc.notes
         HAVING total_assessments > 0
         ORDER BY s.subject_name"
    );
    $stmt->execute([$student['class_id'], $studentId, $student['class_id'], $studentId, $student['class_id'], $semesterId]);
    $subjects = $stmt->fetchAll();

    $subjectResults = [];
    
    foreach ($subjects as $subject) {
        // Get assessment type breakdown
        $stmt = $db->prepare(
            "SELECT 
                COALESCE(at.type_name, 'Unassigned') as type_name,
                COALESCE(at.weight_percentage, 0) as weight_percentage,
                AVG(r.score) as average_score,
                COUNT(DISTINCT a.assessment_id) as total_assessments,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_assessments
             FROM assessments a
             JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
             LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
             LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
             WHERE ac.subject_id = ? AND ac.class_id = ? AND a.semester_id = ?
             GROUP BY COALESCE(at.type_name, 'Unassigned'), at.weight_percentage
             ORDER BY COALESCE(at.type_name, 'Unassigned')"
        );
        $stmt->execute([$studentId, $subject['subject_id'], $subject['assessment_class_id'], $semesterId]);
        $assessmentTypes = $stmt->fetchAll();

        // Calculate weighted score
        $totalWeightedScore = 0;
        $totalWeight = 0;
        $unweightedScores = [];
        
        foreach ($assessmentTypes as $type) {
            if ($type['average_score'] !== null) {
                if ($type['weight_percentage'] > 0) {
                    $totalWeightedScore += ($type['average_score'] * $type['weight_percentage'] / 100);
                    $totalWeight += $type['weight_percentage'];
                } else {
                    $unweightedScores[] = $type['average_score'];
                }
            }
        }

        // Handle unweighted scores
        if (!empty($unweightedScores) && $totalWeight < 100) {
            $remainingWeight = 100 - $totalWeight;
            $unweightedAverage = array_sum($unweightedScores) / count($unweightedScores);
            $totalWeightedScore += ($unweightedAverage * $remainingWeight / 100);
            $totalWeight = 100;
        }

        $finalScore = $totalWeight > 0 ? $totalWeightedScore : 0;
        $gradeInfo = calculateGrade($finalScore);

        $subjectResults[] = [
            'subject_id' => $subject['subject_id'],
            'subject_name' => $subject['subject_name'],
            'enrollment_type' => $subject['enrollment_type'],
            'special_notes' => $subject['special_notes'],
            'assessment_class_id' => $subject['assessment_class_id'],
            'assessment_types' => $assessmentTypes,
            'total_assessments' => $subject['total_assessments'],
            'completed_assessments' => $subject['completed_assessments'],
            'final_score' => round($finalScore, 1),
            'letter_grade' => $gradeInfo['grade'],
            'grade_point' => $gradeInfo['points'],
            'remarks' => $gradeInfo['remarks']
        ];
    }

    // Calculate summary
    $totalPoints = 0;
    $subjectCount = 0;
    $totalScore = 0;
    
    foreach ($subjectResults as $result) {
        if ($result['final_score'] > 0) {
            $totalPoints += $result['grade_point'];
            $totalScore += $result['final_score'];
            $subjectCount++;
        }
    }
    
    $gpa = $subjectCount > 0 ? round($totalPoints / $subjectCount, 2) : 0;
    $overallAverage = $subjectCount > 0 ? round($totalScore / $subjectCount, 1) : 0;
    $overallGrade = calculateGrade($overallAverage);

    return [
        'student' => $student,
        'semester' => $semester,
        'subjects' => $subjectResults,
        'summary' => [
            'total_subjects' => count($subjectResults),
            'gpa' => $gpa,
            'overall_average' => $overallAverage,
            'overall_grade' => $overallGrade['grade'],
            'overall_remarks' => $overallGrade['remarks']
        ]
    ];
}

/**
 * Calculate letter grade and grade points based on score
 */
function calculateGrade($score) {
    if ($score >= 80) {
        return ['grade' => 'A1', 'points' => 4.0, 'remarks' => 'Excellent'];
    } elseif ($score >= 75) {
        return ['grade' => 'B2', 'points' => 3.5, 'remarks' => 'Very Good'];
    } elseif ($score >= 70) {
        return ['grade' => 'B3', 'points' => 3.0, 'remarks' => 'Good'];
    } elseif ($score >= 65) {
        return ['grade' => 'C4', 'points' => 2.5, 'remarks' => 'Credit'];
    } elseif ($score >= 60) {
        return ['grade' => 'C5', 'points' => 2.0, 'remarks' => 'Credit'];
    } elseif ($score >= 55) {
        return ['grade' => 'C6', 'points' => 1.5, 'remarks' => 'Credit'];
    } elseif ($score >= 50) {
        return ['grade' => 'D7', 'points' => 1.0, 'remarks' => 'Pass'];
    } elseif ($score >= 45) {
        return ['grade' => 'E8', 'points' => 0.5, 'remarks' => 'Pass'];
    } else {
        return ['grade' => 'F9', 'points' => 0.0, 'remarks' => 'Fail'];
    }
}

$pageTitle = 'My Report Card';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    .report-card-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 2rem;
        border-radius: 10px;
        margin-bottom: 2rem;
    }
    
    .grade-card {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
        border-radius: 15px;
        padding: 1.5rem;
        text-align: center;
    }
    
    .subject-card {
        transition: transform 0.2s;
        border-left: 4px solid #007bff;
    }
    
    .subject-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    
    .grade-excellent { border-left-color: #28a745; }
    .grade-good { border-left-color: #ffc107; }
    .grade-pass { border-left-color: #fd7e14; }
    .grade-fail { border-left-color: #dc3545; }
</style>

<main class="container-fluid px-4">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($reportCardData): ?>
    <!-- Header -->
    <div class="report-card-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="h2 mb-2">📋 My Report Card</h1>
                <h4 class="mb-1"><?php echo htmlspecialchars($reportCardData['student']['first_name'] . ' ' . $reportCardData['student']['last_name']); ?></h4>
                <p class="mb-0 opacity-75">
                    <?php echo htmlspecialchars($reportCardData['student']['class_name'] . ' • ' . $reportCardData['student']['program_name']); ?>
                </p>
            </div>
            <div class="col-md-4 text-md-end">
                <div class="grade-card">
                    <h3 class="display-4 mb-0"><?php echo $reportCardData['summary']['gpa']; ?></h3>
                    <p class="mb-0">Grade Point Average</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Semester Selector -->
    <?php if (count($semesters) > 1): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h6 class="mb-0">Select Semester:</h6>
                </div>
                <div class="col-md-6">
                    <select class="form-select" onchange="window.location.href='report_card.php?semester=' + this.value">
                        <?php foreach ($semesters as $semester): ?>
                            <option value="<?php echo $semester['semester_id']; ?>"
                                <?php echo ($selectedSemester == $semester['semester_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($semester['semester_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm bg-primary text-white">
                <div class="card-body text-center">
                    <h3 class="display-6 mb-0"><?php echo $reportCardData['summary']['total_subjects']; ?></h3>
                    <p class="mb-0">Total Subjects</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm bg-success text-white">
                <div class="card-body text-center">
                    <h3 class="display-6 mb-0"><?php echo $reportCardData['summary']['overall_average']; ?>%</h3>
                    <p class="mb-0">Overall Average</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm bg-info text-white">
                <div class="card-body text-center">
                    <h3 class="display-6 mb-0"><?php echo $reportCardData['summary']['overall_grade']; ?></h3>
                    <p class="mb-0">Overall Grade</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm bg-warning text-white">
                <div class="card-body text-center">
                    <h3 class="display-6 mb-0"><?php echo $reportCardData['summary']['gpa']; ?></h3>
                    <p class="mb-0">GPA</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Subject Results -->
    <div class="row">
        <?php foreach ($reportCardData['subjects'] as $subject): ?>
            <?php 
            $gradeClass = '';
            if ($subject['grade_point'] >= 3.0) $gradeClass = 'grade-excellent';
            elseif ($subject['grade_point'] >= 2.0) $gradeClass = 'grade-good';
            elseif ($subject['grade_point'] >= 1.0) $gradeClass = 'grade-pass';
            else $gradeClass = 'grade-fail';
            ?>
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm subject-card <?php echo $gradeClass; ?>">
                <div class="card-body">
                    <div class="row">
                        <div class="col-8">
                            <h6 class="card-title mb-2">
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                <?php if ($subject['enrollment_type'] === 'special'): ?>
                                    <span class="badge bg-warning text-dark ms-2" title="Special Enrollment">
                                        <i class="fas fa-star"></i> Special
                                    </span>
                                <?php endif; ?>
                            </h6>
                            <p class="text-muted mb-2">
                                <small>
                                    <?php echo $subject['completed_assessments']; ?>/<?php echo $subject['total_assessments']; ?> assessments completed
                                </small>
                            </p>
                            <?php if ($subject['enrollment_type'] === 'special' && $subject['special_notes']): ?>
                                <p class="text-info mb-2">
                                    <small>
                                        <i class="fas fa-info-circle"></i> 
                                        <?php echo htmlspecialchars($subject['special_notes']); ?>
                                    </small>
                                </p>
                            <?php endif; ?>
                            
                            <!-- Assessment Types Breakdown -->
                            <?php if (!empty($subject['assessment_types'])): ?>
                            <div class="mb-2">
                                <?php foreach ($subject['assessment_types'] as $type): ?>
                                    <?php if ($type['average_score'] !== null): ?>
                                    <span class="badge bg-light text-dark me-1 mb-1">
                                        <?php echo htmlspecialchars($type['type_name']); ?>: <?php echo round($type['average_score'], 1); ?>%
                                        <?php if ($type['weight_percentage'] > 0): ?>
                                            (<?php echo $type['weight_percentage']; ?>%)
                                        <?php endif; ?>
                                    </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <p class="mb-0">
                                <span class="badge bg-secondary"><?php echo $subject['letter_grade']; ?></span>
                                <span class="text-muted ms-2"><?php echo $subject['remarks']; ?></span>
                            </p>
                        </div>
                        <div class="col-4 text-end">
                            <h2 class="text-primary mb-0"><?php echo $subject['final_score']; ?>%</h2>
                            <p class="text-muted mb-0"><?php echo $subject['grade_point']; ?> points</p>
                        </div>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="mt-3">
                        <div class="progress" style="height: 6px;">
                            <?php 
                            $completion = $subject['total_assessments'] > 0 ? 
                                ($subject['completed_assessments'] / $subject['total_assessments']) * 100 : 0;
                            ?>
                            <div class="progress-bar bg-info" style="width: <?php echo $completion; ?>%"></div>
                        </div>
                        <small class="text-muted">Assessment completion: <?php echo round($completion); ?>%</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Overall Performance -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-dark text-white">
            <h5 class="card-title mb-0">📊 Semester Performance Summary</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    <h6>Academic Standing</h6>
                    <p class="mb-2">
                        <strong>Overall Remarks:</strong> 
                        <span class="<?php echo $reportCardData['summary']['gpa'] >= 2.0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $reportCardData['summary']['overall_remarks']; ?>
                        </span>
                    </p>
                    
                    <h6 class="mt-4">Grade Scale</h6>
                    <div class="row">
                        <div class="col-sm-6">
                            <small class="d-block">A1 (80-100): Excellent</small>
                            <small class="d-block">B2 (75-79): Very Good</small>
                            <small class="d-block">B3 (70-74): Good</small>
                            <small class="d-block">C4 (65-69): Credit</small>
                            <small class="d-block">C5 (60-64): Credit</small>
                        </div>
                        <div class="col-sm-6">
                            <small class="d-block">C6 (55-59): Credit</small>
                            <small class="d-block">D7 (50-54): Pass</small>
                            <small class="d-block">E8 (45-49): Pass</small>
                            <small class="d-block">F9 (0-44): Fail</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <h6>Semester Information</h6>
                    <p class="mb-1"><strong>Semester:</strong> <?php echo htmlspecialchars($reportCardData['semester']['semester_name']); ?></p>
                    <p class="mb-1"><strong>Period:</strong> 
                        <?php echo date('M d, Y', strtotime($reportCardData['semester']['start_date'])); ?> - 
                        <?php echo date('M d, Y', strtotime($reportCardData['semester']['end_date'])); ?>
                    </p>
                    <p class="mb-0"><strong>Generated:</strong> <?php echo date('M d, Y g:i A'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="text-center py-5">
        <i class="fas fa-file-alt fa-5x text-muted mb-3"></i>
        <h3>No Report Card Available</h3>
        <p class="text-muted">No assessment data found for any semester.</p>
    </div>
    <?php endif; ?>
</main>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>