<?php
/**
 * Assessment Results Integration Example
 * Shows how to integrate payment options into assessment results page
 * 
 * @author School Management System
 * @date July 24, 2025
 */

session_start();
require_once '../includes/functions.php';
require_once '../includes/PaymentHandlers.php';

// This would be integrated into your existing student/assessment-results.php
$assessmentId = $_GET['id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;

if (!$userId || !$assessmentId) {
    header('Location: ../login.php');
    exit;
}

// Get assessment details (simplified for example)
$db = DatabaseConfig::getInstance()->getConnection();
$stmt = $db->prepare("SELECT * FROM Assessments WHERE assessment_id = ?");
$stmt->execute([$assessmentId]);
$assessment = $stmt->fetch();

// Get student's assessment result
$stmt = $db->prepare("SELECT * FROM StudentAssessments WHERE user_id = ? AND assessment_id = ?");
$stmt->execute([$userId, $assessmentId]);
$result = $stmt->fetch();

if (!$assessment || !$result) {
    die('Assessment not found');
}

// Check payment requirements
$reviewRequiresPayment = AssessmentReviewPayment::requiresPayment($userId, $assessmentId);
$retakeRequiresPayment = AssessmentRetakePayment::requiresPayment($userId, $assessmentId);
$reviewAccess = AssessmentReviewPayment::checkAccess($userId, $assessmentId);
$retakeInfo = AssessmentRetakePayment::getRetakeInfo($userId, $assessmentId);
$retakeEligibility = AssessmentRetakePayment::canUserRetake($userId, $assessmentId);

$reviewPricing = AssessmentReviewPayment::getPricing();
$retakePricing = AssessmentRetakePayment::getPricing();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Results - <?= htmlspecialchars($assessment['title']) ?></title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <!-- Assessment Header -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-chart-bar mr-2"></i>
                            <?= htmlspecialchars($assessment['title']) ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h2 class="text-<?= $result['score'] >= 60 ? 'success' : 'danger' ?>">
                                        <?= number_format($result['score'], 1) ?>%
                                    </h2>
                                    <p class="mb-0">Your Score</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h5><?= $result['correct_answers'] ?>/<?= $result['total_questions'] ?></h5>
                                    <p class="mb-0">Correct Answers</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h5><?= gmdate('H:i:s', $result['time_taken']) ?></h5>
                                    <p class="mb-0">Time Taken</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Action Cards -->
        <div class="row">
            <!-- Review Assessment Card -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-search mr-2"></i>Review Assessment
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            Get detailed feedback with correct answers, explanations, and performance insights.
                        </p>
                        
                        <?php if ($reviewAccess['has_access']): ?>
                            <!-- User has access -->
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Access Granted!</strong>
                                <?php if ($reviewAccess['expires_at']): ?>
                                    <br><small>Expires: <?= date('M j, Y g:i A', strtotime($reviewAccess['expires_at'])) ?></small>
                                <?php endif; ?>
                            </div>
                            <a href="detailed-review.php?id=<?= $assessmentId ?>" class="btn btn-success btn-block">
                                <i class="fas fa-eye mr-2"></i>View Detailed Review
                            </a>
                            
                        <?php elseif ($reviewRequiresPayment): ?>
                            <!-- Payment required -->
                            <div class="alert alert-warning">
                                <i class="fas fa-credit-card mr-2"></i>
                                Payment required: <strong><?= PaymentHandlerUtils::formatCurrency($reviewPricing['amount'], $reviewPricing['currency']) ?></strong>
                            </div>
                            <button type="button" class="btn btn-primary btn-block" onclick="initiateReviewPayment()">
                                <i class="fas fa-credit-card mr-2"></i>
                                Pay to Review (<?= PaymentHandlerUtils::formatCurrency($reviewPricing['amount'], $reviewPricing['currency']) ?>)
                            </button>
                            
                        <?php else: ?>
                            <!-- Free access -->
                            <a href="detailed-review.php?id=<?= $assessmentId ?>" class="btn btn-success btn-block">
                                <i class="fas fa-eye mr-2"></i>View Detailed Review (Free)
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Retake Assessment Card -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="fas fa-redo mr-2"></i>Retake Assessment
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            Get another chance to improve your score and understanding.
                        </p>
                        
                        <!-- Retake Information -->
                        <div class="mb-3">
                            <small class="text-muted">
                                Retakes used: <?= $retakeInfo['retakes_used'] ?>/<?= $retakeInfo['max_retakes'] ?>
                            </small>
                        </div>
                        
                        <?php if ($retakeEligibility['can_retake']): ?>
                            <?php if ($retakeEligibility['requires_payment']): ?>
                                <!-- Payment required for retake -->
                                <div class="alert alert-warning">
                                    <i class="fas fa-credit-card mr-2"></i>
                                    Payment required: <strong><?= PaymentHandlerUtils::formatCurrency($retakePricing['amount'], $retakePricing['currency']) ?></strong>
                                </div>
                                <button type="button" class="btn btn-warning btn-block" onclick="initiateRetakePayment()">
                                    <i class="fas fa-credit-card mr-2"></i>
                                    Pay to Retake (<?= PaymentHandlerUtils::formatCurrency($retakePricing['amount'], $retakePricing['currency']) ?>)
                                </button>
                                
                            <?php else: ?>
                                <!-- Free retake available -->
                                <div class="alert alert-success">
                                    <i class="fas fa-gift mr-2"></i>
                                    <strong>Free retake available!</strong>
                                </div>
                                <a href="take-assessment.php?id=<?= $assessmentId ?>" class="btn btn-success btn-block">
                                    <i class="fas fa-redo mr-2"></i>Retake Assessment (Free)
                                </a>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <!-- No retakes available -->
                            <div class="alert alert-danger">
                                <i class="fas fa-times-circle mr-2"></i>
                                <strong>No retakes available</strong>
                                <br><small>You have used all available retakes for this assessment.</small>
                            </div>
                            <button type="button" class="btn btn-secondary btn-block" disabled>
                                <i class="fas fa-ban mr-2"></i>Retake Not Available
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Basic Results (Always visible) -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list mr-2"></i>Basic Results
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Total Questions:</strong></td>
                                        <td><?= $result['total_questions'] ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Correct Answers:</strong></td>
                                        <td class="text-success"><?= $result['correct_answers'] ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Wrong Answers:</strong></td>
                                        <td class="text-danger"><?= $result['wrong_answers'] ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Score:</strong></td>
                                        <td class="text-<?= $result['score'] >= 60 ? 'success' : 'danger' ?>">
                                            <?= number_format($result['score'], 1) ?>%
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Time Taken:</strong></td>
                                        <td><?= gmdate('H:i:s', $result['time_taken']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Status:</strong></td>
                                        <td class="text-<?= $result['score'] >= 60 ? 'success' : 'danger' ?>">
                                            <?= $result['score'] >= 60 ? 'Passed' : 'Failed' ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle mr-1"></i>
                                For detailed explanations and correct answers, purchase the Review Assessment option above.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Include payment modal -->
    <?php include '../includes/payment-modal.php'; ?>
    
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/js/bootstrap.min.js"></script>
    
    <script>
    function initiateReviewPayment() {
        showPaymentModal(
            'review_assessment',
            'Assessment Review Access',
            'Get detailed feedback with correct answers and explanations',
            <?= $reviewPricing['amount'] ?>,
            '<?= $reviewPricing['currency'] ?>',
            <?= $assessmentId ?>,
            '24 hours of access to detailed review and explanations'
        );
    }
    
    function initiateRetakePayment() {
        showPaymentModal(
            'retake_assessment',
            'Assessment Retake Access',
            'Get another chance to improve your score',
            <?= $retakePricing['amount'] ?>,
            '<?= $retakePricing['currency'] ?>',
            <?= $assessmentId ?>,
            'One additional attempt at this assessment'
        );
    }
    </script>
</body>
</html>