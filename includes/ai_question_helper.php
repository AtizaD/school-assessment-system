<?php
// AI Question Helper Component
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

// Only show for teachers
if ($_SESSION['user_role'] !== 'teacher') {
    return;
}
?>

<!-- AI Question Helper Sidebar -->
<div id="ai-helper-sidebar" class="ai-helper-closed">
    <!-- Toggle Button -->
    <button id="ai-helper-toggle" class="ai-helper-toggle">
        <i class="fas fa-robot"></i>
        <span class="ai-helper-text">AI Assistant</span>
    </button>
    
    <!-- Sidebar Content -->
    <div class="ai-helper-content">
        <div class="ai-helper-header">
            <div class="ai-helper-title">
                <i class="fas fa-robot"></i>
                <h4>AI Question Helper</h4>
            </div>
            <button id="ai-helper-close" class="ai-helper-close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="ai-helper-body">
            <!-- Navigation Tabs -->
            <div class="ai-tabs">
                <button class="ai-tab active" data-tab="assessments">
                    <i class="fas fa-tasks"></i>
                    <span>Assessments</span>
                </button>
                <button class="ai-tab" data-tab="questions">
                    <i class="fas fa-question-circle"></i>
                    <span>Questions</span>
                </button>
                <button class="ai-tab" data-tab="bulk">
                    <i class="fas fa-file-upload"></i>
                    <span>Bulk Import</span>
                </button>
            </div>
            
            <!-- Assessment Tab -->
            <div id="tab-assessments" class="ai-tab-content active">
                <div class="ai-section">
                    <h5><i class="fas fa-eye"></i> My Assessments</h5>
                    <div id="assessments-list" class="scrollable-list">
                        <div class="loading-indicator">
                            <i class="fas fa-spinner fa-spin"></i>
                            Loading assessments...
                        </div>
                    </div>
                </div>
                
                <div class="ai-section">
                    <h5><i class="fas fa-plus"></i> Create Assessment</h5>
                    <form id="create-assessment-form">
                        <div class="form-group">
                            <label>Assessment Title</label>
                            <input type="text" id="assessment-title" class="form-control" placeholder="Enter title">
                        </div>
                        <div class="form-group">
                            <label>Subject & Class</label>
                            <select id="subject-class" class="form-control">
                                <option value="">Select Subject & Class</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Assessment Date</label>
                            <input type="date" id="assessment-date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>AI Prompt (Optional)</label>
                            <textarea id="assessment-prompt" class="form-control" rows="3" 
                                placeholder="Describe what you want the AI to focus on for this assessment..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-magic"></i> Create with AI
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Questions Tab -->
            <div id="tab-questions" class="ai-tab-content">
                <div class="ai-section">
                    <h5><i class="fas fa-search"></i> Select Assessment</h5>
                    <select id="question-assessment" class="form-control">
                        <option value="">Choose an assessment</option>
                    </select>
                </div>
                
                <div id="questions-management" style="display: none;">
                    <div class="ai-section">
                        <h5><i class="fas fa-list"></i> Current Questions</h5>
                        <div id="current-questions" class="scrollable-list">
                            <!-- Questions will be loaded here -->
                        </div>
                    </div>
                    
                    <div class="ai-section">
                        <h5><i class="fas fa-plus"></i> Add Question</h5>
                        <form id="add-question-form">
                            <div class="form-group">
                                <label>Question Type</label>
                                <select id="question-type" class="form-control">
                                    <option value="MCQ">Multiple Choice</option>
                                    <option value="Short Answer">Short Answer</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>AI Prompt</label>
                                <textarea id="question-prompt" class="form-control" rows="4" 
                                    placeholder="Ask AI to generate a question. Example: 'Create a multiple choice question about photosynthesis with 4 options'"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Points</label>
                                <input type="number" id="question-points" class="form-control" value="1" min="0.5" max="100" step="0.5">
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-magic"></i> Generate Question
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Bulk Import Tab -->
            <div id="tab-bulk" class="ai-tab-content">
                <div class="ai-section">
                    <h5><i class="fas fa-search"></i> Select Assessment</h5>
                    <select id="bulk-assessment" class="form-control">
                        <option value="">Choose an assessment</option>
                    </select>
                </div>
                
                <div id="bulk-import-section" style="display: none;">
                    <div class="ai-section">
                        <h5><i class="fas fa-paste"></i> Paste Questions</h5>
                        <div class="form-group">
                            <label>Question Format</label>
                            <select id="bulk-format" class="form-control">
                                <option value="auto">Auto-detect</option>
                                <option value="numbered">Numbered (1. Question...)</option>
                                <option value="qanda">Q&A Format</option>
                                <option value="custom">Custom Format</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Questions Text</label>
                            <textarea id="bulk-questions" class="form-control" rows="10" 
                                placeholder="Paste your questions here. AI will parse and format them automatically...

