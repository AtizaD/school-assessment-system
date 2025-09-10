<?php
// admin/assessment_types.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request';
    } else {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        try {
            switch ($_POST['action']) {
                case 'update_weights':
                    // Validate that weights total 100%
                    $totalWeight = 0;
                    $weights = [];
                    
                    foreach ($_POST['weights'] as $typeId => $weight) {
                        $weight = floatval($weight);
                        if ($weight < 0 || $weight > 100) {
                            throw new Exception('Weight must be between 0 and 100');
                        }
                        $weights[$typeId] = $weight;
                        $totalWeight += $weight;
                    }
                    
                    if (abs($totalWeight - 100.0) > 0.01) {
                        throw new Exception("Total weight must equal 100%. Current total: {$totalWeight}%");
                    }
                    
                    // Update weights
                    $stmt = $db->prepare("UPDATE assessment_types SET weight_percentage = ? WHERE type_id = ?");
                    foreach ($weights as $typeId => $weight) {
                        $stmt->execute([$weight, $typeId]);
                    }
                    
                    $success = 'Assessment type weights updated successfully!';
                    logSystemActivity('Assessment Types', 'Updated assessment type weights', 'INFO', $_SESSION['user_id']);
                    break;
                    
                case 'add_type':
                    $typeName = sanitizeInput($_POST['type_name']);
                    $description = sanitizeInput($_POST['description']);
                    $weight = floatval($_POST['weight_percentage']);
                    
                    if (empty($typeName)) {
                        throw new Exception('Assessment type name is required');
                    }
                    
                    if ($weight < 0 || $weight > 100) {
                        throw new Exception('Weight must be between 0 and 100');
                    }
                    
                    // Get next sort order
                    $stmt = $db->query("SELECT MAX(sort_order) as max_order FROM assessment_types");
                    $maxOrder = $stmt->fetch()['max_order'] ?? 0;
                    
                    $stmt = $db->prepare(
                        "INSERT INTO assessment_types (type_name, description, weight_percentage, sort_order) 
                         VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$typeName, $description, $weight, $maxOrder + 1]);
                    
                    $success = "Assessment type '{$typeName}' added successfully!";
                    logSystemActivity('Assessment Types', "Added new assessment type: {$typeName}", 'INFO', $_SESSION['user_id']);
                    break;
                    
                case 'toggle_status':
                    $typeId = intval($_POST['type_id']);
                    $isActive = intval($_POST['is_active']);
                    
                    $stmt = $db->prepare("UPDATE assessment_types SET is_active = ? WHERE type_id = ?");
                    $stmt->execute([$isActive, $typeId]);
                    
                    $status = $isActive ? 'activated' : 'deactivated';
                    $success = "Assessment type {$status} successfully!";
                    logSystemActivity('Assessment Types', "Assessment type {$status}: ID {$typeId}", 'INFO', $_SESSION['user_id']);
                    break;
                    
                case 'delete_type':
                    $typeId = intval($_POST['type_id']);
                    
                    // Check if type is being used
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM assessments WHERE assessment_type_id = ?");
                    $stmt->execute([$typeId]);
                    $count = $stmt->fetch()['count'];
                    
                    if ($count > 0) {
                        throw new Exception("Cannot delete assessment type. It is being used by {$count} assessment(s).");
                    }
                    
                    $stmt = $db->prepare("DELETE FROM assessment_types WHERE type_id = ?");
                    $stmt->execute([$typeId]);
                    
                    $success = 'Assessment type deleted successfully!';
                    logSystemActivity('Assessment Types', "Deleted assessment type: ID {$typeId}", 'INFO', $_SESSION['user_id']);
                    break;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            logError("Assessment types management error: " . $e->getMessage());
        }
    }
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get all assessment types
    $stmt = $db->query("
        SELECT at.*, 
               COUNT(a.assessment_id) as assessment_count
        FROM assessment_types at
        LEFT JOIN assessments a ON at.type_id = a.assessment_type_id
        GROUP BY at.type_id
        ORDER BY at.sort_order
    ");
    $assessmentTypes = $stmt->fetchAll();
    
    // Calculate total weight
    $totalWeight = array_sum(array_column($assessmentTypes, 'weight_percentage'));
    
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Assessment types page load error: " . $e->getMessage());
}

