<?php
// student/schedule.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

requireRole('student');

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get student info
    $stmt = $db->prepare(
        "SELECT s.student_id, s.class_id, c.class_name, p.program_name 
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

    // Check if there's an assessment in progress
    $stmt = $db->prepare(
        "SELECT aa.assessment_id, aa.attempt_id, a.title, s.subject_name, aa.start_time
         FROM assessmentattempts aa
         JOIN assessments a ON aa.assessment_id = a.assessment_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         WHERE aa.student_id = ? 
         AND aa.status = 'in_progress'
         AND ac.class_id = ?
         ORDER BY aa.start_time DESC
         LIMIT 1"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id']]);
    $inProgressAssessment = $stmt->fetch();

    // Validate and set month/year
    $requestedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
    $requestedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    
    if ($requestedMonth < 1) {
        $requestedMonth = 12;
        $requestedYear--;
    } elseif ($requestedMonth > 12) {
        $requestedMonth = 1;
        $requestedYear++;
    }

    $month = $requestedMonth;
    $year = $requestedYear;

    // Get assessments for the month based on the schema - including allow_late_submission
    $stmt = $db->prepare(
        "SELECT 
            a.assessment_id,
            a.title,
            a.date,
            a.start_time,
            a.end_time,
            a.status,
            a.allow_late_submission,
            s.subject_name,
            r.status as result_status,
            r.score
         FROM assessments a
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         LEFT JOIN results r ON (a.assessment_id = r.assessment_id AND r.student_id = :student_id)
         WHERE ac.class_id = :class_id
         AND MONTH(a.date) = :month
         AND YEAR(a.date) = :year
         ORDER BY a.date ASC, a.start_time ASC"
    );
    
    $stmt->execute([
        ':student_id' => $studentInfo['student_id'],
        ':class_id' => $studentInfo['class_id'],
        ':month' => $month,
        ':year' => $year
    ]);
    $assessments = $stmt->fetchAll();

    // Organize assessments by date
    $assessmentsByDate = [];
    foreach ($assessments as $assessment) {
        $day = (int)date('d', strtotime($assessment['date']));
        if (!isset($assessmentsByDate[$day])) {
            $assessmentsByDate[$day] = [];
        }
        $assessmentsByDate[$day][] = $assessment;
    }

} catch (Exception $e) {
    logError("Schedule error: " . $e->getMessage());
    $error = "Error loading schedule data: " . $e->getMessage();
}

// Function to determine assessment availability and status
function getAssessmentStatus($assessment, $inProgressAssessment = null) {
    $now = date('H:i:s');
    $currentDate = date('Y-m-d');
    $startTime = $assessment['start_time'] ?? '00:00:00';
    $endTime = $assessment['end_time'] ?? '23:59:59';
    $allowLateSubmission = $assessment['allow_late_submission'] ?? false;
    
    // If assessment is completed
    if ($assessment['result_status'] === 'completed') {
        return [
            'status' => 'completed',
            'class' => 'success',
            'canTake' => false,
            'link' => 'view_result.php?id=' . $assessment['assessment_id'],
            'title' => 'Completed - Click to view result'
        ];
    }
    
    $isToday = ($assessment['date'] === $currentDate);
    $isPast = ($assessment['date'] < $currentDate);
    $isFuture = ($assessment['date'] > $currentDate);
    
    // Check if student can take assessment (no assessment in progress or this is the one in progress)
    $canTakeAssessment = !$inProgressAssessment || 
                        ($inProgressAssessment && $assessment['assessment_id'] == $inProgressAssessment['assessment_id']);
    
    // If it's today
    if ($isToday) {
        $isTimeAvailable = ($now >= $startTime && $now <= $endTime);
        
        if ($isTimeAvailable && $canTakeAssessment) {
            return [
                'status' => 'available',
                'class' => 'warning',
                'canTake' => true,
                'link' => 'take_assessment.php?id=' . $assessment['assessment_id'],
                'title' => 'Available now - Click to take assessment'
            ];
        } elseif (!$canTakeAssessment) {
            return [
                'status' => 'locked',
                'class' => 'secondary',
                'canTake' => false,
                'link' => '#',
                'title' => 'Complete your current assessment first'
            ];
        } elseif ($now < $startTime) {
            return [
                'status' => 'upcoming_today',
                'class' => 'info',
                'canTake' => false,
                'link' => '#',
                'title' => 'Opens at ' . date('g:i A', strtotime($startTime))
            ];
        } elseif ($now > $endTime) {
            if ($allowLateSubmission && $canTakeAssessment) {
                return [
                    'status' => 'late_allowed',
                    'class' => 'info',
                    'canTake' => true,
                    'link' => 'take_assessment.php?id=' . $assessment['assessment_id'],
                    'title' => 'Late submission allowed - Click to take'
                ];
            } else {
                return [
                    'status' => 'expired',
                    'class' => 'danger',
                    'canTake' => false,
                    'link' => '#',
                    'title' => 'Time expired'
                ];
            }
        }
    }
    
    // If it's in the past
    if ($isPast) {
        if ($allowLateSubmission && $canTakeAssessment) {
            return [
                'status' => 'late_allowed',
                'class' => 'info',
                'canTake' => true,
                'link' => 'take_assessment.php?id=' . $assessment['assessment_id'],
                'title' => 'Late submission allowed - Click to take'
            ];
        } else {
            return [
                'status' => 'missed',
                'class' => 'danger',
                'canTake' => false,
                'link' => '#',
                'title' => 'Assessment missed'
            ];
        }
    }
    
    // If it's in the future
    if ($isFuture) {
        if (!$canTakeAssessment) {
            return [
                'status' => 'locked',
                'class' => 'secondary',
                'canTake' => false,
                'link' => '#',
                'title' => 'Complete your current assessment first'
            ];
        } else {
            return [
                'status' => 'upcoming',
                'class' => 'primary',
                'canTake' => false,
                'link' => 'assessments.php#assessment-' . $assessment['assessment_id'],
                'title' => 'Upcoming assessment - Click for details'
            ];
        }
    }
    
    // Default fallback
    return [
        'status' => 'unknown',
        'class' => 'secondary',
        'canTake' => false,
        'link' => '#',
        'title' => 'Status unknown'
    ];
}

$pageTitle = 'Assessment Schedule';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="row align-items-center mb-4">
        <div class="col">
            <h1 class="h3 text-warning">Assessment Schedule</h1>
            <div class="text-dark small">
                <?php echo htmlspecialchars($studentInfo['program_name']); ?> | 
                <?php echo htmlspecialchars($studentInfo['class_name']); ?>
            </div>
        </div>
        <div class="col-auto">
            <div class="btn-group">
                <a href="?month=<?php echo $month-1; ?>&year=<?php echo $year; ?>" 
                   class="btn btn-sm btn-warning">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <span class="btn btn-sm btn-dark">
                    <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
                </span>
                <a href="?month=<?php echo $month+1; ?>&year=<?php echo $year; ?>" 
                   class="btn btn-sm btn-warning">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($inProgressAssessment): ?>
        <div class="alert alert-warning alert-dismissible fade show mb-4">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-1">Assessment in Progress!</h5>
                    <p class="mb-2">
                        You have an ongoing assessment: <strong>"<?php echo htmlspecialchars($inProgressAssessment['title']); ?>"</strong> 
                        in <?php echo htmlspecialchars($inProgressAssessment['subject_name']); ?>
                    </p>
                    <p class="mb-0 small text-muted">
                        Started: <?php echo date('M d, Y g:i A', strtotime($inProgressAssessment['start_time'])); ?>
                    </p>
                </div>
                <div class="ms-3">
                    <a href="take_assessment.php?id=<?php echo $inProgressAssessment['assessment_id']; ?>" 
                       class="btn btn-warning btn-lg">
                        <i class="fas fa-play me-2"></i>Continue Assessment
                    </a>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Calendar Grid -->
    <div class="row g-3">
        <?php
        $firstDay = new DateTime("$year-$month-01");
        $lastDay = new DateTime("$year-$month-" . $firstDay->format('t'));
        
        $currentDate = clone $firstDay;
        while ($currentDate <= $lastDay) {
            $dayNum = $currentDate->format('j');
            $isToday = $currentDate->format('Y-m-d') === date('Y-m-d');
            
            echo '<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">';
            echo '<div class="card h-100 border-0 shadow-sm ' . ($isToday ? 'today' : '') . '">';
            echo '<div class="card-header d-flex justify-content-between align-items-center p-2" 
                  style="background: ' . ($isToday ? 'linear-gradient(145deg, #ffc107 0%, #ff6f00 100%)' : '#000') . ';">';
            echo '<span class="fw-bold ' . ($isToday ? 'text-dark' : 'text-warning') . '">' . $dayNum . '</span>';
            echo '<small class="' . ($isToday ? 'text-dark' : 'text-warning') . '">' . $currentDate->format('D') . '</small>';
            echo '</div>';
            
            echo '<div class="card-body p-2 bg-white">';
            if (isset($assessmentsByDate[$dayNum])) {
                foreach ($assessmentsByDate[$dayNum] as $assessment) {
                    $assessmentStatus = getAssessmentStatus($assessment, $inProgressAssessment);
                    
                    // Add special styling for in-progress assessment
                    $isThisInProgress = ($inProgressAssessment && $assessment['assessment_id'] == $inProgressAssessment['assessment_id']);
                    $extraClasses = $isThisInProgress ? ' in-progress-item' : '';
                    
                    if ($assessmentStatus['canTake'] || $assessmentStatus['status'] === 'completed') {
                        echo '<a href="' . $assessmentStatus['link'] . '" class="text-decoration-none" title="' . $assessmentStatus['title'] . '">';
                    } else {
                        echo '<div class="text-decoration-none" title="' . $assessmentStatus['title'] . '">';
                    }
                    
                    echo '<div class="assessment-item mb-2 p-2 rounded border' . $extraClasses . '">';
                    echo '<div class="fw-bold text-' . $assessmentStatus['class'] . ' text-truncate small">' . 
                        htmlspecialchars($assessment['subject_name']);
                    
                    // Add status indicators
                    if ($isThisInProgress) {
                        echo ' <i class="fas fa-clock text-warning" title="In Progress"></i>';
                    } elseif ($assessmentStatus['status'] === 'completed') {
                        echo ' <i class="fas fa-check-circle text-success" title="Completed"></i>';
                    } elseif ($assessmentStatus['status'] === 'available') {
                        echo ' <i class="fas fa-play text-warning" title="Available Now"></i>';
                    } elseif ($assessmentStatus['status'] === 'locked') {
                        echo ' <i class="fas fa-lock text-secondary" title="Locked"></i>';
                    } elseif ($assessmentStatus['status'] === 'upcoming_today') {
                        echo ' <i class="fas fa-clock text-info" title="Opens Later Today"></i>';
                    } elseif ($assessmentStatus['status'] === 'late_allowed') {
                        echo ' <i class="fas fa-exclamation-triangle text-info" title="Late Submission"></i>';
                    }
                    
                    echo '</div>';
                    echo '<div class="text-truncate small text-dark">' . 
                        htmlspecialchars($assessment['title']) . '</div>';
                    
                    // Show time information
                    if ($assessment['start_time'] && $assessment['start_time'] !== '00:00:00') {
                        echo '<small class="text-secondary">' . 
                            date('g:i A', strtotime($assessment['start_time']));
                        if ($assessment['end_time'] && $assessment['end_time'] !== '23:59:59') {
                            echo ' - ' . date('g:i A', strtotime($assessment['end_time']));
                        }
                        echo '</small>';
                    } elseif ($assessment['start_time'] === '00:00:00' && $assessment['end_time'] === '23:59:59') {
                        echo '<small class="text-secondary">All Day</small>';
                    }
                    
                    // Show additional status for today's assessments
                    if ($isToday && $assessmentStatus['status'] === 'upcoming_today') {
                        echo '<br><small class="text-info">Opens at ' . 
                            date('g:i A', strtotime($assessment['start_time'])) . '</small>';
                    }
                    
                    echo '</div>';
                    
                    if ($assessmentStatus['canTake'] || $assessmentStatus['status'] === 'completed') {
                        echo '</a>';
                    } else {
                        echo '</div>';
                    }
                }
            } else {
                echo '<div class="text-center py-3">';
                echo '<small class="text-muted">No assessments</small>';
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
            echo '</div>';

            $currentDate->modify('+1 day');
        }
        ?>
    </div>

    <!-- Legend -->
    <div class="mt-4 d-flex justify-content-center flex-wrap gap-3">
        <div class="d-flex align-items-center">
            <div class="status-dot bg-success me-2"></div>
            <small class="text-dark">Completed</small>
        </div>
        <div class="d-flex align-items-center">
            <div class="status-dot bg-warning me-2"></div>
            <small class="text-dark">Available Now</small>
        </div>
        <div class="d-flex align-items-center">
            <div class="status-dot bg-info me-2"></div>
            <small class="text-dark">Opens Later / Late Submission</small>
        </div>
        <div class="d-flex align-items-center">
            <div class="status-dot bg-primary me-2"></div>
            <small class="text-dark">Upcoming</small>
        </div>
        <div class="d-flex align-items-center">
            <div class="status-dot bg-danger me-2"></div>
            <small class="text-dark">Missed / Expired</small>
        </div>
        <div class="d-flex align-items-center">
            <div class="status-dot bg-secondary me-2"></div>
            <small class="text-dark">Locked</small>
        </div>
    </div>
</main>

<style>
.card {
    transition: transform 0.2s;
    background: #ffffff;
}

.card:hover {
    transform: translateY(-2px);
}

.card.today {
    box-shadow: 0 0 15px rgba(255, 193, 7, 0.3) !important;
}

.card-header {
    border-bottom: none;
}

.btn-warning {
    color: #000;
    background: linear-gradient(145deg, #ffc107 0%, #ff6f00 100%);
    border: none;
}

.btn-warning:hover {
    background: linear-gradient(145deg, #ff6f00 0%, #ffc107 100%);
    color: #000;
}

.btn-dark {
    border: none;
}

.assessment-item {
    background: #ffffff;
    transition: all 0.2s;
    border-color: rgba(0, 0, 0, 0.1) !important;
    cursor: default;
}

.assessment-item:hover {
    background: #f8f9fa;
    border-color: rgba(0, 0, 0, 0.2) !important;
}

/* Clickable assessment items */
a .assessment-item {
    cursor: pointer;
}

a .assessment-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* In-progress assessment styling */
.in-progress-item {
    background-color: rgba(255, 193, 7, 0.1) !important;
    border-left: 4px solid #ffc107 !important;
    animation: pulse-highlight 2s infinite;
}

@keyframes pulse-highlight {
    0% { 
        background-color: rgba(255, 193, 7, 0.1); 
    }
    50% { 
        background-color: rgba(255, 193, 7, 0.25); 
    }
    100% { 
        background-color: rgba(255, 193, 7, 0.1); 
    }
}

.status-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.text-warning {
    color: #ffc107 !important;
}

main {
    background: #ffffff;
    min-height: 100vh;
    padding-top: 2rem;
    padding-bottom: 2rem;
}

.text-success {
    color: #28a745 !important;
}

.text-danger {
    color: #dc3545 !important;
}

.text-primary {
    color: #007bff !important;
}

.text-info {
    color: #17a2b8 !important;
}

.text-secondary {
    color: #6c757d !important;
}

.shadow-sm {
    box-shadow: 0 .125rem .25rem rgba(0,0,0,.075) !important;
}

/* Alert styling */
.alert-warning {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border-left: 4px solid #ffc107;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.alert-warning .alert-heading {
    color: #856404;
    font-weight: 600;
}

.alert-warning .btn-warning {
    background: #ffc107;
    border-color: #ffc107;
    color: #000;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.alert-warning .btn-warning:hover {
    background: #e0a800;
    border-color: #d39e00;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

/* Responsive design */
@media (max-width: 768px) {
    .alert-warning .d-flex {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }

    .alert-warning .ms-3 {
        margin-left: 0 !important;
    }

    .assessment-item {
        font-size: 0.875rem;
    }

    .status-dot {
        width: 10px;
        height: 10px;
    }
}

/* Tooltip styling */
[title] {
    cursor: help;
}

/* Disabled/non-clickable items */
div:not(a) .assessment-item {
    opacity: 0.8;
}

div:not(a) .assessment-item:hover {
    transform: none;
    box-shadow: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[title]');
    tooltips.forEach(element => {
        new bootstrap.Tooltip(element);
    });

    // Add click handlers for non-clickable assessment items to show information
    const nonClickableItems = document.querySelectorAll('div:not(a) .assessment-item');
    nonClickableItems.forEach(item => {
        item.addEventListener('click', function(e) {
            const title = this.closest('[title]')?.getAttribute('title');
            if (title) {
                // Show a small toast or alert with the status information
                showStatusInfo(title);
            }
        });
    });

    // Function to show status information
    function showStatusInfo(message) {
        // Create a simple toast notification
        const toast = document.createElement('div');
        toast.className = 'toast-notification';
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #333;
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            font-size: 14px;
            max-width: 300px;
        `;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        // Remove after 3 seconds
        setTimeout(() => {
            if (document.body.contains(toast)) {
                document.body.removeChild(toast);
            }
        }, 3000);
    }

    // Auto-dismiss in-progress alert after 15 seconds
    const inProgressAlert = document.querySelector('.alert-warning[data-bs-dismiss]');
    if (inProgressAlert) {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(inProgressAlert);
            bsAlert.close();
        }, 15000);
    }

    // Add smooth animations for card hover effects
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 4px 15px rgba(0,0,0,0.1)';
            this.style.transition = 'all 0.3s ease';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
        });
    });
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>