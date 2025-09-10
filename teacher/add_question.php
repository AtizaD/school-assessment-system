<?php
// teacher/add_question.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Assessment.php';

requireRole('teacher');

$error = '';
$success = '';
$assessmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$assessmentId) {
    redirectTo('assessments.php');
}

// Initialize variables for editing
$isEditing = false;
$questionData = null;
$questionId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get teacher ID
    $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherId = $stmt->fetch()['teacher_id'];

    // Get assessment details with authorization check
    $stmt = $db->prepare(
        "SELECT a.*, c.class_name, s.subject_name
         FROM assessments a
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN classes c ON ac.class_id = c.class_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         JOIN teacherclassassignments tca ON c.class_id = tca.class_id AND ac.subject_id = tca.subject_id
         WHERE a.assessment_id = ? AND tca.teacher_id = ? AND a.status = 'pending'"
    );
    $stmt->execute([$assessmentId, $teacherId]);
    $assessment = $stmt->fetch();

    if (!$assessment) {
        throw new Exception('Assessment not found, unauthorized, or already completed');
    }

    // Handle question editing
    if ($questionId) {
        // Check if the question exists and belongs to this assessment
        $stmt = $db->prepare(
            "SELECT q.*, 
                   (SELECT GROUP_CONCAT(CONCAT(mcq.mcq_id, ':', mcq.answer_text, ':', mcq.is_correct) SEPARATOR '|')
                    FROM mcqquestions mcq 
                    WHERE mcq.question_id = q.question_id) as mcq_options
            FROM questions q
            WHERE q.question_id = ? AND q.assessment_id = ?"
        );
        $stmt->execute([$questionId, $assessmentId]);
        $questionData = $stmt->fetch();
        
        if (!$questionData) {
            throw new Exception('Question not found or does not belong to this assessment');
        }
        
        
        
        // Get image info if exists
        if (!empty($questionData['image_id'])) {
            $stmt = $db->prepare(
                "SELECT filename FROM assessment_images WHERE image_id = ?"
            );
            $stmt->execute([$questionData['image_id']]);
            $imageData = $stmt->fetch();
            if ($imageData) {
                $questionData['image_url'] = BASE_URL . '/assets/assessment_images/' . $imageData['filename'];
            }
        }
        
        $isEditing = true;
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid request');
        }

        $db->beginTransaction();

        try {
            $questionText = sanitizeInput($_POST['question_text']);
            $questionType = sanitizeInput($_POST['question_type']);
            $maxScore = filter_var($_POST['max_score'], FILTER_VALIDATE_FLOAT);
            $answerCount = filter_var($_POST['answer_count'] ?? 1, FILTER_VALIDATE_INT);
            $answerMode = sanitizeInput($_POST['answer_mode'] ?? 'exact');
            $imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);
            $editingQuestionId = filter_input(INPUT_POST, 'editing_question_id', FILTER_VALIDATE_INT);
            
            // Fix: Convert empty or invalid imageId to NULL
            $imageId = ($imageId === false || $imageId === null || $imageId === 0) ? null : $imageId;

            // Validate inputs
            if ($maxScore <= 0 || $maxScore > 100) {
                throw new Exception('Invalid score value');
            }

            if ($answerCount < 1) {
                throw new Exception('At least one answer is required');
            }

            // Check if we're updating or creating
            $isUpdate = ($editingQuestionId > 0);

            // Handle different question types
            if ($questionType === 'MCQ') {
                // Validate MCQ options
                if (!isset($_POST['options']) || count($_POST['options']) < 2) {
                    throw new Exception('MCQ questions must have at least 2 options');
                }

                if (!isset($_POST['correct_option'])) {
                    throw new Exception('Please select a correct answer');
                }

                if ($isUpdate) {
                    // Update existing question
                    $stmt = $db->prepare(
                        "UPDATE questions SET
                            question_text = ?,
                            question_type = ?,
                            max_score = ?,
                            image_id = ?
                        WHERE question_id = ? AND assessment_id = ?"
                    );
                    
                    $stmt->execute([
                        $questionText,
                        $questionType,
                        $maxScore,
                        $imageId,
                        $editingQuestionId,
                        $assessmentId
                    ]);
                    
                    // Delete existing MCQ options
                    $stmt = $db->prepare("DELETE FROM mcqquestions WHERE question_id = ?");
                    $stmt->execute([$editingQuestionId]);
                    
                    $questionId = $editingQuestionId;
                } else {
                    // Insert new question
                    $stmt = $db->prepare(
                        "INSERT INTO questions (
                            assessment_id,
                            question_text,
                            question_type,
                            max_score,
                            answer_count,
                            answer_mode,
                            multiple_answers_allowed,
                            image_id
                        ) VALUES (?, ?, ?, ?, 1, 'exact', 0, ?)"
                    );
                    
                    $stmt->execute([
                        $assessmentId,
                        $questionText,
                        $questionType,
                        $maxScore,
                        $imageId
                    ]);

                    $questionId = $db->lastInsertId();
                }

                // Insert MCQ options
                $stmt = $db->prepare(
                    "INSERT INTO mcqquestions (
                        question_id,
                        answer_text,
                        is_correct
                    ) VALUES (?, ?, ?)"
                );

                foreach ($_POST['options'] as $index => $option) {
                    $isCorrect = isset($_POST['correct_option']) && 
                               $_POST['correct_option'] == $index ? 1 : 0;
                    $stmt->execute([
                        $questionId,
                        sanitizeInput($option),
                        $isCorrect
                    ]);
                }

            } else {
                // Handle Short Answer type
                if ($answerMode === 'any_match') {
                    // Validate and process multiple valid answers
                    $validAnswers = array_filter(
                        array_map('trim', $_POST['valid_answers'] ?? [])
                    );
                    
                    if (empty($validAnswers)) {
                        throw new Exception('At least one valid answer is required');
                    }

                    $correctAnswer = json_encode($validAnswers);
                    $multipleAnswers = 1;
                } else {
                    // Single exact answer
                    $correctAnswer = sanitizeInput($_POST['correct_answer']);
                    if (empty($correctAnswer)) {
                        throw new Exception('Correct answer is required');
                    }
                    $multipleAnswers = 0;
                }

                if ($isUpdate) {
                    // Update short answer question
                    $stmt = $db->prepare(
                        "UPDATE questions SET
                            question_text = ?,
                            question_type = ?,
                            correct_answer = ?,
                            max_score = ?,
                            answer_count = ?,
                            answer_mode = ?,
                            multiple_answers_allowed = ?,
                            image_id = ?
                        WHERE question_id = ? AND assessment_id = ?"
                    );
                    
                    $stmt->execute([
                        $questionText,
                        $questionType,
                        $correctAnswer,
                        $maxScore,
                        $answerCount,
                        $answerMode,
                        $multipleAnswers,
                        $imageId,
                        $editingQuestionId,
                        $assessmentId
                    ]);
                } else {
                    // Insert short answer question
                    $stmt = $db->prepare(
                        "INSERT INTO questions (
                            assessment_id,
                            question_text,
                            question_type,
                            correct_answer,
                            max_score,
                            answer_count,
                            answer_mode,
                            multiple_answers_allowed,
                            image_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    
                    $stmt->execute([
                        $assessmentId,
                        $questionText,
                        $questionType,
                        $correctAnswer,
                        $maxScore,
                        $answerCount,
                        $answerMode,
                        $multipleAnswers,
                        $imageId
                    ]);
                }
            }

            $db->commit();
            $_SESSION['success'] = $isUpdate ? 'Question updated successfully' : 'Question added successfully';
            header("Location: manage_questions.php?id=" . $assessmentId);
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Add question error: " . $e->getMessage());
}

