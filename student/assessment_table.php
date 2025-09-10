<?php
// assessment_table.php
if (!defined('BASEPATH')) exit('No direct script access allowed');

// Check for any in-progress assessment for this student
$hasInProgressAssessment = false;
$inProgressAssessmentId = null;
$inProgressTitle = '';
$inProgressSubject = '';

if (isset($_SESSION['user_id'])) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Get student info
        $stmt = $db->prepare(
            "SELECT s.student_id, s.class_id 
             FROM Students s 
             WHERE s.user_id = ?"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $studentInfo = $stmt->fetch();

        if ($studentInfo) {
            // Check for in-progress assessment
            $stmt = $db->prepare(
                "SELECT aa.assessment_id, a.title, s.subject_name, aa.start_time
                 FROM AssessmentAttempts aa
                 JOIN Assessments a ON aa.assessment_id = a.assessment_id
                 JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
                 JOIN Subjects s ON ac.subject_id = s.subject_id
                 WHERE aa.student_id = ? 
                 AND aa.status = 'in_progress'
                 AND ac.class_id = ?
                 ORDER BY aa.start_time DESC
                 LIMIT 1"
            );
            $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id']]);
            $inProgressAssessment = $stmt->fetch();

            if ($inProgressAssessment) {
                $hasInProgressAssessment = true;
                $inProgressAssessmentId = $inProgressAssessment['assessment_id'];
                $inProgressTitle = $inProgressAssessment['title'];
                $inProgressSubject = $inProgressAssessment['subject_name'];
            }
        }
    } catch (Exception $e) {
        // Log error but don't break the display
        logError("Assessment table in-progress check error: " . $e->getMessage());
    }
}

// Ensure $assessments variable exists
if (!isset($assessments) || !is_array($assessments)): ?>
    <div class="text-center py-4">
        <div class="text-muted">
            <i class="fas fa-clipboard-list fa-2x mb-3"></i>
            <p>No assessments available</p>
        </div>
    </div>