$pageTitle = 'Assessment Types Management';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    :root {
        --primary-yellow: #ffd700;
        --dark-yellow: #ccac00;
        --light-yellow: #fff9e6;
        --primary-black: #000000;
    }

    .page-container {
        padding: 1.5rem;
        background: linear-gradient(135deg, #f8f9fa 0%, #fff9e6 100%);
        min-height: 100vh;
    }

    .header-card {
        background: linear-gradient(90deg, var(--primary-black) 0%, var(--primary-yellow) 100%);
        padding: 1.5rem;
        border-radius: 8px;
        color: white;
        margin-bottom: 2rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .types-card {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .weight-input {
        width: 80px;
        text-align: center;
    }

    .total-weight {
        font-size: 1.2rem;
        font-weight: bold;
        padding: 0.75rem 1rem;
        border-radius: 5px;
        text-align: center;
    }

    .total-valid {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .total-invalid {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .btn-custom {
        background: linear-gradient(45deg, var(--primary-black), var(--primary-yellow));
        border: none;
        color: white;
        transition: all 0.3s ease;
    }

    .btn-custom:hover {
        transform: translateY(-1px);
        color: white;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.875rem;
    }

    .badge-active {
        background: #d4edda;
        color: #155724;
    }

    .badge-inactive {
        background: #f8d7da;
        color: #721c24;
    }
</style>

<div class="page-container">
    <!-- Header -->
    <div class="header-card">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-1">Assessment Types Management</h1>
                <p class="mb-0">Configure assessment types and their grade weights</p>
            </div>
            <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addTypeModal">
                <i class="fas fa-plus me-2"></i>Add New Type
            </button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Assessment Types List -->
    <div class="types-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="mb-0">Assessment Types & Weights</h5>
            <div class="total-weight <?php echo abs($totalWeight - 100) < 0.01 ? 'total-valid' : 'total-invalid'; ?>">
                Total Weight: <?php echo number_format($totalWeight, 1); ?>%
            </div>
        </div>

        <form method="POST" id="weightsForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="update_weights">
            
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Type Name</th>
                            <th>Description</th>
                            <th class="text-center">Weight (%)</th>
                            <th class="text-center">Assessments</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assessmentTypes as $type): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($type['type_name']); ?></strong>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($type['description']); ?>
                                </small>
                            </td>
                            <td class="text-center">
                                <input type="number" 
                                       name="weights[<?php echo $type['type_id']; ?>]" 
                                       value="<?php echo $type['weight_percentage']; ?>"
                                       class="form-control weight-input" 
                                       min="0" max="100" step="0.1"
                                       <?php echo $type['is_active'] ? '' : 'disabled'; ?>>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info"><?php echo $type['assessment_count']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="status-badge <?php echo $type['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo $type['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                            onclick="toggleStatus(<?php echo $type['type_id']; ?>, <?php echo $type['is_active'] ? 0 : 1; ?>)">
                                        <i class="fas fa-<?php echo $type['is_active'] ? 'pause' : 'play'; ?>"></i>
                                    </button>
                                    <?php if ($type['assessment_count'] == 0): ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm" 
                                            onclick="deleteType(<?php echo $type['type_id']; ?>, '<?php echo htmlspecialchars($type['type_name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Total weight must equal 100% to save changes
                </small>
                <button type="submit" class="btn btn-custom" id="saveWeights" disabled>
                    <i class="fas fa-save me-2"></i>Save Weights
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Type Modal -->
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Assessment Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="add_type">
                    
                    <div class="mb-3">
                        <label class="form-label">Type Name</label>
                        <input type="text" name="type_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Weight Percentage</label>
                        <input type="number" name="weight_percentage" class="form-control" 
                               min="0" max="100" step="0.1" value="0">
                        <div class="form-text">You can adjust this later with other types</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-custom">Add Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden forms for actions -->
<form id="toggleStatusForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="type_id" id="toggleTypeId">
    <input type="hidden" name="is_active" id="toggleIsActive">
</form>

<form id="deleteTypeForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="delete_type">
    <input type="hidden" name="type_id" id="deleteTypeId">
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const weightInputs = document.querySelectorAll('.weight-input');
    const saveButton = document.getElementById('saveWeights');
    const totalWeightDiv = document.querySelector('.total-weight');

    function updateTotalWeight() {
        let total = 0;
        weightInputs.forEach(input => {
            if (!input.disabled) {
                total += parseFloat(input.value) || 0;
            }
        });
        
        totalWeightDiv.textContent = `Total Weight: ${total.toFixed(1)}%`;
        
        if (Math.abs(total - 100) < 0.01) {
            totalWeightDiv.className = 'total-weight total-valid';
            saveButton.disabled = false;
        } else {
            totalWeightDiv.className = 'total-weight total-invalid';
            saveButton.disabled = true;
        }
    }

    weightInputs.forEach(input => {
        input.addEventListener('input', updateTotalWeight);
    });

    // Initial calculation
    updateTotalWeight();
});

function toggleStatus(typeId, isActive) {
    document.getElementById('toggleTypeId').value = typeId;
    document.getElementById('toggleIsActive').value = isActive;
    document.getElementById('toggleStatusForm').submit();
}

function deleteType(typeId, typeName) {
    if (confirm(`Are you sure you want to delete the assessment type "${typeName}"?`)) {
        document.getElementById('deleteTypeId').value = typeId;
        document.getElementById('deleteTypeForm').submit();
    }
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>