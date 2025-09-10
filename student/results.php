<?php
// student/results.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

requireRole('student');

$error = '';
$success = '';

// WAEC Grading System Function
function getWAECGrade($percentage) {
    if ($percentage >= 75) {
        return ['grade' => 'A1', 'description' => 'Excellent', 'class' => 'grade-a1'];
    } elseif ($percentage >= 70) {
        return ['grade' => 'B2', 'description' => 'Very Good', 'class' => 'grade-b2'];
    } elseif ($percentage >= 65) {
        return ['grade' => 'B3', 'description' => 'Good', 'class' => 'grade-b3'];
    } elseif ($percentage >= 60) {
        return ['grade' => 'C4', 'description' => 'Credit', 'class' => 'grade-c4'];
    } elseif ($percentage >= 55) {
        return ['grade' => 'C5', 'description' => 'Credit', 'class' => 'grade-c5'];
    } elseif ($percentage >= 50) {
        return ['grade' => 'C6', 'description' => 'Credit', 'class' => 'grade-c6'];
    } elseif ($percentage >= 45) {
        return ['grade' => 'D7', 'description' => 'Pass', 'class' => 'grade-d7'];
    } elseif ($percentage >= 40) {
        return ['grade' => 'E8', 'description' => 'Pass', 'class' => 'grade-e8'];
    } else {
        return ['grade' => 'F9', 'description' => 'Fail', 'class' => 'grade-f9'];
    }
}

function getGradeColor($gradeClass) {
    $colors = [
        'grade-a1' => '#28a745', 'grade-b2' => '#20c997', 'grade-b3' => '#17a2b8',
        'grade-c4' => '#ffc107', 'grade-c5' => '#fd7e14', 'grade-c6' => '#e83e8c',
        'grade-d7' => '#6f42c1', 'grade-e8' => '#dc3545', 'grade-f9' => '#343a40'
    ];
    return $colors[$gradeClass] ?? '#6c757d';
}

function getCompletionRating($percent) {
    if ($percent >= 90) return 'Outstanding';
    elseif ($percent >= 75) return 'Very Good';
    elseif ($percent >= 50) return 'Average';
    elseif ($percent >= 25) return 'Below Average';
    else return 'Poor';
}

