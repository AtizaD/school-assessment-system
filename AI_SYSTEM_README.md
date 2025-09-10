# AI Question Helper System

## Overview
The AI Question Helper is an intelligent sidebar system for teachers that can:
- Answer questions about assessments, students, and classes
- Generate questions for assessments
- Parse and import bulk questions
- Provide real-time data from the school database

## Core Files

### Main Files
- `/includes/ai_question_helper.php` - Sidebar component for teachers
- `/api/ai_helper.php` - Main API endpoint handling all AI operations

### Integration
- Added to `/includes/bass/base_header.php` for teacher role only

## Features

### 1. Assessment Management
- View all assessments
- Get assessment statistics
- Create assessments with AI assistance

### 2. Question Generation
- AI-powered question creation
- Support for MCQ and Short Answer types
- Bulk question parsing and import

### 3. Smart Database Queries
The AI can answer questions like:
- "How many assessments do I have?"
- "Show me participation by class for COMPUTER SOFTWARE"
- "Which students scored above 80%?"
- "What's the average completion rate?"

### 4. Security Features
- Teacher authentication required
- Data filtered by TeacherClassAssignments
- Only SELECT queries allowed for AI-generated SQL
- Session-based validation

## How It Works

### 1. Smart Pattern Recognition
Common queries are recognized and routed to optimized database functions:
- Assessment counts → `handleAssessmentCount()`
- Class breakdowns → `handleCorrectedClassBreakdown()`
- Specific assessments → `handleSpecificAssessmentByClass()`

### 2. Dynamic Query Generation
For complex queries, the AI:
- Understands the database schema
- Generates secure SQL queries
- Executes them safely
- Formats results for readability

### 3. Fallback System
- Pattern matching (fastest, most accurate)
- AI query generation (flexible, intelligent)  
- Static context with external AI (last resort)

## Database Schema Understanding
The AI knows about these key tables:
- Assessments, AssessmentClasses, Students, Classes
- Teachers, TeacherClassAssignments, Subjects
- questions, studentanswers, mcqquestions

## API Endpoints

### Chat Endpoint
```
POST /api/ai_helper.php
{
    "action": "chat",
    "message": "your question here"
}
```

### Other Endpoints
- `get_assessments` - List teacher's assessments
- `get_subjects_classes` - Get teacher assignments
- `create_assessment` - Create new assessment
- `generate_question` - AI question generation
- `parse_questions` - Parse bulk questions
- `import_questions` - Import parsed questions

## Usage Examples

### For Teachers
1. Click the AI helper icon in the sidebar
2. Ask natural language questions:
   - "How many students answered my latest assessment?"
   - "Show me class participation breakdown"
   - "Create 5 questions about computer networks"

### For Developers
```javascript
fetch('/api/ai_helper.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
        action: 'chat',
        message: 'your question'
    })
})
```

## Configuration
- Mistral AI API key configured in the system
- Database connection through existing DatabaseConfig
- Session management through existing auth system

## Data Accuracy
The system uses corrected database queries to ensure accurate participation data:
- Uses `CASE WHEN sa.assessment_id = a.assessment_id` for proper counting
- Avoids incorrect LEFT JOIN issues that inflated participation rates
- Provides real-time, accurate statistics

## Future Enhancements
- Support for more question types
- Enhanced AI training on school-specific data
- Automated report generation
- Integration with grading workflows