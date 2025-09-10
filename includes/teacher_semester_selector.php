<?php
// includes/teacher_semester_selector.php
// Shared semester selection functionality for teacher pages

/**
 * Get current semester for teacher pages
 * Handles URL parameter, session, and fallback logic
 */
function getCurrentSemesterForTeacher($db, $user_id = null) {
    // Get selected semester from URL parameter
    $selectedSemesterId = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
    
    if ($selectedSemesterId) {
        // Validate selected semester exists
        $stmt = $db->prepare("SELECT semester_id, semester_name, is_double_track FROM semesters WHERE semester_id = ?");
        $stmt->execute([$selectedSemesterId]);
        $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($semester) {
            return $semester;
        }
    }
    
    // Try to find current active semester
    $stmt = $db->prepare("
        SELECT DISTINCT s.semester_id, s.semester_name, s.is_double_track
        FROM semesters s
        LEFT JOIN semester_forms sf ON s.semester_id = sf.semester_id
        WHERE (
            (s.is_double_track = 0 AND s.start_date <= CURDATE() AND s.end_date >= CURDATE())
            OR 
            (s.is_double_track = 1 AND sf.start_date <= CURDATE() AND sf.end_date >= CURDATE())
        )
        ORDER BY s.semester_id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $currentSemester = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currentSemester) {
        return $currentSemester;
    }
    
    // Fallback to most recent semester
    $stmt = $db->prepare("
        SELECT semester_id, semester_name, is_double_track
        FROM semesters 
        ORDER BY semester_id DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $semester = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$semester) {
        throw new Exception("No semester found");
    }
    
    return $semester;
}

/**
 * Get all available semesters for teacher dropdown
 */
function getAllSemestersForTeacher($db) {
    $stmt = $db->query("
        SELECT semester_id, semester_name, is_double_track, start_date, end_date,
               CASE 
                   WHEN is_double_track = 0 AND start_date <= CURDATE() AND end_date >= CURDATE() THEN 1
                   WHEN is_double_track = 1 AND EXISTS(
                       SELECT 1 FROM semester_forms sf 
                       WHERE sf.semester_id = semesters.semester_id 
                       AND sf.start_date <= CURDATE() AND sf.end_date >= CURDATE()
                   ) THEN 1
                   ELSE 0
               END as is_current
        FROM semesters 
        ORDER BY semester_id DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Render semester selector dropdown
 */
function renderSemesterSelector($currentSemester, $allSemesters, $currentPage = 'index.php') {
    $currentUrl = basename($_SERVER['PHP_SELF']);
    $queryParams = $_GET;
    unset($queryParams['semester']); // Remove semester param to rebuild URL
    
    $baseUrl = $currentUrl;
    if (!empty($queryParams)) {
        $baseUrl .= '?' . http_build_query($queryParams) . '&semester=';
    } else {
        $baseUrl .= '?semester=';
    }
    
    echo '<div class="semester-selector mb-3">';
    echo '<div class="d-flex align-items-center gap-3">';
    echo '<label for="semesterSelect" class="form-label mb-0 fw-medium">Semester:</label>';
    echo '<select id="semesterSelect" class="form-select form-select-sm" style="width: auto;" onchange="changeSemester(this.value)">';
    
    foreach ($allSemesters as $semester) {
        $selected = $semester['semester_id'] == $currentSemester['semester_id'] ? 'selected' : '';
        $currentLabel = $semester['is_current'] ? ' (Current)' : '';
        $trackLabel = $semester['is_double_track'] ? ' - Double Track' : '';
        
        echo '<option value="' . $semester['semester_id'] . '" ' . $selected . '>';
        echo htmlspecialchars($semester['semester_name']) . $currentLabel . $trackLabel;
        echo '</option>';
    }
    
    echo '</select>';
    echo '</div>';
    echo '</div>';
    
    // JavaScript for semester switching
    echo '<script>';
    echo 'function changeSemester(semesterId) {';
    echo '    window.location.href = "' . $baseUrl . '" + semesterId;';
    echo '}';
    echo '</script>';
}

/**
 * Add semester parameter to URL if not present
 */
function addSemesterToUrl($url, $semesterId) {
    $parsedUrl = parse_url($url);
    $query = [];
    
    if (isset($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $query);
    }
    
    if (!isset($query['semester'])) {
        $query['semester'] = $semesterId;
    }
    
    $newUrl = $parsedUrl['path'] ?? '';
    if (!empty($query)) {
        $newUrl .= '?' . http_build_query($query);
    }
    
    return $newUrl;
}
?>