Example:
1. What is photosynthesis?
A) Process of breathing
B) Process of making food using sunlight
C) Process of reproduction
D) Process of growth
Answer: B

2. Name the parts of a plant cell."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Default Points per Question</label>
                            <input type="number" id="bulk-points" class="form-control" value="1" min="0.5" max="100" step="0.5">
                        </div>
                        <button type="button" id="parse-questions" class="btn btn-secondary btn-block">
                            <i class="fas fa-search"></i> Preview Questions
                        </button>
                        <div id="parsed-preview" class="mt-3" style="display: none;">
                            <h6>Parsed Questions Preview:</h6>
                            <div id="preview-content" class="preview-box"></div>
                            <button type="button" id="import-questions" class="btn btn-primary btn-block mt-2">
                                <i class="fas fa-upload"></i> Import All Questions
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- AI Chat Section (Always Visible) -->
            <div class="ai-section ai-chat-section">
                <h5><i class="fas fa-comments"></i> AI Chat</h5>
                <div id="ai-chat-messages" class="chat-messages">
                    <div class="ai-message">
                        <i class="fas fa-robot"></i>
                        <div class="message-content">
                            Hi! I'm your AI assistant. I can help you create assessments, generate questions, and manage your content. What would you like to do today?
                        </div>
                    </div>
                </div>
                <form id="ai-chat-form" class="chat-input-form">
                    <div class="input-group">
                        <input type="text" id="ai-chat-input" class="form-control" 
                            placeholder="Ask me anything about assessments or questions...">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* AI Helper Sidebar Styles */
.ai-helper-sidebar, #ai-helper-sidebar {
    position: fixed;
    top: 60px;
    right: 0;
    width: 400px;
    height: calc(100vh - 60px);
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    transform: translateX(100%);
    transition: transform 0.3s ease-in-out;
    z-index: 1050;
    box-shadow: -4px 0 15px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
}

.ai-helper-sidebar.ai-helper-open, #ai-helper-sidebar.ai-helper-open {
    transform: translateX(0);
}

.ai-helper-toggle {
    position: absolute;
    left: -50px;
    top: 20px;
    width: 50px;
    height: 60px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    border-radius: 8px 0 0 8px;
    color: white;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    box-shadow: -2px 0 10px rgba(0, 0, 0, 0.2);
}

.ai-helper-toggle:hover {
    left: -45px;
    box-shadow: -4px 0 15px rgba(0, 0, 0, 0.3);
}

.ai-helper-toggle i {
    font-size: 18px;
    margin-bottom: 4px;
}

.ai-helper-text {
    font-size: 9px;
    writing-mode: vertical-rl;
    text-orientation: mixed;
}

.ai-helper-content {
    height: 100%;
    display: flex;
    flex-direction: column;
    background: white;
}

