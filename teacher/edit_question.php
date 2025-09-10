<?php
// teacher/edit_question.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Assessment.php';

requireRole('teacher');

$error = '';
$success = '';
$assessmentId = filter_input(INPUT_GET, 'assessment_id', FILTER_VALIDATE_INT);
$questionId = filter_input(INPUT_GET, 'question_id', FILTER_VALIDATE_INT);

if (!$assessmentId || !$questionId) {
    redirectTo('assessments.php');
}

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

    // Get question details
    $stmt = $db->prepare(
        "SELECT q.*, 
                (SELECT COUNT(*) FROM studentanswers WHERE question_id = q.question_id) as answer_count
         FROM questions q
         WHERE q.question_id = ? AND q.assessment_id = ?"
    );
    $stmt->execute([$questionId, $assessmentId]);
    $question = $stmt->fetch();

    if (!$question) {
        throw new Exception('Question not found or does not belong to this assessment');
    }

   

    // Get additional question data based on type
    if ($question['question_type'] === 'MCQ') {
        $stmt = $db->prepare(
            "SELECT * FROM mcqquestions 
             WHERE question_id = ? 
             ORDER BY mcq_id"
        );
        $stmt->execute([$questionId]);
        $question['options'] = $stmt->fetchAll();
    } elseif ($question['answer_mode'] === 'any_match') {
        $question['valid_answers'] = json_decode($question['correct_answer'], true);
    }
    
    // Get image info if exists
    if (!empty($question['image_id'])) {
        $stmt = $db->prepare(
            "SELECT filename FROM assessment_images 
             WHERE image_id = ?"
        );
        $stmt->execute([$question['image_id']]);
        $imageData = $stmt->fetch();
        if ($imageData) {
            $question['image_url'] = BASE_URL . '/assets/assessment_images/' . $imageData['filename'];
        }
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid request');
        }

        $db->beginTransaction();

        try {
            $questionText = sanitizeInput($_POST['question_text']);
            $maxScore = filter_var($_POST['max_score'], FILTER_VALIDATE_FLOAT);
            $questionType = sanitizeInput($_POST['question_type']);
            $imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);
            
            // Fix: Convert empty or invalid imageId to NULL
            $imageId = ($imageId === false || $imageId === null || $imageId === 0) ? null : $imageId;

            if ($questionType === 'MCQ') {
                // Update MCQ question
                $stmt = $db->prepare(
                    "UPDATE questions 
                     SET question_text = ?,
                         max_score = ?,
                         answer_count = 1,
                         answer_mode = 'exact',
                         multiple_answers_allowed = 0,
                         image_id = ?
                     WHERE question_id = ?"
                );
                $stmt->execute([$questionText, $maxScore, $imageId, $questionId]);

                // Update MCQ options
                $stmt = $db->prepare("DELETE FROM mcqquestions WHERE question_id = ?");
                $stmt->execute([$questionId]);

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
                    $stmt->execute([$questionId, sanitizeInput($option), $isCorrect]);
                }
            } else {
                // Update Short Answer question
                $answerMode = sanitizeInput($_POST['answer_mode']);
                $answerCount = filter_var($_POST['answer_count'] ?? 1, FILTER_VALIDATE_INT);

                if ($answerMode === 'any_match') {
                    $validAnswers = array_filter(
                        array_map('trim', $_POST['valid_answers'] ?? [])
                    );
                    $correctAnswer = json_encode($validAnswers);
                    $multipleAnswers = 1;
                } else {
                    $correctAnswer = sanitizeInput($_POST['correct_answer']);
                    $multipleAnswers = 0;
                }

                $stmt = $db->prepare(
                    "UPDATE questions 
                     SET question_text = ?,
                         correct_answer = ?,
                         max_score = ?,
                         answer_count = ?,
                         answer_mode = ?,
                         multiple_answers_allowed = ?,
                         image_id = ?
                     WHERE question_id = ?"
                );
                
                $stmt->execute([
                    $questionText,
                    $correctAnswer,
                    $maxScore,
                    $answerCount,
                    $answerMode,
                    $multipleAnswers,
                    $imageId,
                    $questionId
                ]);
            }

            $db->commit();
            $_SESSION['success'] = 'Question updated successfully';
            header("Location: manage_questions.php?id=" . $assessmentId);
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Edit question error: " . $e->getMessage());
}