<?php else: ?>
    <?php if ($hasInProgressAssessment): ?>
        <div class="alert alert-info alert-dismissible fade show mb-4" id="inProgressAlert">
            <div class="d-flex align-items-center">
                <i class="fas fa-clock fa-2x me-3 text-warning"></i>
                <div class="flex-grow-1">
                    <h6 class="alert-heading mb-1">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <strong>Assessment in Progress!</strong>
                    </h6>
                    <p class="mb-1">
                        You have an ongoing assessment: <strong>"<?php echo htmlspecialchars($inProgressTitle); ?>"</strong> 
                        in <?php echo htmlspecialchars($inProgressSubject); ?>
                    </p>
                    <p class="mb-0 small text-muted">
                        Please complete your current assessment before starting a new one.
                    </p>
                </div>
                <div class="ms-3">
                    <a href="take_assessment.php?id=<?php echo $inProgressAssessmentId; ?>" 
                       class="btn btn-warning btn-sm">
                        <i class="fas fa-play me-1"></i>Continue Assessment
                    </a>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Subject</th>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Score</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assessments as $assessment): 
                    // Skip if no assessment_id
                    if (empty($assessment['assessment_id'])) continue;
                    
                    // Get current date for comparison - use server time
                    $today = date('Y-m-d');
                    $assessmentDate = isset($assessment['date']) ? date('Y-m-d', strtotime($assessment['date'])) : '';
                    
                    // Check if this assessment is the one in progress
                    $isInProgress = ($hasInProgressAssessment && $assessment['assessment_id'] == $inProgressAssessmentId);
                    $canTakeAssessment = !$hasInProgressAssessment || $isInProgress;
                ?>
                    <tr class="<?php echo $isInProgress ? 'table-warning assessment-in-progress' : ''; ?>">
                        <td>
                            <?php echo htmlspecialchars($assessment['subject_name'] ?? 'N/A'); ?>
                            <?php if ($isInProgress): ?>
                                <span class="badge bg-warning text-dark ms-2">
                                    <i class="fas fa-clock"></i> In Progress
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($assessment['title']); ?>
                            <?php if ($isInProgress): ?>
                                <div class="small text-muted">
                                    <i class="fas fa-info-circle"></i> Continue your assessment</div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo !empty($assessmentDate) ? date('M d, Y', strtotime($assessmentDate)) : 'N/A'; ?></td>
                        <td>
                            <?php if (!empty($assessment['result_status']) && $assessment['result_status'] == 'completed'): ?>
                                <span class="badge bg-success">Completed</span>
                            <?php elseif ($isInProgress): ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-clock"></i> In Progress
                                </span>
                            <?php elseif (!empty($assessmentDate)): ?>
                                <?php if ($assessmentDate < $today): ?>
                                    <span class="badge bg-danger">Missed</span>
                                <?php elseif ($assessmentDate == $today): ?>
                                    <span class="badge bg-info">Due Today</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Upcoming</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary">Unknown</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            if (isset($assessment['score']) && isset($assessment['total_possible']) && $assessment['total_possible'] > 0): 
                                // Calculate percentage from raw score
                                $percentage = ($assessment['score'] / $assessment['total_possible']) * 100;
                                echo number_format($percentage, 1) . '%'; 
                            else: 
                                echo '-';
                            endif; 
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($assessment['result_status']) && $assessment['result_status'] == 'completed'): ?>
                                <a href="view_result.php?id=<?php echo $assessment['assessment_id']; ?>" 
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye me-1"></i>View Result
                                </a>
                            <?php elseif ($isInProgress): ?>
                                <a href="take_assessment.php?id=<?php echo $assessment['assessment_id']; ?>" 
                                   class="btn btn-sm btn-warning">
                                    <i class="fas fa-play me-1"></i>Continue Assessment
                                </a>
                            <?php elseif (!empty($assessmentDate) && $assessmentDate == $today && $canTakeAssessment): ?>
                                <a href="take_assessment.php?id=<?php echo $assessment['assessment_id']; ?>" 
                                   class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-pencil-alt me-1"></i>Take Assessment
                                </a>
                            <?php elseif (!$canTakeAssessment): ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled 
                                        title="Complete your current assessment first"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top">
                                    <i class="fas fa-lock me-1"></i>Assessment Locked
                                </button>
                            <?php elseif (!empty($assessmentDate) && $assessmentDate > $today): ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled>
                                    <i class="fas fa-clock me-1"></i>Not Available
                                </button>
                            <?php elseif (!empty($assessmentDate)): ?>
                                <span class="badge bg-danger">Expired</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Unavailable</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