.ai-helper-header {
    padding: 15px 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.ai-helper-title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ai-helper-title h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.ai-helper-close {
    background: none;
    border: none;
    color: white;
    font-size: 18px;
    cursor: pointer;
    padding: 5px;
    border-radius: 4px;
    transition: background 0.2s;
}

.ai-helper-close:hover {
    background: rgba(255, 255, 255, 0.2);
}

.ai-helper-body {
    flex: 1;
    overflow-y: auto;
    padding: 0;
}

/* Tabs */
.ai-tabs {
    display: flex;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.ai-tab {
    flex: 1;
    padding: 12px 8px;
    border: none;
    background: transparent;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #6c757d;
}

.ai-tab:hover {
    background: #e9ecef;
    color: #495057;
}

.ai-tab.active {
    background: white;
    color: #667eea;
    border-bottom: 2px solid #667eea;
}

.ai-tab i {
    font-size: 16px;
}

.ai-tab-content {
    display: none;
    padding: 15px;
}

.ai-tab-content.active {
    display: block;
}

/* Sections */
.ai-section {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.ai-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.ai-section h5 {
    font-size: 14px;
    font-weight: 600;
    color: #495057;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ai-section h5 i {
    color: #667eea;
}

/* Form Styles */
.form-group {
    margin-bottom: 12px;
}

.form-group label {
    font-size: 12px;
    font-weight: 500;
    color: #495057;
    margin-bottom: 4px;
    display: block;
}

.form-control {
    font-size: 12px;
    padding: 8px 10px;
    border-radius: 6px;
    border: 1px solid #ced4da;
    width: 100%;
}

.form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.btn {
    font-size: 12px;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-block {
    width: 100%;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
}

.btn-secondary {
    background: #6c757d;
    border: none;
    color: white;
}

/* Lists */
.scrollable-list {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #eee;
    border-radius: 6px;
    background: #f8f9fa;
}

.assessment-item, .question-item {
    padding: 10px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    transition: background 0.2s;
}

.assessment-item:hover, .question-item:hover {
    background: #e9ecef;
}

.assessment-item:last-child, .question-item:last-child {
    border-bottom: none;
}

.assessment-title {
    font-weight: 500;
    font-size: 12px;
    color: #495057;
    margin-bottom: 2px;
}

.assessment-meta {
    font-size: 10px;
    color: #6c757d;
}

/* Chat Section */
.ai-chat-section {
    background: #f8f9fa;
    padding: 15px !important;
    margin-bottom: 0 !important;
    border-bottom: none !important;
    margin-top: auto;
}

.chat-messages {
    max-height: 150px;
    overflow-y: auto;
    margin-bottom: 10px;
    padding: 10px;
    background: white;
    border-radius: 6px;
    border: 1px solid #eee;
}

.ai-message, .user-message {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
    font-size: 11px;
    line-height: 1.4;
}

.ai-message i {
    color: #667eea;
    font-size: 14px;
    margin-top: 2px;
}

.user-message i {
    color: #28a745;
    font-size: 14px;
    margin-top: 2px;
}

.message-content {
    background: #f8f9fa;
    padding: 8px 10px;
    border-radius: 8px;
    flex: 1;
}

.user-message .message-content {
    background: #e3f2fd;
}

.chat-input-form .input-group {
    display: flex;
}

.chat-input-form .form-control {
    flex: 1;
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}

.chat-input-form .btn {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    padding: 8px 15px;
}

/* Loading States */
.loading-indicator {
    text-align: center;
    padding: 20px;
    color: #6c757d;
    font-size: 12px;
}

.loading-indicator i {
    margin-right: 8px;
}

/* Preview Box */
.preview-box {
    max-height: 200px;
    overflow-y: auto;
    padding: 10px;
    background: #f8f9fa;
    border: 1px solid #eee;
    border-radius: 6px;
    font-size: 11px;
}

/* Mobile Styles */
@media (max-width: 767px) {
    .ai-helper-sidebar, #ai-helper-sidebar {
        width: 100%;
        right: 0;
    }
    
    .ai-helper-toggle {
        left: -60px;
        width: 60px;
    }
    
    .ai-tabs {
        flex-wrap: wrap;
    }
    
    .ai-tab {
        min-width: 33.333%;
    }
}

/* Animations */
@keyframes slideIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.ai-message, .user-message {
    animation: slideIn 0.3s ease-out;
}
</style>

<script>
// AI Helper Sidebar JavaScript
class AIQuestionHelper {
    constructor() {
        this.sidebar = document.getElementById('ai-helper-sidebar');
        this.isOpen = false;
        this.currentAssessment = null;
        this.baseUrl = '<?php echo BASE_URL; ?>';
        
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.loadInitialData();
    }
    
    bindEvents() {
        // Toggle sidebar
        document.getElementById('ai-helper-toggle').addEventListener('click', () => {
            this.toggle();
        });
        
        // Close sidebar
        document.getElementById('ai-helper-close').addEventListener('click', () => {
            this.close();
        });
        
        // Tab navigation
        document.querySelectorAll('.ai-tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                this.switchTab(tab.dataset.tab);
            });
        });
        
        // Assessment selection
        document.getElementById('question-assessment').addEventListener('change', (e) => {
            this.loadAssessmentQuestions(e.target.value);
        });
        
        document.getElementById('bulk-assessment').addEventListener('change', (e) => {
            document.getElementById('bulk-import-section').style.display = 
                e.target.value ? 'block' : 'none';
        });
        
        // Form submissions
        document.getElementById('create-assessment-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.createAssessment();
        });
        
        document.getElementById('add-question-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.generateQuestion();
        });
        
        document.getElementById('ai-chat-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.sendChatMessage();
        });
        
        // Bulk import
        document.getElementById('parse-questions').addEventListener('click', () => {
            this.parseQuestions();
        });
        
        document.getElementById('import-questions').addEventListener('click', () => {
            this.importQuestions();
        });
    }
    
    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }
    
    open() {
        this.sidebar.classList.add('ai-helper-open');
        this.isOpen = true;
    }
    
    close() {
        this.sidebar.classList.remove('ai-helper-open');
        this.isOpen = false;
    }
    
    switchTab(tabName) {
        // Update active tab
        document.querySelectorAll('.ai-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
        
        // Update active content
        document.querySelectorAll('.ai-tab-content').forEach(content => {
            content.classList.remove('active');
        });
        document.getElementById(`tab-${tabName}`).classList.add('active');
        
        // Load data if needed
        if (tabName === 'assessments' || tabName === 'questions' || tabName === 'bulk') {
            this.loadAssessments();
        }
    }
    
    async loadInitialData() {
        try {
            await this.loadAssessments();
            await this.loadSubjectsAndClasses();
        } catch (error) {
            console.error('Failed to load initial data:', error);
        }
    }
    
    async loadAssessments() {
        const containers = ['assessments-list', 'question-assessment', 'bulk-assessment'];
        
        try {
            console.log('AI Helper: Loading assessments from:', `${this.baseUrl}/api/ai_helper.php?action=get_assessments`);
            
            const response = await fetch(`${this.baseUrl}/api/ai_helper.php?action=get_assessments`, {
                method: 'GET',
                credentials: 'same-origin'
            });
            
            console.log('AI Helper: Response status:', response.status);
            console.log('AI Helper: Response ok:', response.ok);
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('AI Helper: HTTP error:', response.status, errorText);
                throw new Error(`HTTP error ${response.status}: ${errorText}`);
            }
            
            const data = await response.json();
            console.log('AI Helper: Response data:', data);
            
            if (data.success) {
                // Update assessments list
                const listContainer = document.getElementById('assessments-list');
                listContainer.innerHTML = '';
                
                if (data.assessments.length === 0) {
                    listContainer.innerHTML = '<div class="text-center text-muted p-3"><i class="fas fa-info-circle"></i> No assessments found</div>';
                } else {
                    data.assessments.forEach(assessment => {
                        const item = document.createElement('div');
                        item.className = 'assessment-item';
                        item.innerHTML = `
                            <div class="assessment-title">${assessment.title}</div>
                            <div class="assessment-meta">
                                ${assessment.subject_name} - ${assessment.class_name} | ${assessment.date}
                                <span class="badge badge-sm ${assessment.status === 'pending' ? 'badge-warning' : 'badge-success'}">${assessment.status}</span>
                            </div>
                        `;
                        item.addEventListener('click', () => {
                            window.location.href = `${this.baseUrl}/teacher/manage_questions.php?id=${assessment.assessment_id}`;
                        });
                        listContainer.appendChild(item);
                    });
                }
                
                // Update dropdowns
                const selectElements = [
                    document.getElementById('question-assessment'),
                    document.getElementById('bulk-assessment')
                ];
                
                selectElements.forEach(select => {
                    // Keep first option
                    const firstOption = select.querySelector('option');
                    select.innerHTML = '';
                    select.appendChild(firstOption);
                    
                    data.assessments.forEach(assessment => {
                        const option = document.createElement('option');
                        option.value = assessment.assessment_id;
                        option.textContent = `${assessment.title} - ${assessment.subject_name}`;
                        select.appendChild(option);
                    });
                });
            }
        } catch (error) {
            console.error('Failed to load assessments:', error);
        }
    }
    
    async loadSubjectsAndClasses() {
        try {
            const response = await fetch(`${this.baseUrl}/api/ai_helper.php?action=get_subjects_classes`, {
                method: 'GET',
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            if (data.success) {
                const select = document.getElementById('subject-class');
                select.innerHTML = '<option value="">Select Subject & Class</option>';
                
                data.assignments.forEach(assignment => {
                    const option = document.createElement('option');
                    option.value = `${assignment.subject_id}:${assignment.class_id}`;
                    option.textContent = `${assignment.subject_name} - ${assignment.class_name}`;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Failed to load subjects and classes:', error);
        }
    }
    
    async createAssessment() {
        const title = document.getElementById('assessment-title').value;
        const subjectClass = document.getElementById('subject-class').value;
        const date = document.getElementById('assessment-date').value;
        const prompt = document.getElementById('assessment-prompt').value;
        
        if (!title || !subjectClass || !date) {
            alert('Please fill in all required fields');
            return;
        }
        
        const [subjectId, classId] = subjectClass.split(':');
        
        try {
            const response = await fetch(`${this.baseUrl}/api/ai_helper.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'create_assessment',
                    title: title,
                    subject_id: subjectId,
                    class_id: classId,
                    date: date,
                    ai_prompt: prompt
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('Assessment created successfully!');
                document.getElementById('create-assessment-form').reset();
                this.loadAssessments();
                
                if (data.assessment_id) {
                    window.location.href = `${this.baseUrl}/teacher/manage_questions.php?id=${data.assessment_id}`;
                }
            } else {
                alert('Failed to create assessment: ' + data.message);
            }
        } catch (error) {
            console.error('Failed to create assessment:', error);
            alert('Failed to create assessment. Please try again.');
        }
    }
    
    async generateQuestion() {
        const assessmentId = document.getElementById('question-assessment').value;
        const questionType = document.getElementById('question-type').value;
        const prompt = document.getElementById('question-prompt').value;
        const points = document.getElementById('question-points').value;
        
        if (!assessmentId || !prompt) {
            alert('Please select an assessment and enter a prompt');
            return;
        }
        
        try {
            const button = document.querySelector('#add-question-form button[type="submit"]');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            button.disabled = true;
            
            const response = await fetch(`${this.baseUrl}/api/ai_helper.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'generate_question',
                    assessment_id: assessmentId,
                    question_type: questionType,
                    prompt: prompt,
                    points: points
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('Question generated and added successfully!');
                document.getElementById('add-question-form').reset();
                this.loadAssessmentQuestions(assessmentId);
            } else {
                alert('Failed to generate question: ' + data.message);
            }
            
            button.innerHTML = originalText;
            button.disabled = false;
        } catch (error) {
            console.error('Failed to generate question:', error);
            alert('Failed to generate question. Please try again.');
        }
    }
    
    async parseQuestions() {
        const assessmentId = document.getElementById('bulk-assessment').value;
        const format = document.getElementById('bulk-format').value;
        const questionsText = document.getElementById('bulk-questions').value;
        const defaultPoints = document.getElementById('bulk-points').value;
        
        if (!assessmentId || !questionsText.trim()) {
            alert('Please select an assessment and enter questions text');
            return;
        }
        
        try {
            const button = document.getElementById('parse-questions');
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Parsing...';
            button.disabled = true;
            
            const response = await fetch(`${this.baseUrl}/api/ai_helper.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'parse_questions',
                    format: format,
                    questions_text: questionsText,
                    default_points: defaultPoints
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                const previewDiv = document.getElementById('parsed-preview');
                const contentDiv = document.getElementById('preview-content');
                
                contentDiv.innerHTML = '';
                data.questions.forEach((q, index) => {
                    const questionDiv = document.createElement('div');
                    questionDiv.className = 'mb-2 p-2 border rounded';
                    questionDiv.innerHTML = `
                        <strong>Q${index + 1}:</strong> ${q.question_text}<br>
                        <small>Type: ${q.question_type} | Points: ${q.max_score}</small>
                    `;
                    contentDiv.appendChild(questionDiv);
                });
                
                previewDiv.style.display = 'block';
                this.parsedQuestions = data.questions;
            } else {
                alert('Failed to parse questions: ' + data.message);
            }
            
            button.innerHTML = '<i class="fas fa-search"></i> Preview Questions';
            button.disabled = false;
        } catch (error) {
            console.error('Failed to parse questions:', error);
            alert('Failed to parse questions. Please try again.');
        }
    }
    
    async importQuestions() {
        const assessmentId = document.getElementById('bulk-assessment').value;
        
        if (!assessmentId || !this.parsedQuestions) {
            alert('Please select an assessment and parse questions first');
            return;
        }
        
        try {
            const button = document.getElementById('import-questions');
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
            button.disabled = true;
            
            const response = await fetch(`${this.baseUrl}/api/ai_helper.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'import_questions',
                    assessment_id: assessmentId,
                    questions: this.parsedQuestions
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert(`Successfully imported ${data.imported_count} questions!`);
                document.getElementById('bulk-questions').value = '';
                document.getElementById('parsed-preview').style.display = 'none';
                this.loadAssessmentQuestions(assessmentId);
            } else {
                alert('Failed to import questions: ' + data.message);
            }
            
            button.innerHTML = '<i class="fas fa-upload"></i> Import All Questions';
            button.disabled = false;
        } catch (error) {
            console.error('Failed to import questions:', error);
            alert('Failed to import questions. Please try again.');
        }
    }
    
    async loadAssessmentQuestions(assessmentId) {
        if (!assessmentId) {
            document.getElementById('questions-management').style.display = 'none';
            return;
        }
        
        document.getElementById('questions-management').style.display = 'block';
        
        try {
            const response = await fetch(`${this.baseUrl}/api/ai_helper.php?action=get_questions&assessment_id=${assessmentId}`, {
                method: 'GET',
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            const container = document.getElementById('current-questions');
            container.innerHTML = '';
            
            if (data.success) {
                if (data.questions.length === 0) {
                    container.innerHTML = '<div class="text-center text-muted p-3"><i class="fas fa-info-circle"></i> No questions found</div>';
                } else {
                    data.questions.forEach((question, index) => {
                        const item = document.createElement('div');
                        item.className = 'question-item';
                        item.innerHTML = `
                            <div class="question-title">Q${index + 1}: ${question.question_text.substring(0, 50)}...</div>
                            <div class="question-meta">
                                ${question.question_type} | ${question.max_score} pts
                                ${question.answer_count > 0 ? '<span class="text-danger">• Has Answers</span>' : ''}
                            </div>
                        `;
                        container.appendChild(item);
                    });
                }
            } else {
                container.innerHTML = '<div class="text-center text-danger p-3"><i class="fas fa-exclamation-triangle"></i> Failed to load questions</div>';
            }
        } catch (error) {
            console.error('Failed to load questions:', error);
        }
    }
    
    async sendChatMessage() {
        const input = document.getElementById('ai-chat-input');
        const message = input.value.trim();
        
        if (!message) return;
        
        // Add user message
        this.addChatMessage('user', message);
        input.value = '';
        
        try {
            const response = await fetch(`${this.baseUrl}/api/ai_helper.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'chat',
                    message: message
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.addChatMessage('ai', data.response);
            } else {
                this.addChatMessage('ai', 'Sorry, I encountered an error. Please try again.');
            }
        } catch (error) {
            console.error('Chat error:', error);
            this.addChatMessage('ai', 'Sorry, I\'m having trouble connecting. Please try again.');
        }
    }
    
    addChatMessage(type, message) {
        const messagesContainer = document.getElementById('ai-chat-messages');
        const messageDiv = document.createElement('div');
        messageDiv.className = `${type}-message`;
        messageDiv.innerHTML = `
            <i class="fas fa-${type === 'ai' ? 'robot' : 'user'}"></i>
            <div class="message-content">${message}</div>
        `;
        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
}

// Initialize AI Helper when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.aiHelper = new AIQuestionHelper();
});
</script>