$pageTitle = 'Edit Question';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">Edit Question</h1>
            <p class="text-muted mb-0">
                Assessment: <?php echo htmlspecialchars($assessment['title']); ?> | 
                <?php echo htmlspecialchars($assessment['subject_name']); ?> | 
                <?php echo htmlspecialchars($assessment['class_name']); ?> |
                <?php echo date('F d, Y', strtotime($assessment['date'])); ?>
            </p>
        </div>
        <div>
            <a href="manage_questions.php?id=<?php echo $assessmentId; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Questions
            </a>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Edit Question Form -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" id="editQuestionForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="question_type" value="<?php echo htmlspecialchars($question['question_type']); ?>">
                <input type="hidden" name="image_id" id="imageId" value="<?php echo htmlspecialchars($question['image_id'] ?? ''); ?>">

                <!-- Question Text Section -->
                <div class="section-card">
                    <h6 class="section-title">Question Details</h6>
                    <div class="mb-3">
                        <label class="form-label">Question Type</label>
                        <div class="form-control-plaintext fw-bold">
                            <?php echo htmlspecialchars($question['question_type']); ?>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Question Text</label>
                        <textarea name="question_text" class="form-control" rows="3" required><?php echo htmlspecialchars($question['question_text']); ?></textarea>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i> You can use plain text or format with KaTeX math notation using $ symbols.
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Points</label>
                        <input type="number" name="max_score" class="form-control" 
                              min="0.5" max="100" step="0.5" value="<?php echo htmlspecialchars($question['max_score']); ?>" required>
                        <div class="form-text">
                            <i class="fas fa-star me-1"></i> How many points is this question worth?
                        </div>
                    </div>
                </div>
                
                <!-- Question Image Section -->
                <div class="section-card">
                    <h6 class="section-title">Question Image</h6>
                    <div class="image-upload-container">
                        <div class="current-image" id="currentImage" style="display:<?php echo !empty($question['image_url']) ? 'block' : 'none'; ?>;">
                            <img src="<?php echo !empty($question['image_url']) ? htmlspecialchars($question['image_url']) : ''; ?>" 
                                 alt="Question Image" class="img-fluid mb-2" style="max-height: 200px;">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeImage()">
                                <i class="fas fa-times me-1"></i> Remove Image
                            </button>
                        </div>
                        <div class="upload-area">
                            <label class="form-label">Update Image (Optional)</label>
                            <input type="file" name="question_image" id="questionImage" class="form-control"
                                  accept="image/jpeg,image/png,image/gif">
                            <div class="form-text">
                                <i class="fas fa-image me-1"></i> Supported formats: JPG, PNG, GIF. Max size: 5MB
                            </div>
                            <div class="progress mt-2" style="height: 5px; display: none;">
                                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($question['question_type'] === 'MCQ'): ?>
                <!-- MCQ Options -->
                <div id="mcqOptions" class="section-card">
                    <h6 class="section-title">Answer Options</h6>
                    <div class="mb-3">
                        <label class="form-label">Options <span class="text-danger">*</span></label>
                        <div id="optionsContainer">
                            <?php foreach ($question['options'] as $index => $option): ?>
                                <div class="option-row d-flex mb-2">
                                    <div class="flex-grow-1 me-2">
                                        <input type="text" name="options[]" class="form-control" 
                                               value="<?php echo htmlspecialchars($option['answer_text']); ?>" required>
                                    </div>
                                    <div class="form-check align-self-center">
                                        <input type="radio" name="correct_option" value="<?php echo $index; ?>" 
                                               class="form-check-input" id="option<?php echo $index; ?>"
                                               <?php echo $option['is_correct'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label ms-1" for="option<?php echo $index; ?>">Correct</label>
                                    </div>
                                    <?php if (count($question['options']) > 2): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm delete-option">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex justify-content-end mt-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addOption()">
                                <i class="fas fa-plus me-1"></i>Add Option
                            </button>
                        </div>
                        <div class="form-text mt-2">
                            <i class="fas fa-info-circle me-1"></i> Select one option as the correct answer.
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Short Answer Settings -->
                <div id="shortAnswer" class="section-card">
                    <h6 class="section-title">Answer Settings</h6>
                    <div class="mb-3">
                        <label class="form-label">Answer Type</label>
                        <select name="answer_mode" class="form-select" onchange="toggleAnswerOptions(this.value)">
                            <option value="exact" <?php echo $question['answer_mode'] === 'exact' ? 'selected' : ''; ?>>Exact Match</option>
                            <option value="any_match" <?php echo $question['answer_mode'] === 'any_match' ? 'selected' : ''; ?>>Any Valid Answer</option>
                        </select>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i> "Exact Match" requires the student to provide the exact answer. "Any Valid Answer" allows multiple correct answers.
                        </div>
                    </div>

                    <div id="answerCountContainer" style="display: <?php echo $question['answer_mode'] === 'any_match' ? 'block' : 'none'; ?>;">
                        <div class="mb-3">
                            <label class="form-label">Number of Required Answers</label>
                            <input type="number" name="answer_count" class="form-control" 
                                   min="1" value="<?php echo htmlspecialchars($question['answer_count'] ?? 1); ?>">
                            <div class="form-text">
                                <i class="fas fa-list-ol me-1"></i> How many answers should the student provide?
                            </div>
                        </div>
                    </div>

                    <div id="validAnswersContainer" style="display: <?php echo $question['answer_mode'] === 'any_match' ? 'block' : 'none'; ?>;">
                        <div class="mb-3">
                            <label class="form-label">Valid Answers</label>
                            <div class="valid-answers">
                                <?php if ($question['answer_mode'] === 'any_match' && !empty($question['valid_answers'])): ?>
                                    <?php foreach ($question['valid_answers'] as $index => $answer): ?>
                                        <div class="answer-row d-flex mb-2">
                                            <div class="flex-grow-1 me-2">
                                                <input type="text" name="valid_answers[]" class="form-control" 
                                                       value="<?php echo htmlspecialchars($answer); ?>" required>
                                            </div>
                                            <button type="button" class="btn btn-outline-danger btn-sm delete-answer">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex justify-content-end mt-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" 
                                        onclick="addValidAnswer()">
                                    <i class="fas fa-plus me-1"></i>Add Answer
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="correctAnswerContainer" style="display: <?php echo $question['answer_mode'] === 'exact' ? 'block' : 'none'; ?>;">
                        <div class="mb-3">
                            <label class="form-label">Correct Answer</label>
                            <textarea name="correct_answer" class="form-control" rows="2"><?php 
                                if ($question['answer_mode'] === 'exact') {
                                    echo htmlspecialchars($question['correct_answer']);
                                }
                            ?></textarea>
                            <div class="form-text">
                                <i class="fas fa-check-circle me-1"></i> Case-insensitive comparison will be used.
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form Buttons -->
                <div class="d-flex justify-content-end mt-4">
                    <a href="manage_questions.php?id=<?php echo $assessmentId; ?>" class="btn btn-secondary me-2">
                        <i class="fas fa-times me-1"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<style>
/* Question image styles */
.question-image {
    margin: 15px 0;
    text-align: center;
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
}

.question-img {
    max-width: 100%;
    max-height: 300px;
    border: 1px solid #ddd;
}

.image-upload-container {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
}

.current-image {
    text-align: center;
    margin-bottom: 15px;
    padding: 10px;
    background: white;
    border-radius: 5px;
    border: 1px solid #eee;
}

.upload-area {
    margin-top: 10px;
}

/* Animation for image loading */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.question-img {
    animation: fadeIn 0.5s ease;
}

/* Form Elements Styling */
.form-label {
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #444;
}

.form-control, .form-select {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 0.75rem;
    box-shadow: none;
    transition: all 0.2s ease;
}

.form-control:focus, .form-select:focus {
    border-color: #ffd700;
    box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.25);
}