$pageTitle = $isEditing ? 'Edit Question' : 'Add Question';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="h3 mb-1 text-warning"><?php echo $isEditing ? 'Edit' : 'Add New'; ?> Question</h1>
            <p class="text-muted">
                <?php echo htmlspecialchars($assessment['title']); ?> | 
                <?php echo htmlspecialchars($assessment['subject_name']); ?> | 
                <?php echo htmlspecialchars($assessment['class_name']); ?> |
                <?php echo date('F d, Y', strtotime($assessment['date'])); ?>
            </p>
        </div>
        <div class="col-md-4 text-md-end">
            <a href="manage_questions.php?id=<?php echo $assessmentId; ?>" class="btn btn-warning">
                <i class="fas fa-arrow-left me-1"></i> Back to Questions
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="addQuestionForm">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="image_id" id="imageId" value="<?php echo $isEditing && isset($questionData['image_id']) ? $questionData['image_id'] : ''; ?>">
        <input type="hidden" name="editing_question_id" value="<?php echo $isEditing ? $questionId : ''; ?>">

        <div class="row">
            <div class="col-md-8">
                <!-- Top row with Question Type and Point -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="question-section" id="question-type-section">
                            <div class="section-header">Question type</div>
                            <div class="section-content">
                                <select name="question_type" class="form-select" required onchange="toggleQuestionOptions(this.value)">
                                    <option value="MCQ" <?php echo ($isEditing && $questionData['question_type'] === 'MCQ') ? 'selected' : ''; ?>>Multiple Choice</option>
                                    <option value="Short Answer" <?php echo ($isEditing && $questionData['question_type'] === 'Short Answer') ? 'selected' : ''; ?>>Short Answer</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="question-section" id="point-section">
                            <div class="section-header">Point</div>
                            <div class="section-content">
                                <input type="number" name="max_score" class="form-control" min="0.5" max="100" step="0.5" value="<?php echo $isEditing ? $questionData['max_score'] : '1'; ?>" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Question Text Entry -->
                <div class="question-section" id="question-text-section">
                    <div class="section-header">Enter question here</div>
                    <div class="section-content">
                        <textarea name="question_text" class="form-control" rows="3" required><?php echo $isEditing ? htmlspecialchars($questionData['question_text']) : ''; ?></textarea>
                    </div>
                </div>

                <!-- Answer Options -->
                <div class="question-section" id="answer-options-section">
                    <div class="section-header">Answer Options/Answer settings</div>
                    <div class="section-content">
                        <!-- MCQ Options Container -->
                        <div id="mcqOptions">
                            <div id="optionsContainer"></div>
                            <div class="d-grid mt-3">
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="addOption()">
                                    <i class="fas fa-plus me-1"></i>Add Option
                                </button>
                            </div>
                        </div>

                        <!-- Short Answer Settings -->
                        <div id="shortAnswer" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Answer Type</label>
                                <select name="answer_mode" class="form-select" onchange="toggleAnswerOptions(this.value)">
                                    <option value="exact" <?php echo ($isEditing && $questionData['answer_mode'] === 'exact') ? 'selected' : ''; ?>>Exact Match</option>
                                    <option value="any_match" <?php echo ($isEditing && $questionData['answer_mode'] === 'any_match') ? 'selected' : ''; ?>>Any Valid Answer</option>
                                </select>
                            </div>

                            <div id="answerCountContainer" style="display: none;">
                                <div class="mb-3">
                                    <label class="form-label">Number of Required Answers</label>
                                    <input type="number" name="answer_count" class="form-control" min="1" 
                                           value="<?php echo ($isEditing && isset($questionData['answer_count'])) ? $questionData['answer_count'] : '1'; ?>" 
                                           onchange="updateAnswerFields(this.value)">
                                </div>
                            </div>

                            <div id="validAnswersContainer" style="display: none;">
                                <div class="mb-3">
                                    <label class="form-label">Valid Answers</label>
                                    <div class="valid-answers">
                                    <?php 
                                    if ($isEditing && $questionData['answer_mode'] === 'any_match' && !empty($questionData['correct_answer'])) {
                                        $validAnswers = json_decode($questionData['correct_answer'], true);
                                    }
                                    ?>
                                    </div>
                                    <div class="d-grid mt-2">
                                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="addValidAnswer()">
                                            <i class="fas fa-plus me-1"></i>Add Answer
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div id="correctAnswerContainer">
                                <div class="mb-3">
                                    <label class="form-label">Correct Answer</label>
                                    <textarea name="correct_answer" class="form-control" rows="2" placeholder="Enter the exact correct answer"><?php 
                                    echo ($isEditing && $questionData['answer_mode'] === 'exact' && isset($questionData['correct_answer'])) ? 
                                        htmlspecialchars($questionData['correct_answer']) : ''; 
                                    ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Image Preview -->
                <div class="question-section" id="image-preview-section">
                    <div class="section-header bg-dark">Image preview</div>
                    <div class="section-content" id="preview-container">
                        <div id="imagePlaceholder" <?php echo ($isEditing && !empty($questionData['image_url'])) ? 'style="display: none;"' : ''; ?>>
                            <i class="fas fa-image fa-3x text-muted"></i>
                            <p class="text-muted mt-2">No image selected</p>
                        </div>
                        <div id="imagePreview" <?php echo ($isEditing && !empty($questionData['image_url'])) ? '' : 'style="display: none;"'; ?>>
                            <img src="<?php echo ($isEditing && !empty($questionData['image_url'])) ? $questionData['image_url'] : ''; ?>" class="img-fluid preview-image" alt="Question Image">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeImage('edit')">
                                    <i class="fas fa-times"></i> Remove Image
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Browse Button -->
                <div class="question-section" id="browse-section">
                    <div class="section-header">Browse</div>
                    <div class="section-content">
                        <input type="file" name="question_image" id="questionImage" class="form-control" accept="image/jpeg,image/png,image/gif">
                        <div class="progress mt-2" style="height: 5px; display: none;">
                            <div class="progress-bar bg-warning" role="progressbar" style="width: 0%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-warning btn-lg">
                <i class="fas fa-save me-1"></i><?php echo $isEditing ? 'Update' : 'Save'; ?> Question
            </button>
        </div>
    </form>