// Function to calculate total possible score for an assessment
function calculateTotalPossibleScore($db, $assessmentId, $studentId) {
    // First check if this is a question pool assessment
    $stmt = $db->prepare(
        "SELECT use_question_limit, questions_to_answer FROM assessments WHERE assessment_id = ?"
    );
    $stmt->execute([$assessmentId]);
    $assessmentInfo = $stmt->fetch();
    
    if (!$assessmentInfo) {
        return 0;
    }
    
    if ($assessmentInfo['use_question_limit'] && $assessmentInfo['questions_to_answer']) {
        // This is a question pool assessment - get the actual questions selected for this student
        $stmt = $db->prepare(
            "SELECT question_order FROM assessmentattempts 
             WHERE assessment_id = ? AND student_id = ? AND question_order IS NOT NULL"
        );
        $stmt->execute([$assessmentId, $studentId]);
        $attempt = $stmt->fetch();
        
        if ($attempt && $attempt['question_order']) {
            // Decode the JSON to get selected question IDs
            $selectedQuestions = json_decode($attempt['question_order'], true);
            
            if (is_array($selectedQuestions) && !empty($selectedQuestions)) {
                // Get total score for selected questions
                $placeholders = str_repeat('?,', count($selectedQuestions) - 1) . '?';
                $stmt = $db->prepare(
                    "SELECT SUM(max_score) FROM questions WHERE question_id IN ($placeholders)"
                );
                $stmt->execute($selectedQuestions);
                return $stmt->fetchColumn() ?: 0;
            }
        }
        
        // Fallback: if no question order found, use the standard calculation
        // This might happen if the student hasn't started the assessment yet
        $stmt = $db->prepare(
            "SELECT SUM(max_score) FROM questions WHERE assessment_id = ?"
        );
        $stmt->execute([$assessmentId]);
        return $stmt->fetchColumn() ?: 0;
    } else {
        // Regular assessment - sum all questions
        $stmt = $db->prepare(
            "SELECT SUM(max_score) FROM questions WHERE assessment_id = ?"
        );
        $stmt->execute([$assessmentId]);
        return $stmt->fetchColumn() ?: 0;
    }
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get student info
    $stmt = $db->prepare(
        "SELECT s.student_id, s.first_name, s.last_name, s.class_id, c.class_name, p.program_name 
         FROM students s 
         JOIN classes c ON s.class_id = c.class_id
         JOIN programs p ON c.program_id = p.program_id
         WHERE s.user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $studentInfo = $stmt->fetch();

    if (!$studentInfo) {
        throw new Exception('Student record not found');
    }

    // Get current semester
    $stmt = $db->prepare(
        "SELECT semester_id, semester_name, start_date, end_date  
         FROM semesters 
         WHERE start_date <= CURDATE() AND end_date >= CURDATE() 
         ORDER BY start_date DESC LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();
    
    if (!$currentSemester) {
        throw new Exception('No active semester found');
    }
    
    // Get semester list for the filter
    $stmt = $db->prepare(
        "SELECT semester_id, semester_name, start_date, end_date
         FROM semesters
         ORDER BY start_date DESC"
    );
    $stmt->execute();
    $semesters = $stmt->fetchAll();
    
    // Filter by semester if specified
    $selectedSemesterId = filter_input(INPUT_GET, 'semester_id', FILTER_VALIDATE_INT) ?: $currentSemester['semester_id'];
    
    // Get all assessments for the student in the selected semester
    $stmt = $db->prepare(
        "SELECT DISTINCT
            a.assessment_id,
            a.use_question_limit,
            a.questions_to_answer,
            s.subject_id,
            s.subject_name,
            at.type_id,
            at.type_name,
            at.weight_percentage,
            r.score,
            r.result_id
         FROM subjects s
         JOIN assessmentclasses ac ON s.subject_id = ac.subject_id
         JOIN assessments a ON ac.assessment_id = a.assessment_id
         LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
         WHERE ac.class_id = ?
         AND a.semester_id = ?
         ORDER BY s.subject_name, a.assessment_id"
    );
    
    $stmt->execute([
        $studentInfo['student_id'],
        $studentInfo['class_id'],
        $selectedSemesterId
    ]);
    
    $assessmentData = $stmt->fetchAll();
    
    // Group assessments by subject and calculate statistics
    $subjectResults = [];
    
    foreach ($assessmentData as $assessment) {
        $subjectId = $assessment['subject_id'];
        $subjectName = $assessment['subject_name'];
        
        if (!isset($subjectResults[$subjectId])) {
            $subjectResults[$subjectId] = [
                'subject_id' => $subjectId,
                'subject_name' => $subjectName,
                'total_assessments' => 0,
                'completed_assessments' => 0,
                'passed_assessments' => 0,
                'scores' => [],
                'assessments' => []
            ];
        }
        
        $subjectResults[$subjectId]['total_assessments']++;
        
        if ($assessment['result_id'] !== null) {
            $subjectResults[$subjectId]['completed_assessments']++;
            
            // Calculate total possible score for this assessment
            $totalPossible = calculateTotalPossibleScore($db, $assessment['assessment_id'], $studentInfo['student_id']);
            
            if ($totalPossible > 0) {
                $percentage = ($assessment['score'] / $totalPossible) * 100;
                $subjectResults[$subjectId]['scores'][] = $percentage;
                
                if ($percentage >= 50) {
                    $subjectResults[$subjectId]['passed_assessments']++;
                }
            }
        }
    }
    
    // Calculate averages and grades for each subject
    foreach ($subjectResults as &$subject) {
        if (!empty($subject['scores'])) {
            $subject['average_score'] = array_sum($subject['scores']) / count($subject['scores']);
            $subject['highest_score'] = max($subject['scores']);
            $subject['lowest_score'] = min($subject['scores']);
        } else {
            $subject['average_score'] = null;
            $subject['highest_score'] = null;
            $subject['lowest_score'] = null;
        }
        
        // Calculate WAEC grade for subject
        if ($subject['average_score'] !== null) {
            $subject['waec_grade'] = getWAECGrade($subject['average_score']);
        } else {
            $subject['waec_grade'] = ['grade' => 'N/A', 'description' => 'No Grade', 'class' => 'grade-na'];
        }
    }
    unset($subject);
    
    // Calculate assessment type statistics
    $assessmentTypeResults = [];
    
    foreach ($assessmentData as $assessment) {
        $typeId = $assessment['type_id'] ?? 0;
        $typeName = $assessment['type_name'] ?? 'Unassigned';
        $weight = $assessment['weight_percentage'] ?? 0;
        
        if (!isset($assessmentTypeResults[$typeId])) {
            $assessmentTypeResults[$typeId] = [
                'type_id' => $typeId,
                'type_name' => $typeName,
                'weight_percentage' => $weight,
                'total_assessments' => 0,
                'completed_assessments' => 0,
                'passed_assessments' => 0,
                'scores' => [],
                'total_score' => 0
            ];
        }
        
        $assessmentTypeResults[$typeId]['total_assessments']++;
        
        if ($assessment['result_id'] !== null) {
            $assessmentTypeResults[$typeId]['completed_assessments']++;
            
            // Calculate percentage score
            $totalPossible = calculateTotalPossibleScore($db, $assessment['assessment_id'], $studentInfo['student_id']);
            
            if ($totalPossible > 0) {
                $percentage = ($assessment['score'] / $totalPossible) * 100;
                $assessmentTypeResults[$typeId]['scores'][] = $percentage;
                $assessmentTypeResults[$typeId]['total_score'] += $percentage;
                
                if ($percentage >= 50) {
                    $assessmentTypeResults[$typeId]['passed_assessments']++;
                }
            }
        }
    }
    
    // Calculate averages and grades for each assessment type
    foreach ($assessmentTypeResults as &$type) {
        if (!empty($type['scores'])) {
            $type['average_score'] = array_sum($type['scores']) / count($type['scores']);
            $type['highest_score'] = max($type['scores']);
            $type['lowest_score'] = min($type['scores']);
        } else {
            $type['average_score'] = null;
            $type['highest_score'] = null;
            $type['lowest_score'] = null;
        }
        
        // Calculate WAEC grade for assessment type
        if ($type['average_score'] !== null) {
            $type['waec_grade'] = getWAECGrade($type['average_score']);
        } else {
            $type['waec_grade'] = ['grade' => 'N/A', 'description' => 'No Grade', 'class' => 'grade-na'];
        }
    }
    unset($type);
    
    // Convert to array for easier iteration
    $assessmentTypeResults = array_values($assessmentTypeResults);
    
    // Convert to array for easier iteration
    $subjectResults = array_values($subjectResults);

    // Get detailed assessment information for each subject
    foreach ($subjectResults as &$subject) {
        $stmt = $db->prepare(
            "SELECT 
                a.assessment_id, a.title, a.date, a.status, a.use_question_limit, a.questions_to_answer,
                at.type_name, at.type_id,
                r.score,
                r.created_at as result_date
             FROM assessments a
             JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
             LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
             LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
             WHERE ac.subject_id = ?
             AND ac.class_id = ?
             AND a.semester_id = ?
             ORDER BY a.date DESC, a.title"
        );
        
        $stmt->execute([
            $studentInfo['student_id'],
            $subject['subject_id'],
            $studentInfo['class_id'],
            $selectedSemesterId
        ]);
        
        $assessments = $stmt->fetchAll();
        
        // Add total_possible and question_count to each assessment
        foreach ($assessments as &$assessment) {
            $totalPossible = calculateTotalPossibleScore($db, $assessment['assessment_id'], $studentInfo['student_id']);
            $assessment['total_possible'] = $totalPossible;
            
            if ($assessment['use_question_limit'] && $assessment['questions_to_answer']) {
                $assessment['question_count'] = $assessment['questions_to_answer'];
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) FROM questions WHERE assessment_id = ?");
                $stmt->execute([$assessment['assessment_id']]);
                $assessment['question_count'] = $stmt->fetchColumn();
            }
        }
        unset($assessment);
        
        $subject['assessments'] = $assessments;
    }
    unset($subject);

    // Get semester name for display
    $stmt = $db->prepare("SELECT semester_name FROM semesters WHERE semester_id = ?");
    $stmt->execute([$selectedSemesterId]);
    $semesterName = $stmt->fetchColumn();

    // Calculate overall statistics
    $totalAssessments = array_sum(array_column($subjectResults, 'total_assessments'));
    $totalCompleted = array_sum(array_column($subjectResults, 'completed_assessments'));
    $totalPassed = array_sum(array_column($subjectResults, 'passed_assessments'));
    
    $overallAverage = 0;
    $scoreCount = 0;
    foreach ($subjectResults as $subject) {
        if ($subject['average_score'] !== null) {
            $overallAverage += $subject['average_score'];
            $scoreCount++;
        }
    }
    $overallAverage = $scoreCount > 0 ? $overallAverage / $scoreCount : 0;
    $overallGrade = getWAECGrade($overallAverage);

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Student results error: " . $e->getMessage());
}

$pageTitle = 'My Results';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <!-- Header Section -->
    <div class="results-header mb-4">
        <div class="results-header-content">
            <div class="d-flex justify-content-between align-items-center">
                <div class="header-left">
                    <h1 class="display-6 fw-bold text-warning mb-0">Academic Performance Dashboard</h1>
                    <p class="text-light mb-2">Track your assessment results and overall progress</p>
                    <div class="student-info-badges">
                        <span class="info-badge">
                            <i class="fas fa-user-graduate me-1"></i>
                            <?php echo htmlspecialchars($studentInfo['first_name'] . ' ' . $studentInfo['last_name']); ?>
                        </span>
                        <span class="info-badge">
                            <i class="fas fa-school me-1"></i>
                            <?php echo htmlspecialchars($studentInfo['program_name'] . ' - ' . $studentInfo['class_name']); ?>
                        </span>
                    </div>
                </div>
                
                <!-- Semester Filter -->
                <div class="header-right d-flex align-items-center">
                    <form id="semesterForm" class="semester-selector">
                        <div class="d-flex align-items-center">
                            <span class="text-light me-2">
                                <i class="fas fa-calendar-alt me-1"></i>Viewing:
                            </span>
                            <select id="semester_id" name="semester_id" class="form-select form-select-sm bg-dark text-warning border-warning" onchange="this.form.submit()">
                                <?php foreach ($semesters as $semester): ?>
                                    <option value="<?php echo $semester['semester_id']; ?>" <?php echo $semester['semester_id'] == $selectedSemesterId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($semester['semester_name']); ?>
                                        (<?php echo date('M Y', strtotime($semester['start_date'])); ?> - <?php echo date('M Y', strtotime($semester['end_date'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Performance Summary -->
    <div class="card mb-4 performance-card">
        <div class="card-header bg-dark text-warning">
            <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Performance Summary - <?php echo htmlspecialchars($semesterName); ?></h5>
        </div>
        <div class="card-body">
            <?php if (empty($subjectResults)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No assessment results available for this semester.</p>
                </div>
            <?php else: ?>
                <div class="row">
                    <!-- Overall Grade Circle -->
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="overall-grade-card">
                            <div class="grade-circle" style="border-color: <?php echo getGradeColor($overallGrade['class']); ?>;">
                                <div class="grade-content" style="color: <?php echo getGradeColor($overallGrade['class']); ?>;">
                                    <div class="grade-letter"><?php echo $overallGrade['grade']; ?></div>
                                    <div class="grade-percentage"><?php echo number_format($overallAverage, 1); ?>%</div>
                                    <div class="grade-description"><?php echo $overallGrade['description']; ?></div>
                                </div>
                            </div>
                            <div class="text-center mt-2">
                                <h6 class="fw-bold">Overall Performance</h6>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics -->
                    <?php 
                    $completionRate = $totalAssessments > 0 ? ($totalCompleted / $totalAssessments) * 100 : 0;
                    $passRate = $totalCompleted > 0 ? ($totalPassed / $totalCompleted) * 100 : 0;
                    ?>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-tasks"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $totalCompleted; ?><span class="value-total">/ <?php echo $totalAssessments; ?></span></div>
                                <div class="stat-label">Assessments Completed</div>
                                <div class="progress stat-progress">
                                    <div class="progress-bar bg-primary" style="width: <?php echo $completionRate; ?>%"></div>
                                </div>
                                <div class="stat-rating">
                                    <?php echo getCompletionRating($completionRate); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $totalPassed; ?><span class="value-total">/ <?php echo $totalCompleted; ?></span></div>
                                <div class="stat-label">Assessments Passed</div>
                                <div class="progress stat-progress">
                                    <div class="progress-bar bg-success" style="width: <?php echo $passRate; ?>%"></div>
                                </div>
                                <div class="stat-rating">
                                    <?php echo number_format($passRate, 1); ?>% Pass Rate
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-warning text-dark">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <div class="stat-content">
                                <?php 
                                $excellentSubjects = 0;
                                foreach ($subjectResults as $subject) {
                                    if ($subject['average_score'] >= 75) $excellentSubjects++;
                                }
                                ?>
                                <div class="stat-value"><?php echo $excellentSubjects; ?><span class="value-total">/ <?php echo count($subjectResults); ?></span></div>
                                <div class="stat-label">Excellent Grades (A1)</div>
                                <div class="stat-rating">
                                    <?php echo $excellentSubjects > 0 ? 'Outstanding Performance!' : 'Room for Improvement'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Assessment Type Performance -->
    <?php if (!empty($assessmentTypeResults)): ?>
        <div class="card mb-4 assessment-type-card">
            <div class="card-header bg-dark text-warning">
                <h5 class="mb-0"><i class="fas fa-layer-group me-2"></i>Performance by Assessment Type</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($assessmentTypeResults as $type): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card h-100 type-performance-card">
                                <div class="card-header text-center" style="background: linear-gradient(45deg, #000000, #ffd700);">
                                    <h6 class="mb-1 text-white"><?php echo htmlspecialchars($type['type_name']); ?></h6>
                                    <small class="text-white-50">Weight: <?php echo $type['weight_percentage']; ?>%</small>
                                </div>
                                <div class="card-body text-center">
                                    <?php if ($type['average_score'] !== null): ?>
                                        <div class="score-circle mb-3">
                                            <div class="score-value" style="color: <?php echo getGradeColor($type['waec_grade']['class']); ?>;">
                                                <?php echo number_format($type['average_score'], 1); ?>%
                                            </div>
                                        </div>
                                        <div class="grade-badge <?php echo $type['waec_grade']['class']; ?> mb-2">
                                            <?php echo $type['waec_grade']['grade']; ?>
                                        </div>
                                        <p class="small text-muted mb-2"><?php echo $type['waec_grade']['description']; ?></p>
                                    <?php else: ?>
                                        <div class="score-circle mb-3">
                                            <div class="score-value text-muted">-</div>
                                        </div>
                                        <div class="grade-badge grade-na mb-2">N/A</div>
                                        <p class="small text-muted mb-2">No assessments completed</p>
                                    <?php endif; ?>
                                    
                                    <div class="stats-row">
                                        <div class="stat-item">
                                            <small class="text-muted">Completed</small>
                                            <div class="fw-bold"><?php echo $type['completed_assessments']; ?>/<?php echo $type['total_assessments']; ?></div>
                                        </div>
                                        <?php if ($type['completed_assessments'] > 0): ?>
                                        <div class="stat-item">
                                            <small class="text-muted">Passed</small>
                                            <div class="fw-bold text-success"><?php echo $type['passed_assessments']; ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($type['completed_assessments'] > 0): ?>
                                    <div class="progress mt-2" style="height: 6px;">
                                        <div class="progress-bar" 
                                             style="width: <?php echo ($type['completed_assessments'] / $type['total_assessments']) * 100; ?>%; background: linear-gradient(90deg, #000000, #ffd700);">
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo number_format(($type['completed_assessments'] / $type['total_assessments']) * 100, 1); ?>% Complete
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Subject Performance with WAEC Grading -->
    <?php if (!empty($subjectResults)): ?>
        <div class="row">
            <?php foreach ($subjectResults as $subject): ?>
                <div class="col-md-6 mb-4">
                    <div class="card h-100 subject-card">
                        <div class="card-header" style="background: linear-gradient(90deg, #000000, #343a40);">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 text-warning">
                                    <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($subject['subject_name']); ?>
                                </h5>
                                <div class="subject-grade-container">
                                    <?php if ($subject['average_score'] !== null): ?>
                                        <div class="waec-grade-badge" style="background-color: <?php echo getGradeColor($subject['waec_grade']['class']); ?>;">
                                            <?php echo $subject['waec_grade']['grade']; ?>
                                        </div>
                                        <small class="grade-description text-light"><?php echo $subject['waec_grade']['description']; ?></small>
                                    <?php else: ?>
                                        <div class="waec-grade-badge bg-secondary">N/A</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Subject Statistics -->
                            <div class="subject-stats-row mb-3">
                                <div class="row text-center">
                                    <div class="col-3">
                                        <div class="mini-stat">
                                            <div class="mini-stat-value"><?php echo $subject['average_score'] ? number_format($subject['average_score'], 1) : 'N/A'; ?>%</div>
                                            <div class="mini-stat-label">Average</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="mini-stat">
                                            <div class="mini-stat-value"><?php echo $subject['highest_score'] ? number_format($subject['highest_score'], 1) : 'N/A'; ?>%</div>
                                            <div class="mini-stat-label">Highest</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="mini-stat">
                                            <div class="mini-stat-value"><?php echo $subject['completed_assessments']; ?>/<?php echo $subject['total_assessments']; ?></div>
                                            <div class="mini-stat-label">Completed</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="mini-stat">
                                            <div class="mini-stat-value"><?php echo $subject['passed_assessments']; ?></div>
                                            <div class="mini-stat-label">Passed</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($subject['average_score'] !== null): ?>
                                <div class="progress mb-4 rounded-pill" style="height: 10px;">
                                    <div class="progress-bar rounded-pill" 
                                         role="progressbar" 
                                         style="width: <?php echo min($subject['average_score'], 100); ?>%; background-color: <?php echo getGradeColor($subject['waec_grade']['class']); ?>;" 
                                         aria-valuenow="<?php echo $subject['average_score']; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="progress mb-4 rounded-pill" style="height: 10px;">
                                    <div class="progress-bar bg-secondary rounded-pill" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($subject['assessments'])): ?>
                                <div class="table-responsive assessment-table">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Assessment</th>
                                                <th>Date</th>
                                                <th>Grade</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($subject['assessments'] as $assessment): ?>
                                                <tr class="<?php echo $assessment['score'] !== null ? 'clickable-row' : ''; ?>" 
                                                    <?php if ($assessment['score'] !== null): ?>
                                                        onclick="window.location.href='view_result.php?id=<?php echo $assessment['assessment_id']; ?>'"
                                                        style="cursor: pointer;"
                                                        title="Click to view detailed results"
                                                    <?php endif; ?>>
                                                    <td>
                                                        <div class="assessment-title">
                                                            <?php echo htmlspecialchars($assessment['title']); ?>
                                                            <?php if ($assessment['use_question_limit']): ?>
                                                                <span class="badge bg-info ms-1" title="Question Pool Assessment">
                                                                    <i class="fas fa-layer-group"></i>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($assessment['score'] !== null): ?>
                                                            <small class="text-muted">
                                                                <?php echo $assessment['score']; ?>/<?php echo $assessment['total_possible']; ?> points
                                                                <?php if ($assessment['use_question_limit']): ?>
                                                                    (<?php echo $assessment['question_count']; ?> questions)
                                                                <?php endif; ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($assessment['date'])); ?></td>
                                                    <td>
                                                        <?php if ($assessment['score'] !== null && $assessment['total_possible'] > 0): ?>
                                                            <?php 
                                                            $assessmentPercentage = ($assessment['score'] / $assessment['total_possible']) * 100;
                                                            $assessmentGrade = getWAECGrade($assessmentPercentage);
                                                            ?>
                                                            <span class="waec-grade-badge small" style="background-color: <?php echo getGradeColor($assessmentGrade['class']); ?>;">
                                                                <?php echo $assessmentGrade['grade']; ?>
                                                            </span>
                                                            <small class="d-block text-muted"><?php echo number_format($assessmentPercentage, 1); ?>%</small>
                                                        <?php else: ?>
                                                            <span class="waec-grade-badge small bg-secondary">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($assessment['score'] !== null): ?>
                                                            <a href="view_result.php?id=<?php echo $assessment['assessment_id']; ?>" class="btn btn-sm btn-outline-warning" onclick="event.stopPropagation();">
                                                                <i class="fas fa-eye me-1"></i>View
                                                            </a>
                                                        <?php elseif ($assessment['status'] == 'pending'): ?>
                                                            <span class="badge bg-info">Scheduled</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Missed</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <p class="text-muted mb-0">No assessments available for this subject.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                <h4>No Results Found</h4>
                <p class="text-muted">There are no assessment results available for this semester.</p>
                <a href="assessments.php" class="btn btn-warning mt-3">
                    <i class="fas fa-tasks me-2"></i>Go to Assessments
                </a>
            </div>
        </div>
    <?php endif; ?>
</main>

<style>
    :root {
        --gold: #ffd700;
        --gold-dark: #e5c100;
        --gold-light: #fffce6;
        --black: #000000;
        --gray-dark: #343a40;
        --gray-light: #f8f9fa;
    }
    
    .results-header {
        background: linear-gradient(135deg, var(--black) 0%, #2c3e50 50%, var(--gold) 100%);
        padding: 2rem;
        border-radius: 12px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
        margin-bottom: 2rem;
        position: relative;
        overflow: hidden;
    }
    
    .results-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,215,0,0.1)" stroke-width="1"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
        opacity: 0.3;
    }
    
    .results-header-content {
        color: white;
        position: relative;
        z-index: 2;
    }
    
    .student-info-badges {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 1rem;
    }
    
    .info-badge {
        background: rgba(255, 255, 255, 0.15);
        color: var(--gold);
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 215, 0, 0.3);
    }
    
    .text-warning {
        color: var(--gold) !important;
    }
    
    .semester-selector select {
        background-color: rgba(0, 0, 0, 0.3) !important;
        border: 2px solid var(--gold) !important;
        color: var(--gold) !important;
        padding: 0.5rem 1.5rem 0.5rem 1rem;
        border-radius: 25px;
        cursor: pointer;
        transition: all 0.3s ease;
        backdrop-filter: blur(10px);
    }
    
    .semester-selector select:focus {
        box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.25);
    }

    .overall-grade-card {
        text-align: center;
        padding: 1rem;
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    
    .grade-circle {
        width: 120px;
        height: 120px;
        border: 4px solid;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        box-shadow: inset 0 4px 15px rgba(0, 0, 0, 0.1);
        position: relative;
        overflow: hidden;
    }
    
    .grade-circle::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: radial-gradient(circle at center, rgba(255, 255, 255, 0.3) 0%, transparent 70%);
    }
    
    .grade-content {
        text-align: center;
        font-weight: bold;
        position: relative;
        z-index: 2;
    }
    
    .grade-letter {
        font-size: 2rem;
        font-weight: 900;
        line-height: 1;
        margin-bottom: 4px;
        text-transform: uppercase;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    .grade-percentage {
        font-size: 0.9rem;
        font-weight: 600;
        opacity: 0.8;
        margin-bottom: 2px;
    }
    
    .grade-description {
        font-size: 0.7rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.7;
    }

    .performance-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        overflow: hidden;
    }
    
    .stat-card {
        display: flex;
        align-items: center;
        padding: 1.5rem;
        background: linear-gradient(45deg, #f8f9fa 0%, #ffffff 100%);
        border-radius: 12px;
        transition: all 0.3s ease;
        border: 1px solid rgba(0, 0, 0, 0.05);
        height: 100%;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    
    .stat-card:hover {
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        transform: translateY(-3px);
    }
    
    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: white;
        margin-right: 1.25rem;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }
    
    .stat-content {
        flex: 1;
    }
    
    .stat-value {
        font-size: 1.8rem;
        font-weight: 700;
        line-height: 1.2;
        color: var(--gray-dark);
    }
    
    .value-total {
        font-size: 1rem;
        color: #6c757d;
        font-weight: 400;
    }
    
    .stat-label {
        color: #6c757d;
        font-size: 0.9rem;
        margin: 0.5rem 0;
        font-weight: 500;
    }
    
    .stat-rating {
        font-size: 0.8rem;
        font-weight: 500;
        color: #495057;
    }
    
    .stat-progress {
        height: 5px;
        margin: 0.5rem 0;
        background-color: #e9ecef;
        border-radius: 3px;
    }

    .subject-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .subject-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
    }
    
    .subject-grade-container {
        text-align: center;
    }
    
    .waec-grade-badge {
        color: white;
        padding: 0.4rem 0.8rem;
        border-radius: 15px;
        font-size: 0.9rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        margin-bottom: 0.25rem;
    }
    
    .waec-grade-badge.small {
        font-size: 0.75rem;
        padding: 0.3rem 0.6rem;
        margin: 0;
    }
    
    .grade-description {
        font-size: 0.7rem;
        opacity: 0.8;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .subject-stats-row {
        background: var(--gray-light);
        border-radius: 8px;
        padding: 1rem;
    }
    
    .mini-stat {
        text-align: center;
    }
    
    .mini-stat-value {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--gray-dark);
        line-height: 1;
    }
    
    .mini-stat-label {
        font-size: 0.7rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 0.25rem;
    }

    .assessment-table {
        border-radius: 8px;
        overflow: hidden;
    }
    
    .assessment-table .table {
        margin-bottom: 0;
    }
    
    .assessment-table thead {
        background-color: rgba(0, 0, 0, 0.02);
    }
    
    .assessment-table th {
        font-weight: 600;
        color: #495057;
        padding: 0.75rem;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .assessment-table td {
        padding: 0.75rem;
        vertical-align: middle;
    }
    
    .assessment-title {
        font-weight: 500;
        color: #343a40;
        line-height: 1.3;
    }
    
    .clickable-row {
        transition: all 0.2s ease;
    }
    
    .clickable-row:hover {
        background-color: rgba(255, 215, 0, 0.1) !important;
        transform: translateX(2px);
    }
    
    .table-hover tbody tr:hover {
        background-color: rgba(255, 215, 0, 0.05);
    }

    .progress {
        height: 10px;
        background-color: #e9ecef;
        overflow: hidden;
        border-radius: 5px;
    }
    
    .progress-bar {
        border-radius: 5px;
        transition: all 0.6s ease;
    }

    .grade-a1 { color: #28a745; }
    .grade-b2 { color: #20c997; }
    .grade-b3 { color: #17a2b8; }
    .grade-c4 { color: #ffc107; }
    .grade-c5 { color: #fd7e14; }
    .grade-c6 { color: #e83e8c; }
    .grade-d7 { color: #6f42c1; }
    .grade-e8 { color: #dc3545; }
    .grade-f9 { color: #343a40; }

    /* Assessment Type Performance Cards */
    .assessment-type-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        overflow: hidden;
    }

    .type-performance-card {
        border: none;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: transform 0.2s ease;
    }

    .type-performance-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .stats-row {
        display: flex;
        justify-content: space-around;
        margin-bottom: 1rem;
    }

    .stat-item {
        text-align: center;
    }

    .stat-item .fw-bold {
        font-size: 1.1rem;
        margin-top: 0.25rem;
    }

    .score-circle {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border: 3px solid #ffd700;
    }

    .score-value {
        font-size: 1.2rem;
        font-weight: bold;
    }

    .grade-badge {
        display: inline-block;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-weight: bold;
        font-size: 0.9rem;
    }

    .grade-na {
        background-color: #6c757d;
        color: white;
    }

    .btn-warning, .btn-outline-warning:hover {
        background: linear-gradient(45deg, var(--black) 0%, var(--gold) 100%);
        border: none;
        color: white;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .btn-outline-warning {
        border: 1px solid var(--gold);
        color: var(--black);
        background: transparent;
    }
    
    .btn-outline-warning:hover {
        color: white;
        background: linear-gradient(45deg, var(--black) 0%, var(--gold) 100%);
        transform: translateY(-1px);
    }
    
    .badge {
        padding: 0.4em 0.7em;
        font-weight: 500;
        font-size: 0.8rem;
    }

    @keyframes gradeReveal {
        0% {
            opacity: 0;
            transform: scale(0.8);
        }
        100% {
            opacity: 1;
            transform: scale(1);
        }
    }
    
    .grade-circle {
        animation: gradeReveal 0.8s ease-out;
    }
    
    .waec-grade-badge {
        animation: gradeReveal 0.6s ease-out;
    }

    @media (max-width: 768px) {
        .results-header {
            padding: 1.5rem;
        }
        
        .student-info-badges {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .info-badge {
            font-size: 0.8rem;
            padding: 0.3rem 0.6rem;
        }
        
        .header-right {
            margin-top: 1rem;
        }
        
        .grade-circle {
            width: 100px;
            height: 100px;
            border-width: 3px;
        }
        
        .grade-letter {
            font-size: 1.5rem;
        }
        
        .grade-percentage {
            font-size: 0.8rem;
        }
        
        .stat-card {
            margin-bottom: 1rem;
            padding: 1rem;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            font-size: 1.2rem;
            margin-right: 1rem;
        }
        
        .stat-value {
            font-size: 1.5rem;
        }
        
        .subject-stats-row {
            padding: 0.75rem;
        }
        
        .mini-stat-value {
            font-size: 1rem;
        }
        
        .assessment-table th,
        .assessment-table td {
            padding: 0.5rem;
            font-size: 0.8rem;
        }
        
        .assessment-title {
            font-size: 0.85rem;
        }
        
        .waec-grade-badge {
            font-size: 0.75rem;
            padding: 0.3rem 0.5rem;
        }
    }
    
    @media (max-width: 576px) {
        .results-header {
            margin-left: -1rem;
            margin-right: -1rem;
            border-radius: 0;
        }
        
        .semester-selector {
            width: 100%;
        }
        
        .semester-selector select {
            width: 100%;
            font-size: 0.8rem;
        }
        
        .subject-stats-row .row {
            gap: 0.5rem;
        }
        
        .mini-stat {
            background: white;
            padding: 0.5rem;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
    }

    @media print {
        .results-header {
            background: none !important;
            color: #000 !important;
            box-shadow: none !important;
        }
        
        .results-header::before {
            display: none;
        }
        
        .info-badge {
            background: none !important;
            color: #000 !important;
            border: 1px solid #000 !important;
        }
        
        .btn, .semester-selector {
            display: none !important;
        }
        
        .card {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        
        .subject-card .card-header {
            background: none !important;
            color: #000 !important;
            border-bottom: 2px solid #000 !important;
        }
        
        .waec-grade-badge {
            border: 1px solid #000 !important;
            background: none !important;
            color: #000 !important;
        }
        
        .grade-circle {
            border-color: #000 !important;
        }
        
        .stat-card {
            border: 1px solid #ddd !important;
        }
    }

    .overall-grade-card:hover .grade-circle {
        transform: scale(1.05);
        transition: transform 0.3s ease;
    }
    
    .clickable-row:hover .assessment-title {
        color: var(--gold);
        font-weight: 600;
    }

    .semester-selector select:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }

    .clickable-row:focus {
        outline: 2px solid var(--gold);
        outline-offset: 2px;
    }
    
    .btn:focus, .form-select:focus {
        outline: 2px solid var(--gold);
        outline-offset: 2px;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-dismiss alerts
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
        
        // Initialize any tooltips
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(tooltip => {
            new bootstrap.Tooltip(tooltip);
        });
        
        // Add animations on scroll
        const animateOnScroll = function() {
            const cards = document.querySelectorAll('.subject-card, .stat-card, .overall-grade-card');
            
            cards.forEach(card => {
                const cardTop = card.getBoundingClientRect().top;
                const cardBottom = card.getBoundingClientRect().bottom;
                const windowHeight = window.innerHeight;
                
                if (cardTop < windowHeight && cardBottom > 0) {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }
            });
        };
        
        // Set initial state for animation
        document.querySelectorAll('.subject-card, .stat-card, .overall-grade-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        });
        
        // Add animation on load
        setTimeout(animateOnScroll, 100);
        
        // Add animation on scroll
        window.addEventListener('scroll', animateOnScroll);
        
        // Enhanced semester form submission with loading state
        const semesterForm = document.getElementById('semesterForm');
        const semesterSelect = document.getElementById('semester_id');
        
        if (semesterForm && semesterSelect) {
            semesterSelect.addEventListener('change', function() {
                // Add loading state
                this.disabled = true;
                const loadingOption = document.createElement('option');
                loadingOption.textContent = 'Loading...';
                loadingOption.selected = true;
                this.appendChild(loadingOption);
                
                // Submit form
                semesterForm.submit();
            });
        }
        
        // Add keyboard navigation for clickable rows
        document.querySelectorAll('.clickable-row').forEach(row => {
            row.setAttribute('tabindex', '0');
            row.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });
        
        // Performance monitoring
        if ('performance' in window) {
            window.addEventListener('load', function() {
                setTimeout(function() {
                    const loadTime = performance.now();
                    if (loadTime > 3000) {
                        console.warn('Page load time is slow:', loadTime + 'ms');
                    }
                }, 0);
            });
        }
        
        // Error handling for any AJAX requests
        window.addEventListener('unhandledrejection', function(event) {
            console.error('Unhandled promise rejection:', event.reason);
        });
        
        // Add smooth scroll behavior for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    });
</script>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>