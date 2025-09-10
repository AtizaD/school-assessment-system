    // Global variables for modal instances
    let editQuestionModal, deleteQuestionModal;

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Bootstrap modals
        editQuestionModal = new bootstrap.Modal(document.getElementById('editQuestionModal'));
        deleteQuestionModal = new bootstrap.Modal(document.getElementById('deleteQuestionModal'));
    
        // Initialize question type toggle
        toggleQuestionOptions('MCQ');
    
        // Form validation
        ['addQuestionForm', 'editQuestionForm'].forEach(formId => {
            const form = document.getElementById(formId);
            if (form) {
                form.addEventListener('submit', function(e) {
                    // Clear previous validation state
                    this.classList.remove('was-validated');
                    
                    if (!this.checkValidity() || !validateMCQOptions(this)) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
    
                    this.classList.add('was-validated');
                });
            }
        });
    
        // Initialize event listeners for question type changes
        const questionTypeSelects = document.querySelectorAll('select[name="question_type"]');
        questionTypeSelects.forEach(select => {
            select.addEventListener('change', function() {
                toggleQuestionOptions(this.value);
            });
        });
    
        // Add initial options for MCQ if needed
        const questionType = document.querySelector('select[name="question_type"]');
        if (questionType && questionType.value === 'MCQ') {
            const container = document.getElementById('optionsContainer');
            if (container && container.children.length === 0) {
                addOption();
                addOption();
            }
        }
    
        
    });
    
    // Toggle question type options
    function toggleQuestionOptions(type) {
        const mcqOptions = document.getElementById('mcqOptions');
        const shortAnswer = document.getElementById('shortAnswer');
        const correctOptionInputs = document.querySelectorAll('input[name="correct_option"]');
        const optionsInputs = document.querySelectorAll('input[name="options[]"]');
        const optionsContainer = document.getElementById('optionsContainer');
        
        if (type === 'MCQ') {
            mcqOptions.style.display = 'block';
            shortAnswer.style.display = 'none';
            correctOptionInputs.forEach(input => input.required = true);
            optionsInputs.forEach(input => input.required = true);
            
            // Add initial options if none exist
            if (optionsContainer.children.length === 0) {
                addOption();
                addOption();
            }
        } else {
            mcqOptions.style.display = 'none';
            shortAnswer.style.display = 'block';
            correctOptionInputs.forEach(input => input.required = false);
            optionsInputs.forEach(input => input.required = false);
        }
        
        // Reset answer mode when switching types
        const answerModeSelect = document.querySelector('select[name="answer_mode"]');
        if (answerModeSelect) {
            answerModeSelect.value = 'exact';
            toggleAnswerOptions('exact');
        }
    }
    
    // Toggle answer options based on mode
    function toggleAnswerOptions(mode) {
        const validAnswersContainer = document.getElementById('validAnswersContainer');
        const answerCountContainer = document.getElementById('answerCountContainer');
        const correctAnswerContainer = document.getElementById('correctAnswerContainer');
        
        if (mode === 'any_match') {
            validAnswersContainer.style.display = 'block';
            answerCountContainer.style.display = 'block';
            correctAnswerContainer.style.display = 'none';
            
            // Initialize with at least one answer field
            if (document.querySelector('.valid-answers').children.length === 0) {
                addValidAnswer();
            }
        } else {
            validAnswersContainer.style.display = 'none';
            answerCountContainer.style.display = 'none';
            correctAnswerContainer.style.display = 'block';
        }
    }
    
    // Add valid answer field
    function addValidAnswer() {
        const container = document.querySelector('.valid-answers');
        const inputGroup = document.createElement('div');
        inputGroup.className = 'input-group mb-2';
        inputGroup.innerHTML = `
            <input type="text" name="valid_answers[]" 
                   class="form-control" 
                   placeholder="Enter a valid answer" 
                   required>
            <button type="button" class="btn btn-outline-danger" onclick="removeValidAnswer(this)">
                <i class="fas fa-times"></i>
            </button>
        `;
        container.appendChild(inputGroup);
    }
    
    // Remove valid answer field
    function removeValidAnswer(button) {
        const container = button.closest('.valid-answers');
        if (container.children.length > 1) {
            button.closest('.input-group').remove();
        } else {
            alert('At least one valid answer is required');
        }
    }
    
    // Update number of answer fields based on count
    function updateAnswerFields(count) {
        const container = document.querySelector('.valid-answers');
        const currentCount = container.children.length;
        
        if (count > currentCount) {
            for (let i = currentCount; i < count; i++) {
                addValidAnswer();
            }
        } else if (count < currentCount && count >= 1) {
            while (container.children.length > Math.max(count, 1)) {
                container.lastChild.remove();
            }
        }
    }
    
    // Validate unique answers (client-side)
    function validateUniqueAnswers(input) {
        const answerBoxes = document.querySelectorAll('.answer-input');
        const currentValue = input.value.trim().toLowerCase();
        let duplicateFound = false;
        
        answerBoxes.forEach(box => {
            if (box !== input && box.value.trim().toLowerCase() === currentValue) {
                duplicateFound = true;
            }
        });
        
        if (duplicateFound) {
            input.setCustomValidity('This answer has already been used. Please provide a different answer.');
        } else {
            input.setCustomValidity('');
        }
        input.reportValidity();
    }
    
    // Form validation
    document.addEventListener('DOMContentLoaded', function() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!this.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                form.classList.add('was-validated');
            });
        });
    
        // Initialize answer mode for existing forms
        const answerModeSelects = document.querySelectorAll('select[name="answer_mode"]');
        answerModeSelects.forEach(select => {
            toggleAnswerOptions(select.value);
        });
    });
    // Toggle question type options
    function toggleQuestionOptions(type) {
        const mcqOptions = document.getElementById('mcqOptions');
        const shortAnswer = document.getElementById('shortAnswer');
        const correctOptionInputs = document.querySelectorAll('input[name="correct_option"]');
        const optionsInputs = document.querySelectorAll('input[name="options[]"]');
        
        if (type === 'MCQ') {
            mcqOptions.style.display = 'block';
            shortAnswer.style.display = 'none';
            correctOptionInputs.forEach(input => input.required = true);
            optionsInputs.forEach(input => input.required = true);
            // Add initial option if none exist
            if (document.getElementById('optionsContainer').children.length === 0) {
                addOption();
            }
        } else {
            mcqOptions.style.display = 'none';
            shortAnswer.style.display = 'block';
            correctOptionInputs.forEach(input => input.required = false);
            optionsInputs.forEach(input => input.required = false);
        }
    }
    
    // Add option to new question
    function addOption() {
        const container = document.getElementById('optionsContainer');
        const index = container.children.length;
        
        const optionDiv = document.createElement('div');
        optionDiv.className = 'input-group mb-2';
        optionDiv.innerHTML = `
            <div class="input-group-text">
                <input type="radio" name="correct_option" value="${index}" 
                       class="form-check-input mt-0" required>
            </div>
            <input type="text" name="options[]" class="form-control" 
                   required minlength="1">
            <button type="button" class="btn btn-outline-danger" onclick="removeOption(this)">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.appendChild(optionDiv);
        updateRequiredFields();
    }
    
    // Add option to edit form
    function addEditOption(value = '', isCorrect = false) {
        const container = document.getElementById('editOptionsContainer');
        const index = container.children.length;
        
        const optionDiv = document.createElement('div');
        optionDiv.className = 'input-group mb-2';
        optionDiv.innerHTML = `
            <div class="input-group-text">
                <input type="radio" name="correct_option" value="${index}" 
                       class="form-check-input mt-0" required
                       ${isCorrect ? 'checked' : ''}>
            </div>
            <input type="text" name="options[]" class="form-control" 
                   required minlength="1" value="${value ? escapeHtml(value) : ''}">
            <button type="button" class="btn btn-outline-danger" onclick="removeOption(this)">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.appendChild(optionDiv);
        updateRequiredFields();
    }
    
    // Remove option
    function removeOption(button) {
        const container = button.closest('.input-group').parentElement;
        if (container.children.length > 2) {
            button.closest('.input-group').remove();
            // Update radio values
            container.querySelectorAll('input[type="radio"]').forEach((radio, index) => {
                radio.value = index;
            });
            updateRequiredFields();
        } else {
            alert('MCQ questions must have at least two options');
        }
    }
    
    // Update required fields
    function updateRequiredFields() {
        ['optionsContainer', 'editOptionsContainer'].forEach(containerId => {
            const container = document.getElementById(containerId);
            if (container) {
                const questionType = container.closest('form').querySelector('[name="question_type"]')?.value;
                const inputs = container.querySelectorAll('input[name="options[]"]');
                const radios = container.querySelectorAll('input[name="correct_option"]');
                
                if (questionType === 'MCQ') {
                    inputs.forEach(input => input.required = true);
                    radios.forEach(radio => radio.required = true);
                }
            }
        });
    }
    
    // Validate MCQ options
    function validateMCQOptions(form) {
        const questionType = form.querySelector('[name="question_type"]')?.value;
        if (questionType !== 'MCQ') {
            return true;
        }
    
        const optionInputs = form.querySelectorAll('input[name="options[]"]');
        const radioButtons = form.querySelectorAll('input[name="correct_option"]');
        
        if (optionInputs.length < 2) {
            alert('MCQ questions must have at least two options');
            return false;
        }
    
        let hasEmptyOption = false;
        optionInputs.forEach(input => {
            if (!input.value.trim()) {
                hasEmptyOption = true;
                input.classList.add('is-invalid');
            } else {
                input.classList.remove('is-invalid');
            }
        });
    
        if (hasEmptyOption) {
            alert('All options must have values');
            return false;
        }
    
        const hasCorrectAnswer = Array.from(radioButtons).some(radio => radio.checked);
        if (!hasCorrectAnswer) {
            alert('Please select a correct answer');
            return false;
        }
    
        return true;
    }
    
    // Edit question function
    function editQuestion(question) {
        if (!question || !editQuestionModal) {
            console.error('Missing question data or modal not initialized');
            return;
        }
    
        document.getElementById('editQuestionId').value = question.question_id;
        document.getElementById('editQuestionType').value = question.question_type;
        document.getElementById('editQuestionText').value = question.question_text;
        document.getElementById('editMaxScore').value = question.max_score;
    
        const mcqOptions = document.getElementById('editMcqOptions');
        const shortAnswer = document.getElementById('editShortAnswer');
    
        if (question.question_type === 'MCQ') {
            mcqOptions.style.display = 'block';
            shortAnswer.style.display = 'none';
            
            const container = document.getElementById('editOptionsContainer');
            container.innerHTML = '';
            
            if (question.options) {
                question.options.forEach((option, index) => {
                    addEditOption(option.answer_text, option.is_correct === '1' || option.is_correct === 1);
                });
            } else {
                addEditOption();
                addEditOption();
            }
        } else {
            mcqOptions.style.display = 'none';
            shortAnswer.style.display = 'block';
            document.getElementById('editCorrectAnswer').value = question.correct_answer || '';
        }
    
        try {
            editQuestionModal.show();
        } catch (error) {
            console.error('Error showing modal:', error);
        }
    }
    
    // Delete question function
    function deleteQuestion(questionId) {
        if (!deleteQuestionModal) {
            console.error('Delete modal not initialized');
            return;
        }
        document.getElementById('deleteQuestionId').value = questionId;
        deleteQuestionModal.show();
    }
    
    // Toggle all questions in bank
    function toggleAllQuestions() {
        const checkboxes = document.querySelectorAll('.question-checkbox');
        const selectAll = document.getElementById('selectAll');
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
        updateAddSelectedButton();
    }
    
    // Update add selected button state
    function updateAddSelectedButton() {
        const checkedBoxes = document.querySelectorAll('.question-checkbox:checked');
        document.getElementById('addSelectedBtn').disabled = checkedBoxes.length === 0;
    }
    
    // Escape HTML special characters
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    
    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize question type toggle
        toggleQuestionOptions('MCQ');
    
        // Form validation
        ['addQuestionForm', 'editQuestionForm'].forEach(formId => {
            const form = document.getElementById(formId);
            if (form) {
                form.addEventListener('submit', function(e) {
                    // Clear previous validation state
                    this.classList.remove('was-validated');
                    
                    if (!this.checkValidity() || !validateMCQOptions(this)) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
    
                    this.classList.add('was-validated');
                });
            }
        });
    
        // Question bank checkbox handling
        document.querySelectorAll('.question-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateAddSelectedButton);
        });
    
        // Auto-dismiss alerts
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }, 5000);
        });
    
        // Add initial options for MCQ if needed
        const questionType = document.querySelector('select[name="question_type"]');
        if (questionType && questionType.value === 'MCQ') {
            const container = document.getElementById('optionsContainer');
            if (container && container.children.length === 0) {
                addOption();
                addOption();
            }
        }
    });
    
    // Prevent form resubmission
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }