<?php
// Demo page to showcase AI Question Helper
if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('teacher');

$pageTitle = 'AI Question Helper Demo';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-robot text-warning"></i>
                        AI Question Helper - Now Active!
                    </h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h5><i class="fas fa-magic text-primary"></i> Features Available:</h5>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item">
                                    <i class="fas fa-eye text-success"></i>
                                    <strong>View Assessments:</strong> Quick access to all your assessments
                                </li>
                                <li class="list-group-item">
                                    <i class="fas fa-plus text-info"></i>
                                    <strong>Create Assessments:</strong> AI-assisted assessment creation with smart suggestions
                                </li>
                                <li class="list-group-item">
                                    <i class="fas fa-question-circle text-warning"></i>
                                    <strong>Generate Questions:</strong> AI creates questions based on your prompts
                                </li>
                                <li class="list-group-item">
                                    <i class="fas fa-file-upload text-primary"></i>
                                    <strong>Bulk Import:</strong> Paste questions and AI will parse and format them
                                </li>
                                <li class="list-group-item">
                                    <i class="fas fa-comments text-success"></i>
                                    <strong>AI Chat:</strong> Get help and suggestions for your assessments
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h5><i class="fas fa-lightbulb text-warning"></i> How to Use:</h5>
                            <div class="alert alert-info">
                                <p><strong>Step 1:</strong> Look for the AI Assistant button on the right side of your screen</p>
                                <p><strong>Step 2:</strong> Click it to open the AI Question Helper sidebar</p>
                                <p><strong>Step 3:</strong> Use the tabs to navigate between features:</p>
                                <ul class="mb-0">
                                    <li><strong>Assessments:</strong> View and create assessments</li>
                                    <li><strong>Questions:</strong> Add AI-generated questions</li>
                                    <li><strong>Bulk Import:</strong> Import multiple questions at once</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-success">
                                <h6><i class="fas fa-robot"></i> AI Capabilities:</h6>
                                <p class="mb-2">The AI can help you with:</p>
                                <ul class="mb-0">
                                    <li>Creating MCQ questions with 4 options</li>
                                    <li>Generating short answer questions</li>
                                    <li>Parsing bulk question text from any format</li>
                                    <li>Providing assessment creation advice</li>
                                    <li>Suggesting question improvements</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-12">
                            <h5><i class="fas fa-examples text-danger"></i> Example Prompts to Try:</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="card border-primary">
                                        <div class="card-header bg-primary text-white">
                                            <h6 class="mb-0">Question Generation</h6>
                                        </div>
                                        <div class="card-body">
                                            <small>
                                                "Create a multiple choice question about photosynthesis with 4 options"
                                                <br><br>
                                                "Generate a short answer question about the causes of World War I"
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card border-success">
                                        <div class="card-header bg-success text-white">
                                            <h6 class="mb-0">Assessment Creation</h6>
                                        </div>
                                        <div class="card-body">
                                            <small>
                                                "Create an assessment focusing on basic algebra concepts for grade 9 students"
                                                <br><br>
                                                "Generate questions about the digestive system"
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card border-warning">
                                        <div class="card-header bg-warning text-dark">
                                            <h6 class="mb-0">Bulk Import</h6>
                                        </div>
                                        <div class="card-body">
                                            <small>
                                                Paste questions like:<br>
                                                "1. What is H2O?<br>
                                                A) Oxygen B) Water<br>
                                                C) Hydrogen D) Carbon<br>
                                                Answer: B"
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <a href="assessments.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-rocket"></i> Start Using AI Helper
                        </a>
                        <p class="text-muted mt-2">
                            <small>The AI helper is available on all teacher pages. Look for the robot icon on the right side!</small>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.list-group-item {
    border: none;
    padding: 0.75rem 1.25rem;
}

.list-group-item i {
    width: 20px;
    text-align: center;
    margin-right: 10px;
}

.card {
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border: none;
    margin-bottom: 1rem;
}

.card-header {
    border-bottom: none;
}

.alert {
    border: none;
    border-radius: 8px;
}
</style>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>