</div>

<script>
    // Base URL configuration
const BASE_URL = '<?php echo BASE_URL; ?>';

/**
 * Handle question image uploads
 */
function uploadQuestionImage(fileInput) {
    if (!fileInput.files || !fileInput.files[0]) {
        return;
    }
    
    const file = fileInput.files[0];
    const formData = new FormData();
    formData.append('image', file);
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    
    const progressBar = document.querySelector('.progress');
    const progressBarInner = progressBar.querySelector('.progress-bar');
    
    progressBar.style.display = 'block';
    progressBarInner.style.width = '0%';
    
    const imagePlaceholder = document.getElementById('imagePlaceholder');
    const imagePreview = document.getElementById('imagePreview');
    const previewImage = imagePreview.querySelector('img');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            imagePlaceholder.style.display = 'none';
            imagePreview.style.display = 'block';
        }
        reader.readAsDataURL(file);
    }
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${BASE_URL}/api/upload_question_image.php`, true);
    
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const percentComplete = (e.loaded / e.total) * 100;
            progressBarInner.style.width = `${percentComplete}%`;
        }
    };
    
    xhr.onload = function() {
        progressBar.style.display = 'none';
        
        if (xhr.status === 200) {
            let response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                alert('Error parsing server response');
                return;
            }
            
            if (response.status === 'success') {
                document.getElementById('imageId').value = response.data.image_id;
            } else {
                alert('Upload error: ' + response.message);
                imagePlaceholder.style.display = 'block';
                imagePreview.style.display = 'none';
            }
        } else {
            alert('Upload failed. Please try again.');
            imagePlaceholder.style.display = 'block';
            imagePreview.style.display = 'none';
        }
    };
    
    xhr.onerror = function() {
        progressBar.style.display = 'none';
        alert('Upload failed. Please check your connection.');
        imagePlaceholder.style.display = 'block';
        imagePreview.style.display = 'none';
    };
    
    xhr.send(formData);
}

/**
 * Remove the currently uploaded image
 */
function removeImage() {
    document.getElementById('imageId').value = '';
    document.getElementById('questionImage').value = '';
    
    document.getElementById('imagePlaceholder').style.display = 'block';
    document.getElementById('imagePreview').style.display = 'none';
}

/**
 * Toggle question options based on question type
 */
function toggleQuestionOptions(questionType) {
    const shortAnswerDiv = document.getElementById('shortAnswer');
    const mcqOptionsDiv = document.getElementById('mcqOptions');
    
    if (questionType === 'MCQ') {
        shortAnswerDiv.style.display = 'none';
        mcqOptionsDiv.style.display = 'block';
        
        const mcqInputs = mcqOptionsDiv.querySelectorAll('input[name="options[]"]');
        mcqInputs.forEach(input => {
            input.required = true;
        });
        
        const shortAnswerInputs = shortAnswerDiv.querySelectorAll('input, textarea');
        shortAnswerInputs.forEach(input => {
            input.required = false;
        });
        
        const optionsContainer = document.getElementById('optionsContainer');
        if (optionsContainer.children.length < 2) {
            addOption();
            addOption();
        }
    } else {
        shortAnswerDiv.style.display = 'block';
        mcqOptionsDiv.style.display = 'none';
        
        const mcqInputs = mcqOptionsDiv.querySelectorAll('input[name="options[]"]');
        mcqInputs.forEach(input => {
            input.required = false;
        });
        
        const correctAnswerField = document.querySelector('textarea[name="correct_answer"]');
        if (correctAnswerField) {
            correctAnswerField.required = true;
        }
        
        const answerModeSelect = document.querySelector('#shortAnswer select[name="answer_mode"]');
        if (answerModeSelect) {
            toggleAnswerOptions(answerModeSelect.value);
        }
    }
}

/**
 * Toggle answer options based on answer mode for Short Answer questions
 */
function toggleAnswerOptions(answerMode) {
    const answerCountContainer = document.getElementById('answerCountContainer');
    const validAnswersContainer = document.getElementById('validAnswersContainer');
    const correctAnswerContainer = document.getElementById('correctAnswerContainer');
    
    if (answerMode === 'any_match') {
        answerCountContainer.style.display = 'block';
        validAnswersContainer.style.display = 'block';
        correctAnswerContainer.style.display = 'none';
        
        const correctAnswerField = document.querySelector('textarea[name="correct_answer"]');
        if (correctAnswerField) {
            correctAnswerField.required = false;
        }
        
        const validAnswersDiv = validAnswersContainer.querySelector('.valid-answers');
        if (validAnswersDiv && validAnswersDiv.children.length === 0) {
            addValidAnswer();
        }
    } else {
        answerCountContainer.style.display = 'none';
        validAnswersContainer.style.display = 'none';
        correctAnswerContainer.style.display = 'block';
        
        const correctAnswerField = document.querySelector('textarea[name="correct_answer"]');
        if (correctAnswerField) {
            correctAnswerField.required = true;
        }
        
        const validAnswersFields = document.querySelectorAll('input[name="valid_answers[]"]');
        validAnswersFields.forEach(field => {
            field.required = false;
        });
    }
}

/**
 * Update the number of answer fields for multi-answer questions
 */
function updateAnswerFields(count) {
    // Implementation left empty - functionality maintained
}

/**
 * Add a new MCQ option
 */
function addOption() {
    const optionsContainer = document.getElementById('optionsContainer');
    const optionCount = optionsContainer.children.length;
    
    const optionRow = document.createElement('div');
    optionRow.className = 'input-group mb-2';
    
    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'options[]';
    input.className = 'form-control';
    input.placeholder = `Option ${optionCount + 1}`;
    input.required = true;
    
    const radioLabel = document.createElement('div');
    radioLabel.className = 'input-group-text';
    
    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.name = 'correct_option';
    radio.value = optionCount;
    radio.className = 'form-check-input me-1';
    radio.required = true;
    
    const label = document.createElement('span');
    label.textContent = 'Correct';
    
    radioLabel.appendChild(radio);
    radioLabel.appendChild(label);
    
    const buttonContainer = document.createElement('div');
    buttonContainer.className = 'input-group-text';
    
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-sm btn-outline-danger';
    removeBtn.innerHTML = '<i class="fas fa-times"></i>';
    
    removeBtn.addEventListener('click', function() {
        if (optionsContainer.children.length > 2) {
            optionsContainer.removeChild(optionRow);
            updateOptionIndices();
        } else {
            alert('MCQ questions must have at least 2 options');
        }
    });
    
    buttonContainer.appendChild(removeBtn);
    
    optionRow.appendChild(input);
    optionRow.appendChild(radioLabel);
    optionRow.appendChild(buttonContainer);
    
    optionsContainer.appendChild(optionRow);
    
    if (optionCount === 0) {
        radio.checked = true;
    }
    
    input.focus();
}

/**
 * Update the indices of all options after one is removed
 */
function updateOptionIndices() {
    const optionsContainer = document.getElementById('optionsContainer');
    const options = optionsContainer.querySelectorAll('.input-group');
    options.forEach((option, idx) => {
        const radioInput = option.querySelector('input[type="radio"]');
        if (radioInput) {
            radioInput.value = idx;
        }
    });
}

/**
 * Add a valid answer input for Short Answer questions
 */
function addValidAnswer(value = '') {
    const validAnswersContainer = document.querySelector('#validAnswersContainer .valid-answers');
    
    const answerRow = document.createElement('div');
    answerRow.className = 'input-group mb-2';
    
    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'valid_answers[]';
    input.className = 'form-control';
    input.placeholder = 'Enter valid answer';
    input.value = value;
    input.required = true;
    
    const buttonContainer = document.createElement('div');
    buttonContainer.className = 'input-group-text';
    
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-sm btn-outline-danger';
    removeBtn.innerHTML = '<i class="fas fa-times"></i>';
    
    removeBtn.addEventListener('click', function() {
        if (validAnswersContainer.children.length > 1) {
            validAnswersContainer.removeChild(answerRow);
        } else {
            alert('At least one valid answer is required');
        }
    });
    
    buttonContainer.appendChild(removeBtn);
    
    answerRow.appendChild(input);
    answerRow.appendChild(buttonContainer);
    
    validAnswersContainer.appendChild(answerRow);
    
    input.focus();
}

// Initialize the page when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const isEditing = <?php echo $isEditing ? 'true' : 'false'; ?>;
    
    if (isEditing) {
        const questionType = '<?php echo $isEditing ? $questionData['question_type'] : 'MCQ'; ?>';
        toggleQuestionOptions(questionType);
        
        <?php if ($isEditing && $questionData['question_type'] === 'MCQ' && !empty($questionData['mcq_options'])): ?>
            const optionsContainer = document.getElementById('optionsContainer');
            optionsContainer.innerHTML = '';
            
            <?php
            $mcqOptions = [];
            $correctOptionIndex = 0;
            if (!empty($questionData['mcq_options'])) {
                $optionsParts = explode('|', $questionData['mcq_options']);
                foreach ($optionsParts as $index => $option) {
                    if (!empty($option)) {
                        list($id, $text, $isCorrect) = explode(':', $option);
                        $mcqOptions[] = [
                            'id' => $id, 
                            'text' => $text, 
                            'is_correct' => $isCorrect
                        ];
                        if ($isCorrect) {
                            $correctOptionIndex = $index;
                        }
                    }
                }
            }
            foreach ($mcqOptions as $index => $option):
            ?>
            const optionRow<?php echo $index; ?> = document.createElement('div');
            optionRow<?php echo $index; ?>.className = 'input-group mb-2';
            
            const input<?php echo $index; ?> = document.createElement('input');
            input<?php echo $index; ?>.type = 'text';
            input<?php echo $index; ?>.name = 'options[]';
            input<?php echo $index; ?>.className = 'form-control';
            input<?php echo $index; ?>.placeholder = 'Option <?php echo $index + 1; ?>';
            input<?php echo $index; ?>.value = '<?php echo addslashes(htmlspecialchars($option['text'])); ?>';
            input<?php echo $index; ?>.required = true;

            const radioLabel<?php echo $index; ?> = document.createElement('div');
            radioLabel<?php echo $index; ?>.className = 'input-group-text';

            const radio<?php echo $index; ?> = document.createElement('input');
            radio<?php echo $index; ?>.type = 'radio';
            radio<?php echo $index; ?>.name = 'correct_option';
            radio<?php echo $index; ?>.value = <?php echo $index; ?>;
            radio<?php echo $index; ?>.className = 'form-check-input me-1';
            radio<?php echo $index; ?>.required = true;

            <?php if ($option['is_correct']): ?>
            radio<?php echo $index; ?>.checked = true;
            <?php endif; ?>

            const label<?php echo $index; ?> = document.createElement('span');
            label<?php echo $index; ?>.textContent = 'Correct';

            radioLabel<?php echo $index; ?>.appendChild(radio<?php echo $index; ?>);
            radioLabel<?php echo $index; ?>.appendChild(label<?php echo $index; ?>);

            const buttonContainer<?php echo $index; ?> = document.createElement('div');
            buttonContainer<?php echo $index; ?>.className = 'input-group-text';

            const removeBtn<?php echo $index; ?> = document.createElement('button');
            removeBtn<?php echo $index; ?>.type = 'button';
            removeBtn<?php echo $index; ?>.className = 'btn btn-sm btn-outline-danger';
            removeBtn<?php echo $index; ?>.innerHTML = '<i class="fas fa-times"></i>';

            removeBtn<?php echo $index; ?>.addEventListener('click', function() {
                if (optionsContainer.children.length > 2) {
                    optionsContainer.removeChild(optionRow<?php echo $index; ?>);
                    updateOptionIndices();
                } else {
                    alert('MCQ questions must have at least 2 options');
                }
            });

            buttonContainer<?php echo $index; ?>.appendChild(removeBtn<?php echo $index; ?>);

            optionRow<?php echo $index; ?>.appendChild(input<?php echo $index; ?>);
            optionRow<?php echo $index; ?>.appendChild(radioLabel<?php echo $index; ?>);
            optionRow<?php echo $index; ?>.appendChild(buttonContainer<?php echo $index; ?>);

            optionsContainer.appendChild(optionRow<?php echo $index; ?>);
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if ($isEditing && $questionData['question_type'] === 'Short Answer'): ?>
            const answerModeSelect = document.querySelector('#shortAnswer select[name="answer_mode"]');
            answerModeSelect.value = '<?php echo $questionData['answer_mode']; ?>';
            toggleAnswerOptions('<?php echo $questionData['answer_mode']; ?>');
            
            <?php if ($isEditing && $questionData['answer_mode'] === 'any_match' && !empty($questionData['correct_answer'])): ?>
                const validAnswersContainer = document.querySelector('#validAnswersContainer .valid-answers');
                validAnswersContainer.innerHTML = '';
                
                <?php 
                $validAnswers = json_decode($questionData['correct_answer'], true);
                if (is_array($validAnswers)):
                    foreach ($validAnswers as $answer):
                ?>
                addValidAnswer('<?php echo addslashes(htmlspecialchars($answer)); ?>');
                <?php 
                    endforeach; 
                endif;
                ?>
            <?php endif; ?>
        <?php endif; ?>
    } else {
        addOption();
        addOption();
    }
    
    const addQuestionForm = document.getElementById('addQuestionForm');
    if (addQuestionForm) {
        addQuestionForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!this.checkValidity()) {
                this.classList.add('was-validated');
                return;
            }
            
            const questionType = document.querySelector('select[name="question_type"]').value;
            
            if (questionType === 'MCQ') {
                const options = document.querySelectorAll('input[name="options[]"]');
                if (options.length < 2) {
                    alert('MCQ questions must have at least 2 options');
                    return;
                }
                
                const correctOption = document.querySelector('input[name="correct_option"]:checked');
                if (!correctOption) {
                    alert('Please select a correct answer for the MCQ question');
                    return;
                }
            } else {
                const answerMode = document.querySelector('select[name="answer_mode"]').value;
                
                if (answerMode === 'any_match') {
                    const validAnswers = document.querySelectorAll('input[name="valid_answers[]"]');
                    let hasValidAnswer = false;
                    
                    validAnswers.forEach(input => {
                        if (input.value.trim()) {
                            hasValidAnswer = true;
                        }
                    });
                    
                    if (!hasValidAnswer) {
                        alert('Please provide at least one valid answer');
                        return;
                    }
                } else {
                    const correctAnswer = document.querySelector('textarea[name="correct_answer"]').value;
                    if (!correctAnswer.trim()) {
                        alert('Please enter a correct answer for the short answer question');
                        return;
                    }
                }
            }
            
            this.submit();
        });
    }
    
    const questionImage = document.getElementById('questionImage');
    if (questionImage) {
        questionImage.addEventListener('change', function() {
            uploadQuestionImage(this);
        });
    }
});
</script>

<style>
/* Black and Gold color scheme styling */
.question-section {
    margin-bottom: 15px;
    border: 1px solid #000;
}

.section-header {
    background: linear-gradient(90deg, #000000 0%, #333333 100%);
    color: #ffd700;
    font-weight: 500;
    padding: 10px 15px;
    text-align: center;
}

.section-content {
    padding: 15px;
    background-color: white;
}

/* Custom styling for the image preview */
#preview-container {
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    text-align: center;
}

.preview-image {
    max-height: 200px;
    max-width: 100%;
    border: 2px solid #ffd700;
    border-radius: 4px;
}

#imagePlaceholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
}

/* Button styling */
.btn-warning {
    background: linear-gradient(45deg, #000000 0%, #ffd700 100%);
    border: none;
    color: white;
    transition: all 0.3s ease;
}

.btn-warning:hover {
    background: linear-gradient(45deg, #ffd700 0%, #000000 100%);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.btn-outline-warning {
    color: #000;
    border-color: #ffd700;
    background: transparent;
}

.btn-outline-warning:hover {
    background: linear-gradient(45deg, #000000 0%, #ffd700 100%);
    color: white;
}

/* Form control styling */
.form-control:focus, .form-select:focus {
    border-color: #ffd700;
    box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.25);
}

/* Progress bar styling */
.progress-bar.bg-warning {
    background-color: #ffd700 !important;
}

/* Text color */
.text-warning {
    color: #ffd700 !important;
}

/* Mobile responsiveness */
@media (max-width: 767.98px) {
    .question-section {
        margin-bottom: 10px;
    }
    
    .section-header {
        padding: 8px 12px;
    }
    
    .section-content {
        padding: 10px;
    }
    
    #preview-container {
        min-height: 150px;
    }
    
    .preview-image {
        max-height: 150px;
    }
}
</style>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>