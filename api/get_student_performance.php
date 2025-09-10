<?php
// api/get_student_performance.php

// Check if this file is being included or called directly
$isDirectAccess = (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__));

// Basic setup
if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}
require_once BASEPATH . '/vendor/autoload.php';
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/PerformanceCache.php';

// Only execute API code if accessed directly
if ($isDirectAccess) {
    
    // Set content type to JSON first
    header('Content-Type: application/json');
    
    // Check authentication for API
    if (!isset($_SESSION['user_id']) && !isLoggedIn()) {
        error_response('Authentication required', 401);
    }
    
    $userRole = getRole();
    if (!in_array($userRole, ['admin', 'teacher'])) {
        error_response('Insufficient permissions', 403);
    }
    
    // Get filter parameters
    $level = isset($_GET['level']) ? sanitizeInput($_GET['level']) : null;
    $classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
    $subjectId = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : null;
    $semesterId = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : null;
    $limit = isset($_GET['limit']) ? min(intval($_GET['limit']), 1000) : 100; // Limit results
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    
    // Validate input parameters
    if ($classId && $classId <= 0) {
        error_response('Invalid class ID');
    }
    if ($subjectId && $subjectId <= 0) {
        error_response('Invalid subject ID');
    }
    if ($semesterId && $semesterId <= 0) {
        error_response('Invalid semester ID');
    }
    
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $cacheManager = new PerformanceCacheManager();
        
        // Optimize MySQL session settings for performance
        $db->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        $db->exec("SET SESSION optimizer_search_depth = 62");
        $db->exec("SET SESSION max_execution_time = 30000"); // 30 seconds timeout
        
        // Try to get cached data first
        $response = [
            'summary' => $cacheManager->getCachedSummaryStats($level, $classId, $subjectId, $semesterId),
            'top_performers' => $cacheManager->getCachedTopPerformers($level, $classId, $subjectId, $semesterId, $limit, $offset),
            'class_performance' => $cacheManager->getCachedClassPerformance($level, $classId, $subjectId, $semesterId, $limit, $offset),
            'assessment_stats' => $cacheManager->getCachedAssessmentStats($level, $classId, $subjectId, $semesterId, $limit, $offset)
        ];
        
        // Generate missing data and cache it
        if (!$response['summary']) {
            $response['summary'] = get_summary_stats_optimized($db, $level, $classId, $subjectId, $semesterId);
            $cacheManager->cacheSummaryStats($level, $classId, $subjectId, $semesterId, $response['summary']);
        }
        
        if (!$response['top_performers']) {
            $response['top_performers'] = get_top_performers_optimized($db, $level, $classId, $subjectId, $semesterId, $limit, $offset);
            $cacheManager->cacheTopPerformers($level, $classId, $subjectId, $semesterId, $limit, $offset, $response['top_performers']);
        }
        
        if (!$response['class_performance']) {
            $response['class_performance'] = get_class_performance_optimized($db, $level, $classId, $subjectId, $semesterId, $limit, $offset);
            $cacheManager->cacheClassPerformance($level, $classId, $subjectId, $semesterId, $limit, $offset, $response['class_performance']);
        }
        
        if (!$response['assessment_stats']) {
            $response['assessment_stats'] = get_assessment_stats_optimized($db, $level, $classId, $subjectId, $semesterId, $limit, $offset);
            $cacheManager->cacheAssessmentStats($level, $classId, $subjectId, $semesterId, $limit, $offset, $response['assessment_stats']);
        }

        // Add assessment type breakdown
        $response['assessment_type_breakdown'] = get_assessment_type_breakdown($db, $level, $classId, $subjectId, $semesterId);
        
        // Add pagination and cache info
        $response['pagination'] = [
            'limit' => $limit,
            'offset' => $offset
        ];
        
        $response['cache_info'] = $cacheManager->getStats();
        
        // Return the JSON response
        echo json_encode($response);
    } catch (Exception $e) {
        logError("API error: " . $e->getMessage());
        error_response("Error processing request: " . $e->getMessage());
    }
}

/**
 * Output an error response and exit
 */