// Initialize any tooltips and enhance user experience
document.addEventListener('DOMContentLoaded', function() {
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(tooltip => {
        new bootstrap.Tooltip(tooltip);
    });
    
    // Add smooth animation for badges
    document.querySelectorAll('.badge').forEach(badge => {
        badge.style.transition = 'all 0.3s ease';
    });

    // Highlight in-progress assessment row with animation
    const inProgressRows = document.querySelectorAll('.assessment-in-progress');
    inProgressRows.forEach(row => {
        // Add a subtle pulsing effect to draw attention
        row.style.animation = 'pulse-highlight 2s infinite';
        
        // Add click event to navigate to the assessment
        row.style.cursor = 'pointer';
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on buttons or links
            if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A' && !e.target.closest('button, a')) {
                const assessmentId = <?php echo $hasInProgressAssessment ? $inProgressAssessmentId : 'null'; ?>;
                if (assessmentId) {
                    window.location.href = `take_assessment.php?id=${assessmentId}`;
                }
            }
        });
    });

    // Auto-dismiss in-progress alert after 15 seconds
    const inProgressAlert = document.getElementById('inProgressAlert');
    if (inProgressAlert) {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(inProgressAlert);
            bsAlert.close();
        }, 15000);
    }

    // Add warning when trying to start a new assessment while one is in progress
    const lockedButtons = document.querySelectorAll('.btn:disabled[title*="Complete your current assessment"]');
    lockedButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            showAssessmentLockedModal();
        });
    });

    // Function to show assessment locked modal
    function showAssessmentLockedModal() {
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">
                            <i class="fas fa-lock me-2"></i>Assessment Locked
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                            <h5>You have an assessment in progress!</h5>
                            <p>Please complete your current assessment before starting a new one.</p>
                        </div>
                        <div class="alert alert-info">
                            <strong>Current Assessment:</strong><br>
                            <?php echo htmlspecialchars($inProgressTitle); ?> (<?php echo htmlspecialchars($inProgressSubject); ?>)
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <a href="take_assessment.php?id=<?php echo $inProgressAssessmentId; ?>" class="btn btn-warning">
                            <i class="fas fa-play me-2"></i>Continue Assessment
                        </a>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        
        // Remove modal from DOM when hidden
        modal.addEventListener('hidden.bs.modal', function() {
            document.body.removeChild(modal);
        });
    }
});

// Add CSS for the pulse animation and other enhancements
const style = document.createElement('style');
style.textContent = `
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
    
    .assessment-in-progress {
        background-color: rgba(255, 193, 7, 0.1) !important;
        border-left: 4px solid #ffc107 !important;
    }
    
    .assessment-in-progress:hover {
        background-color: rgba(255, 193, 7, 0.2) !important;
        transform: translateX(2px);
        transition: all 0.3s ease;
    }
    
    .btn:disabled {
        cursor: not-allowed;
    }
    
    .btn:disabled[title] {
        cursor: help;
    }
    
    .alert-link {
        text-decoration: none;
        font-weight: bold;
    }
    
    .alert-link:hover {
        text-decoration: underline;
    }
    
    .table-warning {
        --bs-table-accent-bg: rgba(255, 193, 7, 0.1);
    }
    
    .badge {
        font-weight: 500;
        padding: 0.4em 0.6em;
    }
    
    .badge.bg-warning {
        color: #000 !important;
    }
    
    .btn-warning {
        background: linear-gradient(45deg, #ffc107, #ff8f00);
        border: none;
        color: #000;
        font-weight: 500;
    }
    
    .btn-warning:hover {
        background: linear-gradient(45deg, #ff8f00, #ffc107);
        color: #000;
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    
    /* Enhanced alert styling */
    .alert-info {
        background: linear-gradient(135deg, #cce5ff 0%, #e6f3ff 100%);
        border-left: 4px solid #0066cc;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .alert-info .alert-heading {
        color: #0066cc;
        font-weight: 600;
    }
    
    /* Tooltip styling */
    .tooltip .tooltip-inner {
        background-color: #333;
        color: #fff;
        border-radius: 4px;
        padding: 8px 12px;
        font-size: 0.875rem;
        max-width: 200px;
    }
    
    /* Mobile responsive adjustments */
    @media (max-width: 768px) {
        .assessment-in-progress:hover {
            transform: none;
        }
        
        .alert-info .d-flex {
            flex-direction: column;
            gap: 1rem;
        }
        
        .alert-info .ms-3 {
            margin-left: 0 !important;
            text-align: center;
        }
        
        .table-responsive {
            font-size: 0.875rem;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        .badge {
            font-size: 0.7rem;
            padding: 0.3em 0.5em;
        }
    }
    
    /* Print styles */
    @media print {
        .alert-info,
        .btn,
        .assessment-in-progress {
            background: none !important;
            color: #000 !important;
            border: 1px solid #ccc !important;
        }
        
        .badge {
            border: 1px solid #000;
            background: none !important;
            color: #000 !important;
        }
    }
`;
document.head.appendChild(style);
</script>