.form-text {
    color: #6c757d;
    font-size: 0.85rem;
    margin-top: 0.5rem;
}

/* Question Section Styling */
.section-card {
    background-color: #f8f9fa;
    border-radius: 10px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e5e5e5;
    transition: all 0.3s ease;
}

.section-card:hover {
    background-color: #f5f5f5;
    border-color: #ddd;
}

.section-title {
    font-weight: 600;
    color: #444;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #ffd700;
    display: inline-block;
}

/* Image Upload Styling */
.image-upload-container {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 1.25rem;
    border: 1px dashed #ccc;
    transition: all 0.3s ease;
}

.image-upload-container:hover {
    border-color: #ffd700;
    background-color: #fffdf0;
}

.current-image {
    text-align: center;
    margin-bottom: 1rem;
    padding: 1rem;
    background: white;
    border-radius: 8px;
    border: 1px solid #eee;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
}

.upload-area {
    margin-top: 0.75rem;
}

/* Button Styling */
.btn-primary {
    background: linear-gradient(90deg, #000000 0%, #ffd700 100%);
    border: none;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: linear-gradient(90deg, #ffd700 0%, #000000 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 8px rgba(0, 0, 0, 0.15);
}

.btn-secondary {
    background: #f8f9fa;
    color: #333;
    border: 1px solid #ddd;
    transition: all 0.3s ease;
}

.btn-secondary:hover {
    background: #e9ecef;
}

.btn-outline-primary {
    border-color: #ffd700;
    color: #333;
    transition: all 0.3s ease;
}

.btn-outline-primary:hover {
    background-color: #ffd700;
    border-color: #ffd700;
    color: #000;
}

.btn-outline-danger {
    transition: all 0.3s ease;
}

.btn-outline-danger:hover {
    transform: translateY(-1px);
}

/* MCQ Options Styling */
.option-row {
    background-color: white;
    padding: 0.75rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    transition: all 0.2s ease;
    margin-bottom: 0.75rem !important;
}

.option-row:hover {
    border-color: #ffd700;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

.option-row .form-check-input[type="radio"] {
    width: 1.2rem;
    height: 1.2rem;
    margin-top: 0.15rem;
}

.option-row .form-check-input[type="radio"]:checked {
    background-color: #ffd700;
    border-color: #ffd700;
}

.option-row .form-check-label {
    font-weight: 500;
    color: #555;
}

/* Valid Answers Styling */
.answer-row {
    background-color: white;
    padding: 0.75rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    transition: all 0.2s ease;
    margin-bottom: 0.75rem !important;
}

.answer-row:hover {
    border-color: #ffd700;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

/* Progress Bar Styling */
.progress {
    height: 0.5rem;
    border-radius: 1rem;
    background-color: #e9ecef;
    margin-top: 1rem;
}

.progress-bar {
    background: linear-gradient(90deg, #ffd700 0%, #ff8c00 100%);
    border-radius: 1rem;
}

/* Mobile Responsive Adjustments */
@media (max-width: 768px) {
    .section-card {
        padding: 1rem;
    }
}
</style>

<script>
// Base URL for API endpoints
const BASE_URL = '<?php echo BASE_URL; ?>';

// Function to upload question image
function uploadQuestionImage(fileInput, callback) {
    if (!fileInput.files || !fileInput.files[0]) {
        return;
    }
    
    const file = fileInput.files[0];
    const formData = new FormData();
    formData.append('image', file);
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    
    const progressBar = fileInput.closest('.image-upload-container').querySelector('.progress');
    const progressBarInner = progressBar.querySelector('.progress-bar');
    
    // Show progress bar
    progressBar.style.display = 'block';
    progressBarInner.style.width = '0%';
    
    // Send AJAX request
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${BASE_URL}/api/upload_question_image.php`, true);
    
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const percentComplete = (e.loaded / e.total) * 100;
            progressBarInner.style.width = percentComplete + '%';
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
                // Show the uploaded image
                const currentImage = document.getElementById('currentImage');
                currentImage.style.display = 'block';
                currentImage.querySelector('img').src = response.data.url;
                
                // Set the image ID in the hidden field
                document.getElementById('imageId').value = response.data.image_id;
                
                if (typeof callback === 'function') {
                    callback(response.data);
                }
            } else {
                alert('Upload error: ' + response.message);
            }
        } else {
            alert('Upload failed. Please try again.');
        }
    };
    
    xhr.onerror = function() {
        progressBar.style.display = 'none';
        alert('Upload failed. Please check your connection and try again.');
    };
    
    xhr.send(formData);
}

// Function to remove image
function removeImage() {
    const currentImage = document.getElementById('currentImage');
    currentImage.style.display = 'none';
    currentImage.querySelector('img').src = '';
    document.getElementById('imageId').value = '';
    document.getElementById('questionImage').value = '';
}

// Function to toggle answer options based on answer mode
function toggleAnswerOptions(answerMode) {
    const answerCountContainer = document.getElementById('answerCountContainer');
    const validAnswersContainer = document.getElementById('validAnswersContainer');
    const correctAnswerContainer = document.getElementById('correctAnswerContainer');
    
    if (answerMode === 'any_match') {
        answerCountContainer.style.display = 'block';
        validAnswersContainer.style.display = 'block';
        correctAnswerContainer.style.display = 'none';
        
        // Ensure we have at least one valid answer input
        const validAnswersDiv = validAnswersContainer.querySelector('.valid-answers');
        if (validAnswersDiv && validAnswersDiv.children.length === 0) {
            addValidAnswer();
        }
    } else {
        answerCountContainer.style.display = 'none';
        validAnswersContainer.style.display = 'none';
        correctAnswerContainer.style.display = 'block';
    }
}

// Function to add MCQ option
function addOption() {
    const optionsContainer = document.getElementById('optionsContainer');
    const optionCount = optionsContainer.children.length;
    
    const optionRow = document.createElement('div');
    optionRow.className = 'option-row d-flex mb-2';
    
    const optionInput = document.createElement('div');
    optionInput.className = 'flex-grow-1 me-2';
    
    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'options[]';
    input.className = 'form-control';
    input.placeholder = 'Enter option ' + (optionCount + 1);
    input.required = true;
    
    optionInput.appendChild(input);
    
    const optionRadio = document.createElement('div');
    optionRadio.className = 'form-check align-self-center';
    
    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.name = 'correct_option';
    radio.value = optionCount;
    radio.className = 'form-check-input';
    radio.id = 'option' + optionCount;
    
    const label = document.createElement('label');
    label.htmlFor = 'option' + optionCount;
    label.className = 'form-check-label ms-1';
    label.textContent = 'Correct';
    
    optionRadio.appendChild(radio);
    optionRadio.appendChild(label);
    
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-outline-danger btn-sm';
    removeBtn.innerHTML = '<i class="fas fa-times"></i>';
    removeBtn.onclick = function() {
        // Only allow deletion if there are more than 2 options
        if (optionsContainer.children.length > 2) {
            optionsContainer.removeChild(optionRow);
            
            // Update option indices
            const options = optionsContainer.querySelectorAll('.option-row');
            options.forEach((option, idx) => {
                const radioInput = option.querySelector('input[type="radio"]');
                if (radioInput) {
                    radioInput.value = idx;
                    radioInput.id = 'option' + idx;
                    const radioLabel = option.querySelector('label');
                    if (radioLabel) {
                        radioLabel.htmlFor = 'option' + idx;
                    }
                }
            });
        } else {
            alert('MCQ questions must have at least 2 options');
        }
    };
    
    optionRow.appendChild(optionInput);
    optionRow.appendChild(optionRadio);
    optionRow.appendChild(removeBtn);
    
    optionsContainer.appendChild(optionRow);
}

// Function to add a valid answer input
function addValidAnswer() {
    const validAnswersContainer = document.querySelector('.valid-answers');
    
    const answerRow = document.createElement('div');
    answerRow.className = 'answer-row d-flex mb-2';
    
    const answerInput = document.createElement('div');
    answerInput.className = 'flex-grow-1 me-2';
    
    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'valid_answers[]';
    input.className = 'form-control';
    input.placeholder = 'Enter valid answer';
    input.required = true;
    
    answerInput.appendChild(input);
    
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-outline-danger btn-sm';
    removeBtn.innerHTML = '<i class="fas fa-times"></i>';
    removeBtn.onclick = function() {
        // Only allow deletion if there is more than 1 answer
        if (validAnswersContainer.children.length > 1) {
            validAnswersContainer.removeChild(answerRow);
        } else {
            alert('At least one valid answer is required');
        }
    };
    
    answerRow.appendChild(answerInput);
    answerRow.appendChild(removeBtn);
    
    validAnswersContainer.appendChild(answerRow);
}

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    // Set up form submission and validation
    const editQuestionForm = document.getElementById('editQuestionForm');
    if (editQuestionForm) {
        editQuestionForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Check if form is valid
            if (!this.checkValidity()) {
                this.classList.add('was-validated');
                return;
            }
            
            // Form is valid, submit
            this.submit();
        });
    }
    
    // Add event listener for file input
    const questionImage = document.getElementById('questionImage');
    if (questionImage) {
        questionImage.addEventListener('change', function() {
            uploadQuestionImage(this);
        });
    }
    
    // Add event listeners for delete option buttons
    document.querySelectorAll('.delete-option').forEach(button => {
        button.addEventListener('click', function() {
            const optionRow = this.closest('.option-row');
            const optionsContainer = document.getElementById('optionsContainer');
            
            // Only allow deletion if there are more than 2 options
            if (optionsContainer.children.length > 2) {
                optionsContainer.removeChild(optionRow);
                
                // Update option indices
                const options = optionsContainer.querySelectorAll('.option-row');
                options.forEach((option, idx) => {
                    const radioInput = option.querySelector('input[type="radio"]');
                    if (radioInput) {
                        radioInput.value = idx;
                        radioInput.id = 'option' + idx;
                        
                        const radioLabel = option.querySelector('label');
                        if (radioLabel) {
                            radioLabel.htmlFor = 'option' + idx;
                        }
                    }
                });
            } else {
                alert('MCQ questions must have at least 2 options');
            }
        });
    });
    
    // Add event listeners for delete answer buttons
    document.querySelectorAll('.delete-answer').forEach(button => {
        button.addEventListener('click', function() {
            const answerRow = this.closest('.answer-row');
            const validAnswersContainer = document.querySelector('.valid-answers');
            
            // Only allow deletion if there is more than 1 answer
            if (validAnswersContainer.children.length > 1) {
                validAnswersContainer.removeChild(answerRow);
            } else {
                alert('At least one valid answer is required');
            }
        });
    });
    
    // Show auto-dismissing alerts after a timeout
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            }
        }, 5000); // Auto dismiss after 5 seconds
    });
});