function error_response($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * Build optimized WHERE clause and parameters
 */
function build_where_clause($level, $classId, $subjectId, $semesterId, $tableAliases = []) {
    $whereConditions = [];
    $params = [];
    
    $classAlias = $tableAliases['class'] ?? 'c';
    $subjectAlias = $tableAliases['subject'] ?? 'ac';
    $semesterAlias = $tableAliases['semester'] ?? 'a';
    $studentAlias = $tableAliases['student'] ?? 's';
    
    if ($level) {
        $whereConditions[] = "{$classAlias}.level = ?";
        $params[] = $level;
    }
    
    if ($classId) {
        $whereConditions[] = ($studentAlias ? "{$studentAlias}.class_id = ?" : "{$classAlias}.class_id = ?");
        $params[] = $classId;
    }
    
    if ($subjectId) {
        $whereConditions[] = "{$subjectAlias}.subject_id = ?";
        $params[] = $subjectId;
    }
    
    if ($semesterId) {
        $whereConditions[] = "{$semesterAlias}.semester_id = ?";
        $params[] = $semesterId;
    }
    
    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
    
    return [$whereClause, $params];
}

/**
 * Optimized summary statistics with single query approach
 */
function get_summary_stats_optimized($db, $level, $classId, $subjectId, $semesterId) {
    [$whereClause, $params] = build_where_clause($level, $classId, $subjectId, $semesterId);
    
    // Single query to get all summary stats using CTEs (Common Table Expressions)
    $sql = "
    WITH filtered_data AS (
        SELECT DISTINCT 
            s.student_id,
            ac.subject_id,
            a.assessment_id,
            r.score,
            at.type_id,
            at.type_name
        FROM students s
        JOIN classes c ON s.class_id = c.class_id
        JOIN assessmentclasses ac ON c.class_id = ac.class_id
        JOIN assessments a ON ac.assessment_id = a.assessment_id
        LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
        LEFT JOIN results r ON (s.student_id = r.student_id AND r.assessment_id = a.assessment_id)
        $whereClause
    )
    SELECT 
        COUNT(DISTINCT student_id) as total_students,
        COUNT(DISTINCT subject_id) as total_subjects,
        COUNT(DISTINCT assessment_id) as total_assessments,
        COUNT(DISTINCT type_id) as total_assessment_types,
        ROUND(AVG(score), 2) as overall_avg_score
    FROM filtered_data
    WHERE score IS NOT NULL";
    
    $stmt = $db->prepare($sql);
    if (!empty($params)) {
        $idx = 1;
        foreach ($params as $param) {
            $stmt->bindValue($idx++, $param);
        }
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'total_students' => (int)($result['total_students'] ?? 0),
        'total_subjects' => (int)($result['total_subjects'] ?? 0),
        'total_assessments' => (int)($result['total_assessments'] ?? 0),
        'total_assessment_types' => (int)($result['total_assessment_types'] ?? 0),
        'overall_avg_score' => (float)($result['overall_avg_score'] ?? 0)
    ];
}

/**
 * Top performers with proper completion time calculation
 */
function get_top_performers_optimized($db, $level, $classId, $subjectId, $semesterId, $limit = 100, $offset = 0) {
    [$whereClause, $params] = build_where_clause($level, $classId, $subjectId, $semesterId);
    
    // First get the student scores
    $sql = "
    SELECT 
        sub.subject_id,
        sub.subject_name,
        s.student_id,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        c.class_name,
        c.level,
        p.program_name,
        ROUND(AVG(r.score), 2) as avg_score
    FROM subjects sub
    JOIN assessmentclasses ac ON sub.subject_id = ac.subject_id
    JOIN assessments a ON ac.assessment_id = a.assessment_id
    JOIN classes c ON ac.class_id = c.class_id
    JOIN programs p ON c.program_id = p.program_id
    JOIN students s ON s.class_id = c.class_id
    JOIN results r ON (s.student_id = r.student_id AND r.assessment_id = a.assessment_id)
    $whereClause
    GROUP BY sub.subject_id, s.student_id
    HAVING AVG(r.score) > 0
    ORDER BY sub.subject_name, avg_score DESC
    LIMIT ?";
    
    $allParams = array_merge($params, [$limit]);
    $stmt = $db->prepare($sql);
    $idx = 1;
    foreach ($allParams as $param) {
        $stmt->bindValue($idx++, $param);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get completion times for these students
    $studentIds = array_column($results, 'student_id');
    $completionTimes = [];
    
    if (!empty($studentIds)) {
        $placeholders = str_repeat('?,', count($studentIds) - 1) . '?';
        $timeQuery = "
        SELECT 
            sub.student_id,
            sub.subject_id,
            MIN(sub.duration_seconds) as min_duration_seconds,
            MIN(sub.formatted_time) as best_completion_time
        FROM (
            SELECT 
                aa.student_id,
                ac.subject_id,
                TIMESTAMPDIFF(SECOND, aa.start_time, aa.end_time) as duration_seconds,
                CASE 
                    WHEN TIMESTAMPDIFF(SECOND, aa.start_time, aa.end_time) < 60 
                    THEN CONCAT(TIMESTAMPDIFF(SECOND, aa.start_time, aa.end_time), 's')
                    WHEN TIMESTAMPDIFF(MINUTE, aa.start_time, aa.end_time) < 60 
                    THEN CONCAT(TIMESTAMPDIFF(MINUTE, aa.start_time, aa.end_time), 'm')
                    WHEN TIMESTAMPDIFF(HOUR, aa.start_time, aa.end_time) < 24 
                    THEN CONCAT(TIMESTAMPDIFF(HOUR, aa.start_time, aa.end_time), 'h ', 
                               TIMESTAMPDIFF(MINUTE, aa.start_time, aa.end_time) % 60, 'm')
                    ELSE 'Over 24h'
                END as formatted_time
            FROM assessmentattempts aa
            JOIN assessments a ON aa.assessment_id = a.assessment_id
            JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
            WHERE aa.student_id IN ($placeholders)
            AND aa.status = 'completed'
            AND aa.start_time IS NOT NULL 
            AND aa.end_time IS NOT NULL
            AND aa.end_time > aa.start_time
        ) sub
        GROUP BY sub.student_id, sub.subject_id
        ORDER BY sub.student_id, sub.subject_id, min_duration_seconds";
        
        $timeStmt = $db->prepare($timeQuery);
        $timeStmt->execute($studentIds);
        $timeResults = $timeStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($timeResults as $timeRow) {
            $completionTimes[$timeRow['student_id']][$timeRow['subject_id']] = $timeRow['best_completion_time'];
        }
    }
    
    // Group results by subject and add completion times with proper ranking
    $topPerformers = [];
    $subjectData = [];
    
    // First, organize all data by subject
    foreach ($results as $row) {
        $subjectId = $row['subject_id'];
        $studentId = $row['student_id'];
        
        if (!isset($subjectData[$subjectId])) {
            $subjectData[$subjectId] = [
                'subject_id' => $subjectId,
                'subject_name' => $row['subject_name'],
                'students' => []
            ];
        }
        
        $bestTime = $completionTimes[$studentId][$subjectId] ?? 'N/A';
        
        $subjectData[$subjectId]['students'][] = [
            'student_name' => $row['student_name'],
            'class_name' => $row['class_name'],
            'level' => $row['level'],
            'program_name' => $row['program_name'],
            'avg_score' => (float)$row['avg_score'],
            'best_completion_time' => $bestTime,
            'completion_seconds' => ($bestTime !== 'N/A' && isset($completionTimes[$studentId][$subjectId])) 
                ? parseTimeToSeconds($bestTime) 
                : PHP_INT_MAX // Put N/A times at the end
        ];
    }
    
    // Now sort each subject's students with proper ranking logic
    foreach ($subjectData as $subjectId => $subject) {
        // Sort by: 1) Score DESC, 2) Completion time ASC (faster first), 3) N/A times last
        usort($subject['students'], function($a, $b) {
            // First compare by score (higher score wins)
            if ($a['avg_score'] != $b['avg_score']) {
                return $b['avg_score'] <=> $a['avg_score'];
            }
            
            // If scores are equal, compare by completion time
            // Students with N/A times (PHP_INT_MAX) go last
            return $a['completion_seconds'] <=> $b['completion_seconds'];
        });
        
        // Take only top 10 per subject
        $subject['students'] = array_slice($subject['students'], 0, 10);
        
        // Remove the completion_seconds helper field
        foreach ($subject['students'] as &$student) {
            unset($student['completion_seconds']);
        }
        
        $topPerformers[] = $subject;
    }
    
    return $topPerformers;
}

/**
 * Optimized class performance with aggregated queries
 */
function get_class_performance_optimized($db, $level, $classId, $subjectId, $semesterId, $limit = 100, $offset = 0) {
    [$whereClause, $params] = build_where_clause($level, $classId, $subjectId, $semesterId);
    
    // Single query with proper aggregation
    $sql = "
    SELECT 
        sub.subject_id,
        sub.subject_name,
        c.class_name,
        c.level,
        p.program_name,
        COUNT(DISTINCT s.student_id) as total_students,
        ROUND(AVG(r.score), 2) as avg_score,
        ROUND(MIN(r.score), 2) as min_score,
        ROUND(MAX(r.score), 2) as max_score,
        ROW_NUMBER() OVER (PARTITION BY sub.subject_id ORDER BY AVG(r.score) DESC) as class_rank
    FROM subjects sub
    JOIN assessmentclasses ac ON sub.subject_id = ac.subject_id
    JOIN assessments a ON ac.assessment_id = a.assessment_id
    JOIN classes c ON ac.class_id = c.class_id
    JOIN programs p ON c.program_id = p.program_id
    JOIN students s ON s.class_id = c.class_id
    JOIN results r ON (s.student_id = r.student_id AND r.assessment_id = a.assessment_id)
    $whereClause
    GROUP BY sub.subject_id, c.class_id
    HAVING COUNT(DISTINCT s.student_id) > 0
    ORDER BY sub.subject_name, avg_score DESC
    LIMIT ? OFFSET ?";
    
    $allParams = array_merge($params, [$limit, $offset]);
    $stmt = $db->prepare($sql);
    $idx = 1;
    foreach ($allParams as $param) {
        $stmt->bindValue($idx++, $param);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group results by subject
    $classPerformance = [];
    $currentSubject = null;
    
    foreach ($results as $row) {
        if ($currentSubject === null || $currentSubject['subject_id'] !== $row['subject_id']) {
            if ($currentSubject !== null) {
                $classPerformance[] = $currentSubject;
            }
            $currentSubject = [
                'subject_id' => $row['subject_id'],
                'subject_name' => $row['subject_name'],
                'classes' => []
            ];
        }
        
        $currentSubject['classes'][] = [
            'class_name' => $row['class_name'],
            'level' => $row['level'],
            'program_name' => $row['program_name'],
            'total_students' => $row['total_students'],
            'avg_score' => $row['avg_score'],
            'min_score' => $row['min_score'],
            'max_score' => $row['max_score']
        ];
    }
    
    if ($currentSubject !== null) {
        $classPerformance[] = $currentSubject;
    }
    
    return $classPerformance;
}

/**
 * Optimized assessment statistics with batch processing
 */
function get_assessment_stats_optimized($db, $level, $classId, $subjectId, $semesterId, $limit = 100, $offset = 0) {
    [$whereClause, $params] = build_where_clause($level, $classId, $subjectId, $semesterId);
    
    // Single comprehensive query for assessment stats
    $sql = "
    SELECT 
        sub.subject_id,
        sub.subject_name,
        c.class_name,
        c.level,
        p.program_name,
        total_students,
        students_taken,
        (total_students - students_taken) as students_not_taken,
        total_assessments,
        CONCAT(ROUND((students_taken / total_students) * 100, 1), '%') as completion_rate
    FROM subjects sub
    JOIN assessmentclasses ac ON sub.subject_id = ac.subject_id
    JOIN assessments a ON ac.assessment_id = a.assessment_id
    JOIN classes c ON ac.class_id = c.class_id
    JOIN programs p ON c.program_id = p.program_id
    JOIN (
        SELECT 
            c.class_id,
            ac.subject_id,
            COUNT(DISTINCT s.student_id) as total_students,
            COUNT(DISTINCT r.student_id) as students_taken,
            COUNT(DISTINCT a.assessment_id) as total_assessments
        FROM classes c
        JOIN students s ON s.class_id = c.class_id
        JOIN assessmentclasses ac ON c.class_id = ac.class_id
        JOIN assessments a ON ac.assessment_id = a.assessment_id
        LEFT JOIN results r ON (s.student_id = r.student_id AND r.assessment_id = a.assessment_id)
        $whereClause
        GROUP BY c.class_id, ac.subject_id
    ) stats ON (c.class_id = stats.class_id AND sub.subject_id = stats.subject_id)
    ORDER BY sub.subject_name, completion_rate DESC
    LIMIT ? OFFSET ?";
    
    $allParams = array_merge($params, [$limit, $offset]);
    $stmt = $db->prepare($sql);
    $idx = 1;
    foreach ($allParams as $param) {
        $stmt->bindValue($idx++, $param);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group results by subject and calculate subject-level totals
    $assessmentStats = [];
    $subjectTotals = [];
    
    foreach ($results as $row) {
        $subjectId = $row['subject_id'];
        
        if (!isset($subjectTotals[$subjectId])) {
            $subjectTotals[$subjectId] = [
                'subject_id' => $subjectId,
                'subject_name' => $row['subject_name'],
                'total_students' => 0,
                'students_taken' => 0,
                'students_not_taken' => 0,
                'class_stats' => []
            ];
        }
        
        $subjectTotals[$subjectId]['total_students'] += $row['total_students'];
        $subjectTotals[$subjectId]['students_taken'] += $row['students_taken'];
        $subjectTotals[$subjectId]['students_not_taken'] += $row['students_not_taken'];
        
        $subjectTotals[$subjectId]['class_stats'][] = [
            'class_name' => $row['class_name'],
            'level' => $row['level'],
            'program_name' => $row['program_name'],
            'total_students' => $row['total_students'],
            'students_taken' => $row['students_taken'],
            'students_not_taken' => $row['students_not_taken'],
            'total_assessments' => $row['total_assessments'],
            'completion_rate' => $row['completion_rate']
        ];
    }
    
    return array_values($subjectTotals);
}

/**
 * Get assessment type performance breakdown
 */
function get_assessment_type_breakdown($db, $level, $classId, $subjectId, $semesterId) {
    [$whereClause, $params] = build_where_clause($level, $classId, $subjectId, $semesterId);
    
    $sql = "
    SELECT 
        COALESCE(at.type_name, 'Unassigned') as type_name,
        COALESCE(at.weight_percentage, 0) as weight_percentage,
        COUNT(DISTINCT a.assessment_id) as total_assessments,
        COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_results,
        COUNT(DISTINCT r.student_id) as students_attempted,
        ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 2) as average_score,
        ROUND(MIN(CASE WHEN r.status = 'completed' THEN r.score END), 2) as min_score,
        ROUND(MAX(CASE WHEN r.status = 'completed' THEN r.score END), 2) as max_score
    FROM assessments a
    JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
    JOIN classes c ON ac.class_id = c.class_id
    LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
    LEFT JOIN results r ON a.assessment_id = r.assessment_id
    LEFT JOIN students s ON r.student_id = s.student_id AND s.class_id = c.class_id
    $whereClause
    GROUP BY COALESCE(at.type_name, 'Unassigned'), at.type_id, at.weight_percentage
    ORDER BY COALESCE(at.type_name, 'Unassigned')";
    
    $stmt = $db->prepare($sql);
    if (!empty($params)) {
        $idx = 1;
        foreach ($params as $param) {
            $stmt->bindValue($idx++, $param);
        }
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Parse time string to seconds for sorting
 */
function parseTimeToSeconds($timeString) {
    if ($timeString === 'N/A' || $timeString === 'Over 24h') {
        return PHP_INT_MAX;
    }
    
    // Handle different time formats: "45s", "19m", "2h 15m"
    $seconds = 0;
    
    // Extract hours
    if (preg_match('/(\d+)h/', $timeString, $matches)) {
        $seconds += intval($matches[1]) * 3600;
    }
    
    // Extract minutes
    if (preg_match('/(\d+)m/', $timeString, $matches)) {
        $seconds += intval($matches[1]) * 60;
    }
    
    // Extract seconds
    if (preg_match('/(\d+)s/', $timeString, $matches)) {
        $seconds += intval($matches[1]);
    }
    
    return $seconds;
}

/**
 * Database optimization suggestions to run during installation/maintenance
 */
function optimize_database_indexes($db) {
    // Create composite indexes for better query performance
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_students_class_id ON students(class_id)",
        "CREATE INDEX IF NOT EXISTS idx_classes_level ON classes(level)",
        "CREATE INDEX IF NOT EXISTS idx_results_student_assessment ON results(student_id, assessment_id)",
        "CREATE INDEX IF NOT EXISTS idx_results_score ON results(score)",
        "CREATE INDEX IF NOT EXISTS idx_assessmentclasses_subject_class ON assessmentclasses(subject_id, class_id)",
        "CREATE INDEX IF NOT EXISTS idx_assessments_semester ON assessments(semester_id)",
        "CREATE INDEX IF NOT EXISTS idx_assessmentattempts_student_status ON assessmentattempts(student_id, status)",
        "CREATE INDEX IF NOT EXISTS idx_assessmentattempts_times ON assessmentattempts(start_time, end_time)",
        
        // Composite indexes for common query patterns
        "CREATE INDEX IF NOT EXISTS idx_students_class_level ON students(class_id) INCLUDE (student_id)",
        "CREATE INDEX IF NOT EXISTS idx_results_covering ON results(student_id, assessment_id) INCLUDE (score)",
        "CREATE INDEX IF NOT EXISTS idx_classes_covering ON classes(class_id) INCLUDE (level, class_name, program_id)"
    ];
    
    foreach ($indexes as $index) {
        try {
            $db->exec($index);
        } catch (Exception $e) {
            logError("Index creation failed: " . $e->getMessage());
        }
    }
}
?>