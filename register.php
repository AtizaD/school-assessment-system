<?php
// register.php
define('BASEPATH', dirname(__FILE__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirectTo('/index.php');
}

$error = '';
$success = '';

// Initialize form values to be preserved in case of errors
$formData = [
    'first_name' => '',
    'last_name' => '',
    'program' => '',
    'level' => '',
    'class_id' => '',
    'password' => ''
];

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get available programs
    $stmt = $db->query("SELECT program_id, program_name FROM Programs ORDER BY program_name");
    $programs = $stmt->fetchAll();

    // Get unique levels from classes table, ordered numerically
    $stmt = $db->query(
        "SELECT DISTINCT level 
         FROM Classes 
         ORDER BY CAST(level AS UNSIGNED), level"
    );
    $levels = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Save form values to be redisplayed if validation fails
        $formData = [
            'first_name' => trim(sanitizeInput($_POST['first_name'] ?? '')),
            'last_name' => trim(sanitizeInput($_POST['last_name'] ?? '')),
            'program' => filter_input(INPUT_POST, 'program', FILTER_VALIDATE_INT) ?: '',
            'level' => trim(sanitizeInput($_POST['level'] ?? '')),
            'class_id' => filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT) ?: '',
            'password' => $_POST['password'] ?? ''
        ];

        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid request');
        }

        $firstName = $formData['first_name'];
        $lastName = $formData['last_name'];
        $classId = $formData['class_id'];
        $level = $formData['level'];
        $password = $formData['password'];

        if (!$firstName || !$lastName || !$classId || !$level || !$password) {
            throw new Exception('All fields are required');
        }

        // Validate password length
        if (strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters long');
        }

        // Get class details for username generation
        $stmt = $db->prepare("SELECT c.class_name FROM Classes c WHERE c.class_id = ?");
        $stmt->execute([$classId]);
        $classInfo = $stmt->fetch();

        if (!$classInfo) {
            throw new Exception('Invalid class selected');
        }

        // Check if a student with the same name already exists in this class
        $stmt = $db->prepare(
            "SELECT s.student_id, u.username 
             FROM Students s 
             JOIN Users u ON s.user_id = u.user_id
             WHERE (
                (LOWER(s.first_name) = LOWER(?) AND LOWER(s.last_name) = LOWER(?))
                OR 
                (LOWER(s.first_name) = LOWER(?) AND LOWER(s.last_name) = LOWER(?))
             )
             AND s.class_id = ?"
        );
        $stmt->execute([
            strtolower($firstName),
            strtolower($lastName),
            strtolower($lastName),  // Swapped order check
            strtolower($firstName), // Swapped order check
            $classId
        ]);

        $existingStudent = $stmt->fetch();
        if ($existingStudent) {
            throw new Exception("A student with this name is already registered in this class. Username: {$existingStudent['username']}. Please contact your teacher if this is not you.");
        }

        // Generate base username from class name and initials
        $nameParts = array_merge(
            explode(' ', $firstName),
            explode(' ', $lastName)
        );
        array_walk($nameParts, function(&$part) {
            $part = trim($part);
        });
        $nameParts = array_filter($nameParts);
        sort($nameParts, SORT_STRING | SORT_FLAG_CASE);

        // Generate initials from sorted names
        $initials = '';
        foreach ($nameParts as $part) {
            $initials .= substr($part, 0, 1);
        }
        $initials = strtoupper($initials);
        $baseUsername = trim($classInfo['class_name']) . '-' . $initials;

        // Find the highest existing suffix for this base username
        $stmt = $db->prepare(
            "SELECT username FROM Users 
             WHERE username LIKE ? 
             ORDER BY CAST(SUBSTRING(username, -3) AS UNSIGNED) DESC 
             LIMIT 1"
        );
        $stmt->execute([$baseUsername . '%']);
        $lastUsername = $stmt->fetch();

        // Determine the next suffix to use
        if ($lastUsername) {
            // Extract the numeric suffix and increment it
            preg_match('/(\d+)$/', $lastUsername['username'], $matches);
            $suffix = isset($matches[1]) ? (intval($matches[1]) + 1) : 1;
        } else {
            $suffix = 1;
        }

        $username = $baseUsername . str_pad($suffix, 3, '0', STR_PAD_LEFT);

        // Double check that this username doesn't already exist (extra safety check)
        $stmt = $db->prepare("SELECT username FROM Users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            // This should rarely happen if our suffix calculation is correct,
            // but as a fallback, we'll keep incrementing until we find an unused username
            $counter = $suffix + 1;
            $isUnique = false;
            
            while (!$isUnique && $counter < 1000) { // Set a reasonable limit
                $candidateUsername = $baseUsername . str_pad($counter, 3, '0', STR_PAD_LEFT);
                $stmt = $db->prepare("SELECT username FROM Users WHERE username = ?");
                $stmt->execute([$candidateUsername]);
                if (!$stmt->fetch()) {
                    $username = $candidateUsername;
                    $isUnique = true;
                }
                $counter++;
            }
            
            if (!$isUnique) {
                throw new Exception('Unable to generate a unique username. Please contact support.');
            }
        }

        // Start transaction
        $db->beginTransaction();

        try {
            // Create user account
            $stmt = $db->prepare(
                "INSERT INTO Users (username, password_hash, role, first_login, password_change_required) 
                 VALUES (?, ?, 'student', FALSE, FALSE)"
            );
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = $db->lastInsertId();

            // Create student record with properly cased names
            $stmt = $db->prepare(
                "INSERT INTO Students (first_name, last_name, class_id, user_id) 
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                ucwords(strtolower($firstName)),
                ucwords(strtolower($lastName)),
                $classId,
                $userId
            ]);

            $db->commit();
            
            $_SESSION['registration_success'] = [
                'username' => $username,
                'name' => ucwords(strtolower("$firstName $lastName")),
                'password' => $password,
                'password_set' => true
            ];
            
            header('Location: register_success.php');
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            throw new Exception('Registration failed: ' . $e->getMessage());
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Registration error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - BASS</title>
    <link href="<?php echo BASE_URL; ?>/assets/css/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        /* Base styling */
        :root {
            --primary: #FFD700;
            --primary-light: #FFE5A8;
            --primary-dark: #CCB000;
            --black: #000000;
            --white: #FFFFFF;
            --text-dark: #333333;
            --text-light: #FFFFFF;
            --error: #dc2626;
            --error-light: #fee2e2;
        }

        /* Reset and basic styling */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            padding: 20px;
            position: relative;
            background-color: #222; /* Fallback color */
        }

        /* Background styling */
        .background-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }

        .background-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .background-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.7) 100%);
        }

        /* Registration container */
        .registration-container {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 15px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
            text-align: center;
            position: relative;
            transition: transform 0.3s ease;
        }

        .registration-container:hover {
            transform: translateY(-5px);
        }

        /* School logo and header */
        .registration-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }

        .school-logo {
            width: 70px;
            height: 70px;
            object-fit: contain;
            margin-right: 15px;
            border-radius: 50%;
            padding: 4px;
            background-color: white;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .header-text {
            text-align: left;
        }

        .header-text h1 {
            color: var(--text-dark);
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .header-text p {
            color: #666;
            font-size: 15px;
        }

        /* Progress steps */
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
        }

        .step {
            flex: 1;
            height: 4px;
            background-color: #e5e5e5;
            margin: 0 3px;
            border-radius: 2px;
            transition: background-color 0.3s ease;
        }

        .step.active {
            background-color: var(--primary);
        }

        /* Form styling with floating labels */
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
            position: relative;
            flex: 1;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            font-size: 15px;
            background-color: transparent;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            color: var(--text-dark);
            height: 46px;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        .form-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23666' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            padding-right: 35px;
        }

        /* Important: Fix for autofill background */
        .form-group input:-webkit-autofill,
        .form-group input:-webkit-autofill:hover,
        .form-group input:-webkit-autofill:focus,
        .form-group select:-webkit-autofill,
        .form-group select:-webkit-autofill:hover,
        .form-group select:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 30px white inset !important;
            -webkit-text-fill-color: var(--text-dark) !important;
            transition: background-color 5000s ease-in-out 0s !important;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.2);
        }

        /* Floating label styling */
        .form-group label {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            background-color: transparent;
            color: #777;
            padding: 0 5px;
            font-size: 15px;
            transition: all 0.3s ease;
            pointer-events: none;
        }

        .form-group input:focus + label,
        .form-group input:not(:placeholder-shown) + label,
        .form-group select:focus + label,
        .form-group select:not([value=""]):valid + label {
            top: 0;
            font-size: 12px;
            color: var(--primary-dark);
            background-color: white;
            transform: translateY(-50%);
        }

        /* Password toggle */
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            z-index: 2;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--primary-dark);
        }

        /* Password requirements */
        .password-requirements {
            margin-bottom: 20px;
            text-align: center;
        }

        .password-requirements small {
            color: #666;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* Fix placeholder opacity to hide it for floating label effect */
        .form-group input::placeholder,
        .form-group select::placeholder {
            opacity: 0;
            color: transparent;
        }

        /* Name format hint */
        .name-hint {
            font-size: 12px;
            color: #777;
            margin-top: 4px;
            margin-left: 15px;
            display: block;
        }

        /* Buttons */
        button[type="submit"] {
            width: 100%;
            padding: 14px;
            background: var(--black);
            color: var(--primary);
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
        }

        button[type="submit"]:hover {
            background: var(--primary);
            color: var(--black);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .login-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px;
            background: transparent;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            color: #666;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
        }

        .login-link:hover {
            background: #f5f5f5;
            border-color: var(--primary);
            color: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Alert styling */
        .alert-error {
            background-color: var(--error-light);
            border-left: 4px solid var(--error);
            color: var(--error);
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: left;
        }

        /* Mobile responsive adjustments */
        @media (max-width: 480px) {
            .registration-container {
                padding: 25px 20px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <div class="background-container">
        <img src="<?php echo ASSETS_URL; ?>/images/backgrounds/image1.jpg" alt="background" class="background-image">
        <div class="background-overlay"></div>
    </div>

    <div class="registration-container">
        <div class="registration-header">
            <img src="<?php echo ASSETS_URL; ?>/images/logo.png" alt="School Logo" class="school-logo">
            <div class="header-text">
                <h1>BREMAN ASIKUMA SHS</h1>
                <p>Student Registration</p>
            </div>
        </div>

        <!-- Progress indicator -->
        <div class="progress-steps">
            <div class="step active" id="step1"></div>
            <div class="step" id="step2"></div>
            <div class="step" id="step3"></div>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="registrationForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <!-- Group first and last name in a row -->
            <div class="form-row">
                <div class="form-group">
                    <input 
                        type="text" 
                        id="first_name" 
                        name="first_name" 
                        required 
                        pattern="[A-Za-z\s\-]+" 
                        title="Only letters, spaces, and hyphens allowed"
                        placeholder="First Name"
                        value="<?php echo htmlspecialchars($formData['first_name']); ?>"
                        autocomplete="given-name"
                    >
                    <label for="first_name">First Name</label>
                </div>

                <div class="form-group">
                    <input 
                        type="text" 
                        id="last_name" 
                        name="last_name" 
                        required 
                        pattern="[A-Za-z\s\-]+" 
                        title="Only letters, spaces, and hyphens allowed"
                        placeholder="Last Name"
                        value="<?php echo htmlspecialchars($formData['last_name']); ?>"
                        autocomplete="family-name"
                    >
                    <label for="last_name">Last Name</label>
                </div>
            </div>
            
            <!-- Group level and program in a row -->
            <div class="form-row">
                <div class="form-group">
                    <select id="level" name="level" required>
                        <option value="" disabled <?php echo empty($formData['level']) ? 'selected' : ''; ?>></option>
                        <?php foreach ($levels as $level): ?>
                            <option value="<?php echo htmlspecialchars($level); ?>" 
                                    <?php echo ($formData['level'] === $level) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($level); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="level">Level</label>
                </div>

                <div class="form-group">
                    <select id="program" name="program" required>
                        <option value="" disabled <?php echo empty($formData['program']) ? 'selected' : ''; ?>></option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo $program['program_id']; ?>"
                                    <?php echo ($formData['program'] == $program['program_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($program['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="program">Program</label>
                </div>
            </div>

            <!-- Class and Password fields -->
            <div class="form-row">
                <div class="form-group">
                    <select id="class_id" name="class_id" required>
                        <option value="" disabled selected></option>
                        <!-- Classes will be populated via JavaScript -->
                    </select>
                    <label for="class_id">Class</label>
                </div>

                <div class="form-group">
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required 
                        minlength="8"
                        placeholder="Password"
                        value="<?php echo htmlspecialchars($formData['password']); ?>"
                        autocomplete="new-password"
                        spellcheck="false"
                        data-lpignore="true"
                        data-1p-ignore="true"
                        data-bwignore="true"
                    >
                    <label for="password">Password</label>
                    <span class="password-toggle" onclick="togglePasswordVisibility('password')">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
            </div>

            <!-- Password requirements -->
            <div class="password-requirements">
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Password must be at least 8 characters long
                </small>
            </div>

            <button type="submit">
                <i class="fas fa-user-plus"></i>
                <span>Create Account</span>
            </button>
        </form>
        
        <a href="login.php" class="login-link">
            <i class="fas fa-sign-in-alt"></i>
            <span>Already have an account? Login here</span>
        </a>
    </div>

    <script>
    // Store the previously selected class ID
    const savedClassId = '<?php echo $formData['class_id']; ?>';
    
    function updateClasses(programId, level) {
        const classSelect = document.getElementById('class_id');
        const classLabel = classSelect.nextElementSibling;
        
        // Move label up while loading
        classLabel.style.top = '0';
        classLabel.style.fontSize = '12px';
        classLabel.style.backgroundColor = 'white';
        classLabel.style.color = '#777';
        
        classSelect.innerHTML = '<option value="" disabled selected>Loading classes...</option>';

        const formData = new FormData();
        formData.append('program_id', programId);
        formData.append('level', level);

        fetch('api/get_classes.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                classSelect.innerHTML = '<option value="" disabled selected></option>';
                // Sort classes by their numeric prefix
                data.classes.sort((a, b) => {
                    const prefixA = parseInt(a.class_name.match(/^[0-9]+/)) || 0;
                    const prefixB = parseInt(b.class_name.match(/^[0-9]+/)) || 0;
                    if (prefixA === prefixB) {
                        return a.class_name.localeCompare(b.class_name);
                    }
                    return prefixA - prefixB;
                });
                data.classes.forEach(classInfo => {
                    const option = document.createElement('option');
                    option.value = classInfo.class_id;
                    option.textContent = classInfo.class_name;
                    // Select the previously selected class
                    if (savedClassId && classInfo.class_id == savedClassId) {
                        option.selected = true;
                    }
                    classSelect.appendChild(option);
                });
                
                // If we have a saved class, ensure label stays up
                if (savedClassId) {
                    classLabel.style.top = '0';
                    classLabel.style.fontSize = '12px';
                    classLabel.style.backgroundColor = 'white';
                    classLabel.style.color = '#CCB000';
                } else {
                    // Reset label if no class selected
                    if (classSelect.value === '') {
                        classLabel.style.top = '50%';
                        classLabel.style.fontSize = '15px';
                        classLabel.style.backgroundColor = 'transparent';
                        classLabel.style.color = '#777';
                    }
                }
                
                // Update progress indicator
                updateProgressIndicator();
            } else {
                throw new Error(data.error || 'Failed to load classes');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            classSelect.innerHTML = '<option value="" disabled selected>Error loading classes</option>';
        });
    }

    // Update progress indicator based on form completion
    function updateProgressIndicator() {
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const step3 = document.getElementById('step3');
        
        const formFields = {
            names: document.getElementById('first_name').value && document.getElementById('last_name').value,
            selections: document.getElementById('level').value && document.getElementById('program').value,
            class: document.getElementById('class_id').value,
            password: document.getElementById('password').value
        };
        
        step1.classList.add('active');
        
        if (formFields.selections) {
            step2.classList.add('active');
        } else {
            step2.classList.remove('active');
        }
        
        if (formFields.class && formFields.password) {
            step3.classList.add('active');
        } else {
            step3.classList.remove('active');
        }
    }

    // Initialize floating labels based on select state
    function initFloatingSelects() {
        const selects = document.querySelectorAll('select');
        
        selects.forEach(select => {
            if (select.value) {
                const label = select.nextElementSibling;
                label.style.top = '0';
                label.style.fontSize = '12px';
                label.style.backgroundColor = 'white';
                label.style.color = '#CCB000';
            }
            
            select.addEventListener('focus', function() {
                const label = this.nextElementSibling;
                label.style.top = '0';
                label.style.fontSize = '12px';
                label.style.backgroundColor = 'white';
                label.style.color = '#CCB000';
            });
            
            select.addEventListener('blur', function() {
                const label = this.nextElementSibling;
                if (this.value === '') {
                    label.style.top = '50%';
                    label.style.fontSize = '15px';
                    label.style.backgroundColor = 'transparent';
                    label.style.color = '#777';
                }
            });
            
            select.addEventListener('change', function() {
                const label = this.nextElementSibling;
                if (this.value !== '') {
                    label.style.top = '0';
                    label.style.fontSize = '12px';
                    label.style.backgroundColor = 'white';
                    label.style.color = '#CCB000';
                }
            });
        });
    }

    // Initialize floating labels based on input state
    function initFloatingInputs() {
        const inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
        
        inputs.forEach(input => {
            if (input.value !== '') {
                const label = input.nextElementSibling;
                label.style.top = '0';
                label.style.fontSize = '12px';
                label.style.backgroundColor = 'white';
                label.style.color = '#CCB000';
            }
            
            input.addEventListener('focus', function() {
                const label = this.nextElementSibling;
                label.style.top = '0';
                label.style.fontSize = '12px';
                label.style.backgroundColor = 'white';
                label.style.color = '#CCB000';
            });
            
            input.addEventListener('blur', function() {
                const label = this.nextElementSibling;
                if (this.value === '') {
                    label.style.top = '50%';
                    label.style.fontSize = '15px';
                    label.style.backgroundColor = 'transparent';
                    label.style.color = '#777';
                }
            });
        });
    }

    // Monitor all form fields to update progress
    document.querySelectorAll('#registrationForm input, #registrationForm select').forEach(field => {
        field.addEventListener('change', updateProgressIndicator);
        field.addEventListener('input', updateProgressIndicator);
    });

    // Update classes when either program or level changes
    document.getElementById('program').addEventListener('change', updateClassList);
    document.getElementById('level').addEventListener('change', updateClassList);

    function updateClassList() {
        const programId = document.getElementById('program').value;
        const level = document.getElementById('level').value;
        if (programId && level) {
            updateClasses(programId, level);
        } else {
            document.getElementById('class_id').innerHTML = 
                '<option value="" disabled selected></option>';
        }
    }

    // Password visibility toggle function
    function togglePasswordVisibility(fieldId) {
        const passwordField = document.getElementById(fieldId);
        const toggleIcon = passwordField.nextElementSibling.nextElementSibling.querySelector('i');
        
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordField.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }

    // Password length validation
    function validatePassword(password) {
        const errors = [];
        
        if (password.length < 8) {
            errors.push('at least 8 characters');
        }
        
        return errors;
    }

    // Form validation
    document.getElementById('registrationForm').addEventListener('submit', function(e) {
        const firstName = document.getElementById('first_name').value.trim();
        const lastName = document.getElementById('last_name').value.trim();
        
        if (!/^[A-Za-z\s\-]+$/.test(firstName) || !/^[A-Za-z\s\-]+$/.test(lastName)) {
            e.preventDefault();
            alert('Names can only contain letters, spaces, and hyphens');
            return;
        }

        const level = document.getElementById('level').value;
        const program = document.getElementById('program').value;
        const classId = document.getElementById('class_id').value;
        const password = document.getElementById('password').value;

        if (!level || !program || !classId) {
            e.preventDefault();
            alert('Please select all required fields');
            return;
        }

        // Validate password
        const passwordErrors = validatePassword(password);
        if (passwordErrors.length > 0) {
            e.preventDefault();
            alert('Password must be ' + passwordErrors.join(', '));
            return;
        }
    });

    // Load classes if program and level are pre-selected (after form error)
    window.addEventListener('DOMContentLoaded', function() {
        const programSelect = document.getElementById('program');
        const levelSelect = document.getElementById('level');
        
        // Initialize all floating labels
        initFloatingInputs();
        initFloatingSelects();
        
        if (programSelect.value && levelSelect.value) {
            updateClasses(programSelect.value, levelSelect.value);
        }
        
        // Initialize progress indicator
        updateProgressIndicator();
    });

    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    </script>
</body>
</html>