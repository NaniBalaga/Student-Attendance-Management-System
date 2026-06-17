<?php
session_start();

// Restore session from cookie if session expired
if (!isset($_SESSION['email']) && isset($_COOKIE['user_email'])) {
    $_SESSION['email'] = $_COOKIE['user_email'];
}

// DB Connection
// WARNING: Hardcoding credentials is a security risk! Use environment variables or a secure configuration file.
$host = "localhost";
$user = "";
$pass = "";
$db = "";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    // In a production environment, avoid exposing database errors.
    error_log("Database Connection Failed: " . $conn->connect_error);
    die("A connection error occurred. Please try again later.");
}

$email = $_SESSION['email'] ?? null; // Null coalesce to handle case where session is not set but code continues for unverified check

// --- USER AUTHENTICATION & SESSION MANAGEMENT ---
if (empty($email)) {
    // Redirect if no email in session or cookie (unauthenticated user)
    session_destroy();
    setcookie('user_email', '', time() - 3600, "/");
    header("Location: login.php?error=unauthenticated");
    exit();
}

// Set cookie for 12 hours if not already set
if (!isset($_COOKIE['user_email'])) {
    setcookie('user_email', $email, time() + (12 * 60 * 60), "/"); // Cookie valid for 12 hours
}

// Fetch student data
// Use prepared statement to prevent SQL injection
$stmt = $conn->prepare("SELECT * FROM students WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();


// If user not found or not verified, logout
if (!$student || $student['is_verified'] == 0) {
    session_destroy();
    setcookie('user_email', '', time() - 3600, "/"); // Expire cookie
    header("Location: login.php?error=unverified");
    exit();
}

// Check Maintenance Mode
if (isset($student['maintenance_mode']) && $student['maintenance_mode'] == 1) {
    session_destroy();
    setcookie('user_email', '', time() - 3600, "/"); // Expire cookie
    header("Location: login.php?error=maintenance");
    exit();
}

// Session expiry (12 hours)
if (!isset($_SESSION['login_time'])) {
    $_SESSION['login_time'] = time();
} elseif (time() - $_SESSION['login_time'] > (12 * 60 * 60)) { // 12 hours
    session_unset();
    session_destroy();
    setcookie('user_email', '', time() - 3600, "/"); // Expire cookie
    header("Location: login.php?error=session_expired");
    exit();
}

// Update last activity to keep session alive during activity
$_SESSION['last_activity'] = time();

// Assign student values
$sound_status = $student['sounds'];
$mess_type = $student['mess_type'];
$profile_photo = $student['profile_photo'];

// --- ATTENDANCE CALCULATION LOGIC ---
$calculation_results = null;
$calculation_error = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['calculate_attendance'])) {
    // Sanitize and validate inputs
    $total_classes = filter_input(INPUT_POST, 'total_classes', FILTER_VALIDATE_INT);
    $attended_classes = filter_input(INPUT_POST, 'attended_classes', FILTER_VALIDATE_INT);
    $required_percentage = filter_input(INPUT_POST, 'required_percentage', FILTER_VALIDATE_INT);
    
    // Default values if validation failed for display purposes
    $total_classes = $total_classes === false ? 0 : $total_classes;
    $attended_classes = $attended_classes === false ? 0 : $attended_classes;
    $required_percentage = $required_percentage === false ? 75 : $required_percentage;

    // Detailed validation
    if ($attended_classes > $total_classes) {
        $calculation_error = "Attended classes cannot be more than total classes!";
    } elseif ($total_classes <= 0) {
        $calculation_error = "Total classes must be greater than zero!";
    } elseif ($required_percentage < 1 || $required_percentage > 100) {
        $calculation_error = "Required percentage must be between 1 and 100!";
    } else {
        // Calculate values
        $current_percentage = ($attended_classes / $total_classes) * 100;

        // Formula for classes needed to reach required percentage
        if (100 - $required_percentage == 0) {
            $classes_needed_raw = ($current_percentage >= 100) ? 0 : INF;
        } else {
            $classes_needed_raw = ($required_percentage * $total_classes - 100 * $attended_classes) / (100 - $required_percentage);
        }
        $classes_needed = ceil(max(0, $classes_needed_raw)); // Use max(0, ...) to ensure non-negative

        // Formula for classes can bunk (maximum number of classes missed while maintaining R%)
        if ($required_percentage == 0) {
            $classes_can_bunk_raw = INF;
        } else {
            $classes_can_bunk_raw = (100 * $attended_classes - $required_percentage * $total_classes) / $required_percentage;
        }
        $classes_can_bunk = floor(max(0, $classes_can_bunk_raw)); // Use max(0, ...) to ensure non-negative and floor

        // Refined Logic based on current state
        if ($current_percentage >= $required_percentage) {
             $classes_needed = 0;
             $classes_can_bunk = max(0, $classes_can_bunk);
        } else {
             $classes_can_bunk = 0;
             $classes_needed = max(0, $classes_needed);
        }

        // Save to history (Using the student's actual email, which is authenticated)
        $stmt = $conn->prepare("INSERT INTO attendance_history (email, total_classes, attended_classes, required_percentage, current_percentage, classes_needed, classes_can_bunk) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siiidii", $email, $total_classes, $attended_classes, $required_percentage, $current_percentage, $classes_needed, $classes_can_bunk);
        $stmt->execute();
        $stmt->close();
        
        // Track usage
        $usage_stmt = $conn->prepare("INSERT INTO calculator_usage (email) VALUES (?)");
        $usage_stmt->bind_param("s", $email);
        $usage_stmt->execute();
        $usage_stmt->close();
        
        // Set calculation results for display
        $calculation_results = [
            'total_classes' => $total_classes,
            'attended_classes' => $attended_classes,
            'required_percentage' => $required_percentage,
            'current_percentage' => $current_percentage,
            'classes_needed' => $classes_needed,
            'classes_can_bunk' => $classes_can_bunk
        ];
    }
}

// --- DATA FETCH FOR DISPLAY ---

// Fetch user's calculation history (MODIFIED: Removed LIMIT 10)
$history_stmt = $conn->prepare("SELECT * FROM attendance_history WHERE email = ? ORDER BY calculation_date DESC");
$history_stmt->bind_param("s", $email);
$history_stmt->execute();
$history_result = $history_stmt->get_result();
$calculation_history = [];
while($row = $history_result->fetch_assoc()) {
    $calculation_history[] = $row;
}
$history_stmt->close();

// Get user's total lifetime usage count
$user_usage_stmt = $conn->prepare("SELECT COUNT(*) as user_usage FROM calculator_usage WHERE email = ?");
$user_usage_stmt->bind_param("s", $email);
$user_usage_stmt->execute();
$user_usage_result = $user_usage_stmt->get_result();
$user_usage = $user_usage_result->fetch_assoc()['user_usage'] ?? 0;
$user_usage_stmt->close();

// Get user's usage count for TODAY (using attendance_history)
$today = date('Y-m-d'); // Current date in MySQL format
$user_today_usage_stmt = $conn->prepare("SELECT COUNT(*) as today_usage FROM attendance_history WHERE email = ? AND DATE(calculation_date) = ?");
$user_today_usage_stmt->bind_param("ss", $email, $today);
$user_today_usage_stmt->execute();
$user_today_usage_result = $user_today_usage_stmt->get_result();
$user_today_usage = $user_today_usage_result->fetch_assoc()['today_usage'] ?? 0;
$user_today_usage_stmt->close();

// Get total calculator usage count (Global)
$usage_stmt = $conn->prepare("SELECT COUNT(*) as total_usage FROM calculator_usage");
$usage_stmt->execute();
$usage_result = $usage_stmt->get_result();
$total_usage = $usage_result->fetch_assoc()['total_usage'] ?? 0;
$usage_stmt->close();

// Get total unique registered users (Global)
$users_stmt = $conn->prepare("SELECT COUNT(*) as total_users FROM students WHERE is_verified = 1");
$users_stmt->execute();
$users_result = $users_stmt->get_result();
$total_users = $users_result->fetch_assoc()['total_users'] ?? 0;
$users_stmt->close();

$conn->close();

// Get color variables from the original CSS to use in PHP logic for the default profile picture.
$secondary = '#FFD700';
$accent = '#FFA500';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Calculator | Royal Edition</title>
    <link rel="icon" href="../team/logo_bg_new.jpeg" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #000000;
            --secondary: #FFD700; /* Gold */
            --accent: #FFA500;    /* Orange */
            --light: #1a1a1a;
            --dark: #0d0d0d;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --text: #ffffff;
            --text-secondary: #aaaaaa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
            --radius: 12px;
            --transition: all 0.3s ease;
        }

        /* Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--primary);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--primary) 0%, var(--dark) 100%);
        }

        .container {
            /* MODIFIED: Increased max-width for desktop full width feel */
            max-width: 1200px; 
            margin: 0 auto;
            padding: 0 15px;
        }

        /* Header Styles */
        header {
            background-color: rgba(0, 0, 0, 0.9);
            padding: 15px 0;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo i {
            color: var(--secondary);
            font-size: 1.8rem;
        }

        .logo h1 {
            font-size: 1.2rem;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-links {
            display: none;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--secondary);
            box-shadow: 0 0 8px rgba(255, 215, 0, 0.5);
        }

        .user-menu span {
            font-weight: 600;
            font-size: 0.9rem;
            max-width: 100px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        /* Main Content & Dashboard */
        .dashboard {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            padding-top: 20px;
            padding-bottom: 40px;
        }

        .card {
            background-color: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .card-title {
            font-size: 1.3rem;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            background-color: var(--dark);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--radius);
            color: var(--text);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.3);
        }

        .btn {
            display: inline-block;
            padding: 12px 20px;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            color: var(--primary);
            border: none;
            border-radius: var(--radius);
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 6px rgba(255, 215, 0, 0.2);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 10px rgba(255, 215, 0, 0.5);
        }

        .btn-block {
            display: block;
            width: 100%;
        }

        /* Results Styles */
        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .result-item {
            background-color: var(--dark);
            padding: 15px;
            border-radius: var(--radius);
            text-align: center;
            border: 1px solid var(--text-secondary); 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
            transition: all 0.3s ease-in-out; /* Added transition */
        }

        .result-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-weight: 500;
        }

        .result-value {
            font-size: 1.4rem;
            font-weight: 700;
        }
        
        /* Status Colors - applies to the whole result item for best effect */
        .success {
            border-color: var(--success);
            box-shadow: 0 0 10px rgba(40, 167, 69, 0.4);
            color: var(--success); /* Set color for value */
        }

        .warning {
            border-color: var(--warning);
            box-shadow: 0 0 10px rgba(255, 193, 7, 0.4);
            color: var(--warning);
        }

        .danger {
            border-color: var(--danger);
            box-shadow: 0 0 10px rgba(220, 53, 69, 0.4);
            color: var(--danger);
        }

        .success .result-label, .warning .result-label, .danger .result-label {
            color: var(--text); /* Keep label visible */
        }

        /* Alert/Error Message */
        .alert-error {
            color: var(--danger); 
            margin-top: 15px; 
            padding: 12px; 
            background: rgba(220, 53, 69, 0.2); 
            border-radius: var(--radius);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* History Styles */
        .history-list {
            max-height: 350px;
            overflow-y: scroll;
            -ms-overflow-style: none;
            scrollbar-width: none;
            padding-right: 0;
            position: relative;
        }
        
        .history-list::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
        
        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background-color: var(--dark);
            border-radius: var(--radius);
            margin-bottom: 10px;
            transition: background-color 0.2s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .history-item:hover {
            background-color: rgba(255, 215, 0, 0.05);
        }
        
        .history-content {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-grow: 1;
            min-width: 0;
        }
        
        .history-serial {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent);
            flex-shrink: 0;
        }

        .history-details {
            display: flex;
            flex-direction: column;
            min-width: 0; 
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .history-date {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 3px;
        }

        .history-data {
            font-size: 0.9rem;
            white-space: nowrap; 
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .history-percentage {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--accent);
            flex-shrink: 0;
        }


        /* Stats Styles (sidebar cards) */
        .sidebar .card {
            background-color: var(--dark); 
            border: 1px solid rgba(255, 215, 0, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .global-stats-grid {
            grid-template-columns: 1fr;
        }
        
        .global-stats-grid .stat-card {
            grid-column: span 1;
        }

        .stat-card {
            background-color: var(--light);
            padding: 20px 10px;
            border-radius: var(--radius);
            text-align: center;
            border: 1px solid rgba(255, 215, 0, 0.1);
            transition: box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            box-shadow: 0 0 15px rgba(255, 215, 0, 0.2);
        }

        .stat-card i {
            color: var(--accent);
            font-size: 1.2rem;
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--secondary);
            margin: 5px 0;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        /* Quick Tips */
        .card ul {
            list-style: none;
            padding: 0;
        }
        
        .card ul li {
            padding: 8px 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.05);
            font-size: 0.95rem;
            color: var(--text-secondary);
            transition: color 0.2s;
        }
        
        .card ul li:hover {
            color: var(--secondary);
        }
        
        .card ul li:last-child {
            border-bottom: none;
        }

        /* --- MODIFIED: Modal Look Change --- */
        .modal {
            display: none; 
            position: fixed; 
            z-index: 200; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.9); /* Darker overlay for better focus */
            backdrop-filter: blur(10px); 
            padding-top: 0;
            transition: opacity 0.3s ease;
        }

        .modal-content {
            background-color: var(--dark); 
            margin: 5% auto; /* Move up slightly */
            padding: 30px;
            border-radius: 20px; /* More rounded */
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8), 0 0 40px rgba(255, 215, 0, 0.2); 
            max-width: 90%;
            width: 450px; /* Slightly wider */
            text-align: center;
            position: relative;
            animation: bounceIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        @keyframes bounceIn {
            0% { opacity: 0; transform: scale(0.7) translateY(100px); }
            80% { opacity: 1; transform: scale(1.05) translateY(-5px); }
            100% { transform: scale(1) translateY(0); }
        }
        
        .modal-content.success-modal { 
            box-shadow: 0 0 40px rgba(40, 167, 69, 0.7), 0 10px 30px rgba(0, 0, 0, 0.8); 
        }
        .modal-content.warning-modal { 
            box-shadow: 0 0 40px rgba(255, 193, 7, 0.7), 0 10px 30px rgba(0, 0, 0, 0.8); 
        }
        .modal-content.danger-modal { 
            box-shadow: 0 0 40px rgba(220, 53, 69, 0.7), 0 10px 30px rgba(0, 0, 0, 0.8); 
        }

        .modal-icon {
            font-size: 4rem; /* Larger icon */
            margin-bottom: 10px;
            line-height: 1;
            transition: color 0.3s ease;
        }
        
        .modal-title {
            font-size: 2.2rem;
            margin-bottom: 15px;
            font-weight: 900;
            color: var(--secondary);
            text-transform: uppercase;
        }
        
        .modal-body p {
            font-size: 1.1rem;
            margin-bottom: 30px;
            color: #d4d4d4;
            padding: 0 10px;
        }
        
        .modal-stats {
            display: flex;
            justify-content: space-around;
            margin-top: 20px;
            padding: 20px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            gap: 10px;
        }
        
        .modal-stat-item {
            text-align: center;
        }
        
        .modal-stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--accent);
            margin-bottom: 5px;
        }
        
        .modal-stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .close-btn {
            color: var(--text-secondary);
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 15px;
            right: 25px;
            transition: color 0.2s, background 0.2s;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            width: 35px; /* Larger hit area */
            height: 35px;
            line-height: 35px;
            text-align: center;
        }

        .close-btn:hover,
        .close-btn:focus {
            color: var(--secondary);
            background: rgba(255, 215, 0, 0.3);
            text-decoration: none;
            cursor: pointer;
        }
        /* --- END: Modal Look Change --- */

        /* --- Skeleton Loader CSS --- */
        .skeleton-loader {
            background-color: var(--dark);
            background: linear-gradient(110deg, var(--dark) 8%, var(--light) 18%, var(--dark) 33%);
            background-size: 200% 100%;
            animation: 1.5s loading-skeleton-animation linear infinite;
            border-radius: 4px;
        }

        .skeleton-history-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background-color: var(--dark);
            border-radius: var(--radius);
            margin-bottom: 10px;
            height: 60px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .skeleton-text-line {
            height: 10px;
            margin-bottom: 5px;
        }

        .skeleton-history-details {
            width: 70%;
            margin-left: 15px;
        }

        .skeleton-history-details .skeleton-text-line:nth-child(1) {
            width: 60%;
        }

        .skeleton-history-details .skeleton-text-line:nth-child(2) {
            width: 90%;
            margin-bottom: 0;
        }
        
        .skeleton-history-percentage {
            width: 15%;
            height: 20px;
            margin-left: auto;
        }
        
        .skeleton-history-serial {
            width: 20px;
            height: 20px;
        }

        @keyframes loading-skeleton-animation {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        .history-list-content.loading {
            display: none;
        }

        .skeleton-container.hidden {
            display: none;
        }
        /* --- END: Skeleton Loader CSS --- */

        /* Desktop/Tablet Styles */
        @media (min-width: 768px) {
            
            /* MODIFIED: Wider Container */
            .container, .header-content {
                max-width: 1200px; /* Wider for full desktop feel */
            }

            .logo h1 {
                font-size: 1.5rem;
            }
            
            .nav-links {
                display: flex;
                gap: 20px;
            }

            .nav-links a {
                color: var(--text);
                text-decoration: none;
                padding: 8px 15px;
                border-radius: var(--radius);
                transition: var(--transition);
                font-weight: 500;
            }

            .nav-links a:hover, .nav-links a.active {
                background-color: var(--secondary);
                color: var(--primary);
            }
            
            .user-menu span {
                font-size: 1rem;
                max-width: 150px;
            }
            
            .dashboard {
                grid-template-columns: 2fr 1fr;
                gap: 30px; /* Slightly wider gap */
                margin-top: 30px;
            }
            
            .results-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .history-list {
                max-height: 500px; /* Taller on desktop since we show all */
            }
        }
    </style>
</head>
<body>
    
<header style="background-color: rgba(0, 0, 0, 0.9); padding: 15px 0; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5); position: sticky; top: 0; z-index: 100; backdrop-filter: blur(10px);">
    <div class="header-content">
        
        <div class="logo">
            <a href="javascript:history.back()" style="text-decoration:none; color:inherit; display:flex; align-items:center;">
                <i class="fas fa-arrow-left" style="color: #FFD700; font-size: 1.2rem; line-height:1;"></i>
            </a>
            <h1>
                ATTENDANCE Calc
            </h1>
        </div>

        <div class="nav-links"></div>

        <div class="user-menu">
            <span>
                <?php echo htmlspecialchars($student['name'] ?? 'Guest'); ?>
            </span>
            
            <?php if(!empty($profile_photo)): ?>
                <img src="../profile/<?php echo htmlspecialchars($profile_photo); ?>" 
                    alt="Profile" 
                    class="profile-pic">
            <?php else: ?>
                <?php 
                    $initials = strtoupper(substr(trim($student['name'] ?? '?'), 0, 1)); 
                ?>
                <div style="background: linear-gradient(135deg, #FFD700, #FFA500); display: flex; align-items: center; justify-content: center; color: #000; font-weight: bold; font-size: 1rem; width: 40px; height: 40px; border-radius: 50%; border: 2px solid #FFD700; box-shadow: 0 0 8px rgba(255, 215, 0, 0.5);">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</header>

    <div class="container">
        <div class="dashboard">
            <div class="main-content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-calculator"></i> Attendance Calculator</h2>
                    </div>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="total_classes">Total Classes</label>
                            <input type="number" id="total_classes" name="total_classes" class="form-control" min="1" required value="<?php echo isset($calculation_results) ? htmlspecialchars($calculation_results['total_classes']) : ''; ?>" placeholder="Total number of classes">
                        </div>
                        <div class="form-group">
                            <label for="attended_classes">Classes Attended</label>
                            <input type="number" id="attended_classes" name="attended_classes" class="form-control" min="0" required value="<?php echo isset($calculation_results) ? htmlspecialchars($calculation_results['attended_classes']) : ''; ?>" placeholder="Classes you have attended so far">
                        </div>
                        <div class="form-group">
                            <label for="required_percentage">Required Percentage (%)</label>
                            <input type="number" id="required_percentage" name="required_percentage" class="form-control" min="1" max="100" required value="<?php echo isset($calculation_results) ? htmlspecialchars($calculation_results['required_percentage']) : '75'; ?>" placeholder="e.g., 75">
                        </div>
                        <button type="submit" name="calculate_attendance" class="btn btn-block">Calculate Attendance</button>
                    </form>

                    <?php if(isset($calculation_error)): ?>
                        <div class="alert-error">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($calculation_error); ?>
                        </div>
                    <?php endif; ?>

                    <?php 
                    $status_class = '';
                    $status_emoji = '';
                    $status_text = '';
                    $status_message = '';

                    if(isset($calculation_results)): 
                        $current_percentage = $calculation_results['current_percentage'];
                        $required_percentage = $calculation_results['required_percentage'];
                        $status_class = $current_percentage >= $required_percentage ? 'success' : ($current_percentage >= ($required_percentage - 5) ? 'warning' : 'danger');

                        if ($current_percentage >= $required_percentage) {
                            $status_text = 'On Track';
                            $status_emoji = '🎉';
                            $status_message = "Great job! Your current attendance is " . number_format($current_percentage, 2) . "%, which is above the required {$required_percentage}%. You can safely bunk up to {$calculation_results['classes_can_bunk']} more classes and maintain your required percentage.";
                        } elseif ($current_percentage >= ($required_percentage - 5)) {
                            $status_text = 'Close Call';
                            $status_emoji = '⚠️';
                            $status_message = "You're close to the minimum! Your attendance is at " . number_format($current_percentage, 2) . "%. You need to attend {$calculation_results['classes_needed']} consecutive classes to reach the {$required_percentage}% target.";
                        } else {
                            $status_text = 'Needs Attention';
                            $status_emoji = '🚨';
                            $status_message = "Critical status! Your attendance is low at " . number_format($current_percentage, 2) . "%. You must attend the next {$calculation_results['classes_needed']} consecutive classes to get back on track and avoid detention.";
                        }
                    ?>
                        <div class="results-grid" style="margin-top: 20px;">
                            <div class="result-item <?php echo $status_class; ?>">
                                <div class="result-label">Current %</div>
                                <div class="result-value" id="current-percent-value" data-final-value="<?php echo number_format($current_percentage, 2); ?>"><?php echo number_format($current_percentage, 2); ?>%</div>
                            </div>
                            <div class="result-item <?php echo $calculation_results['classes_needed'] == 0 ? 'success' : 'danger'; ?>">
                                <div class="result-label">Classes Needed</div>
                                <div class="result-value" id="classes-needed-value" data-final-value="<?php echo $calculation_results['classes_needed']; ?>"><?php echo $calculation_results['classes_needed']; ?></div>
                            </div>
                            <div class="result-item <?php echo $calculation_results['classes_can_bunk'] > 0 ? 'success' : 'danger'; ?>">
                                <div class="result-label">Classes Can Bunk</div>
                                <div class="result-value" id="classes-bunk-value" data-final-value="<?php echo $calculation_results['classes_can_bunk']; ?>"><?php echo $calculation_results['classes_can_bunk']; ?></div>
                            </div>
                            <div class="result-item <?php echo $status_class; ?>" id="open-status-modal" style="cursor: pointer;">
                                <div class="result-label">Status (Tap/Click)</div>
                                <div class="result-value"><?php echo htmlspecialchars($status_text); ?></div>
                            </div>
                        </div>

                        <div id="modal-status-text" 
                             data-status-class="<?php echo $status_class; ?>" 
                             data-status-emoji="<?php echo htmlspecialchars($status_emoji); ?>" 
                             data-status-title="<?php echo htmlspecialchars($status_text); ?>" 
                             data-status-message="<?php echo htmlspecialchars($status_message); ?>" 
                             data-current-percent="<?php echo number_format($current_percentage, 2); ?>" 
                             data-needed-classes="<?php echo $calculation_results['classes_needed']; ?>" 
                             data-bunk-classes="<?php echo $calculation_results['classes_can_bunk']; ?>" 
                             data-required-percent="<?php echo $required_percentage; ?>"
                             data-calculation-success="true">
                        </div>

                    <?php endif; ?>
                </div>
<br>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-history"></i> Recent Calculations (All Time)</h2>
                    </div>
                    <div class="history-list">
                        
                        <div id="skeleton-container" class="skeleton-container">
                            <?php for ($i = 0; $i < 4; $i++): ?>
                            <div class="skeleton-history-item">
                                <div class="skeleton-history-serial skeleton-loader"></div>
                                <div class="skeleton-history-details">
                                    <div class="skeleton-text-line skeleton-loader"></div>
                                    <div class="skeleton-text-line skeleton-loader"></div>
                                </div>
                                <div class="skeleton-history-percentage skeleton-loader"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <div id="history-list-content" class="history-list-content loading">
                            <?php if(empty($calculation_history)): ?>
                                <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
                                    <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                    <p>No calculation history yet. Calculate above to save your first entry!</p>
                                </div>
                            <?php else: ?>
                                <?php $counter = 1; ?>
                                <?php foreach($calculation_history as $history): ?>
                                    <div class="history-item">
                                        <div class="history-content">
                                            <div class="history-serial"><?php echo $counter++; ?>.</div>
                                            
                                            <div class="history-details">
                                                <div class="history-date" data-timestamp="<?php echo strtotime($history['calculation_date']); ?>">
                                                    <?php echo date('M j, Y g:i A', strtotime($history['calculation_date'])); ?>
                                                </div>
                                                <div class="history-data" title="<?php echo $history['attended_classes']; ?> / <?php echo $history['total_classes']; ?> classes (Req: <?php echo $history['required_percentage']; ?>%)">
                                                    <?php echo $history['attended_classes']; ?> / <?php echo $history['total_classes']; ?> classes (Req: <?php echo $history['required_percentage']; ?>%)
                                                </div>
                                            </div>
                                        </div>
                                        <div class="history-percentage"><?php echo number_format($history['current_percentage'], 2); ?>%</div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sidebar">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-user"></i> Your Stats</h2>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <i class="fas fa-calculator"></i>
                            <div class="stat-value" id="user-usage-value" data-final-value="<?php echo $user_usage; ?>"><?php echo $user_usage; ?></div>
                            <div class="stat-label">Your Calculations</div>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-calendar-day"></i>
                            <div class="stat-value" id="user-today-usage-value" data-final-value="<?php echo $user_today_usage; ?>"><?php echo $user_today_usage; ?></div>
                            <div class="stat-label">Today's Calculations</div>
                        </div>
                    </div>
                </div>
                <br>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-globe"></i> Global Status</h2>
                    </div>
                    <div class="stats-grid global-stats-grid">
                        <div class="stat-card">
                            <i class="fas fa-users"></i>
                            <div class="stat-value" id="total-users-value" data-final-value="<?php echo $total_users; ?>"><?php echo $total_users; ?></div>
                            <div class="stat-label">Total Registered Users</div>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-chart-line"></i>
                            <div class="stat-value" id="total-usage-value" data-final-value="<?php echo $total_usage; ?>"><?php echo $total_usage; ?></div>
                            <div class="stat-label">Total Calculations App-Wide</div>
                        </div>
                    </div>
                </div>
                <br>

                <div class="card">
  <div class="card-header">
    <h2 class="card-title"><i class="fas fa-lightbulb"></i> Quick Tips</h2>
  </div>
  <ul>
    <li>Maintain at least <strong>75%</strong> attendance to avoid detention or restrictions.</li>
    <li>Keep a regular check on your attendance record to stay within safe limits.</li>
    <li>Plan your leaves wisely — make every bunk count!</li>
    <li>If your attendance is dropping, try attending several classes in a row to recover quickly.</li>
    <li>Aim for at least <strong>5%</strong> above the minimum required attendance to stay stress-free.</li>
    <li>Monitor <strong>subject-wise attendance</strong>; overall totals can sometimes be misleading.</li>
    <li>At <strong>100%</strong> attendance? You’ve earned the freedom to skip a class or two responsibly!</li>
  </ul>
</div>

            </div>
        </div>
    </div>
    <br>
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <span class="close-btn">&times;</span>
            <div id="modal-icon" class="modal-icon"></div>
            <div id="modal-title" class="modal-title"></div>
            <div class="modal-body">
                <p id="modal-message"></p>
                <div class="results-grid modal-stats">
                    <div class="result-item" id="modal-stat-current-item">
                        <div class="result-label">Current %</div>
                        <div id="modal-stat-current" class="result-value"></div>
                    </div>
                    <div class="result-item" id="modal-stat-needed-item">
                        <div class="result-label">Classes Needed</div>
                        <div id="modal-stat-needed" class="result-value"></div>
                    </div>
                    <div class="result-item" id="modal-stat-bunk-item">
                        <div class="result-label">Classes Can Bunk</div>
                        <div id="modal-stat-bunk" class="result-value"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer style="
      width: 100%;
      background-color: #000000;
      color: #cccccc;
      padding: 40px 20px;
      font-family: 'Inter', sans-serif;
      text-align: center;
">
    <div style="
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 24px;
    ">
        <div style="
          display: flex;
          align-items: center;
          gap: 15px;
          font-weight: 600;
          font-size: 1.5rem;
          color: #FFD700;
          text-shadow: 0 0 8px rgba(255, 215, 0, 0.4);
        ">
            <img src="../team/logo_bg_new.jpeg" alt="Logo" style="
              width: 52px;
              height: 52px;
              object-fit: cover;
              border-radius: 50%; /* Added border-radius for modern look */
              transition: transform 0.3s ease;
            " onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
            <span>VITAP UPDATES – ATTENDANCE CALC</span>
        </div>

        <p style="
          font-size: 1rem;
          line-height: 1.6;
          max-width: 720px;
          color: #bbbbbb;
        ">
            This website helps VITAP students calculate their attendance percentage and find out how many classes they can miss or need to attend to maintain the required criteria.<br>
            If you find this Attendance Calculator helpful, don't forget to share it with your friends!
        </p>

        <div style="
          font-size: 0.9rem;
          color: #888888;
          margin-top: 10px;
        ">
            &copy; <span id="year"></span> Nani Balaga. All rights reserved.
        </div>
    </div>
</footer>


    <script>
        // Set current year in footer
        document.getElementById('year').textContent = new Date().getFullYear();
        
        // --- Number Counting Animation Function ---
        function animateValue(objId, start, end, duration, isPercentage = false, decimalPlaces = 0) {
            const obj = document.getElementById(objId);
            if (!obj) return;
            let startTimestamp = null;
            
            // Ensure end value is a number and handle percentage conversion for display
            const finalEnd = parseFloat(end);
            if (isNaN(finalEnd)) return; 
            
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                
                let currentValue = start + (finalEnd - start) * progress;
                
                // Format the number based on requirements
                let displayValue = currentValue.toFixed(decimalPlaces);

                if (isPercentage) {
                    obj.textContent = displayValue + "%";
                } else {
                    obj.textContent = Math.round(currentValue);
                }
                
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                } else {
                    // Final value is set precisely to avoid floating point issues
                    if (isPercentage) {
                        obj.textContent = finalEnd.toFixed(decimalPlaces) + "%";
                    } else {
                        obj.textContent = Math.round(finalEnd);
                    }
                }
            };

            window.requestAnimationFrame(step);
        }

        // --- Execute Animations on Load ---
        function runAnimations() {
            const DURATION = 1000; // 1 second for counting up

            // 1. Dashboard Results Animation (if present)
            const resultData = document.getElementById('modal-status-text');
            if (resultData && resultData.getAttribute('data-calculation-success') === 'true') {
                const currentPercent = parseFloat(resultData.getAttribute('data-current-percent'));
                const classesNeeded = parseInt(resultData.getAttribute('data-needed-classes'));
                const classesBunk = parseInt(resultData.getAttribute('data-bunk-classes'));

                animateValue("current-percent-value", 0, currentPercent, DURATION, true, 2);
                animateValue("classes-needed-value", 0, classesNeeded, DURATION);
                animateValue("classes-bunk-value", 0, classesBunk, DURATION);
            }

            // 2. Sidebar Stats Animation
            animateValue("user-usage-value", 0, parseInt(document.getElementById('user-usage-value').getAttribute('data-final-value')), DURATION);
            animateValue("user-today-usage-value", 0, parseInt(document.getElementById('user-today-usage-value').getAttribute('data-final-value')), DURATION);
            animateValue("total-users-value", 0, parseInt(document.getElementById('total-users-value').getAttribute('data-final-value')), DURATION);
            animateValue("total-usage-value", 0, parseInt(document.getElementById('total-usage-value').getAttribute('data-final-value')), DURATION);
        }
        
        // Run animations after the page has rendered
        window.addEventListener('load', runAnimations);


        // --- Time Ago Functionality ---
        function timeAgo(timestamp) {
            const seconds = Math.floor((new Date() - new Date(timestamp * 1000)) / 1000);

            let interval = seconds / 31536000;
            if (interval > 1) {
                return Math.floor(interval) + (Math.floor(interval) === 1 ? " year ago" : " years ago");
            }
            interval = seconds / 2592000;
            if (interval > 1) {
                return Math.floor(interval) + (Math.floor(interval) === 1 ? " month ago" : " months ago");
            }
            interval = seconds / 86400;
            if (interval > 1) {
                return Math.floor(interval) + (Math.floor(interval) === 1 ? " day ago" : " days ago");
            }
            interval = seconds / 3600;
            if (interval > 1) {
                return Math.floor(interval) + (Math.floor(interval) === 1 ? " hour ago" : " hours ago");
            }
            interval = seconds / 60;
            if (interval > 1) {
                return Math.floor(interval) + (Math.floor(interval) === 1 ? " minute ago" : " minutes ago");
            }
            return Math.floor(seconds) + " seconds ago";
        }
        
        // Update history dates on load
        function updateHistoryDates() {
             document.querySelectorAll('.history-date').forEach(item => {
                 const timestamp = item.getAttribute('data-timestamp');
                 if (timestamp) {
                     item.textContent = timeAgo(timestamp);
                 }
             });
        }
        
        // Refresh time ago periodically
        setInterval(updateHistoryDates, 60000); // Update every minute
        
        // --- Skeleton Loader Logic ---
        const historyContent = document.getElementById('history-list-content');
        const skeletonContainer = document.getElementById('skeleton-container');

        if (historyContent && skeletonContainer && historyContent.children.length > 0) {
            // Show skeleton for 3 seconds only if there's actual history to eventually show
            setTimeout(() => {
                skeletonContainer.classList.add('hidden');
                historyContent.classList.remove('loading');
                updateHistoryDates(); // Initial update after content is displayed
            }, 1000); // Reduced delay to 1 second for a snappier feel
        } else {
            // If no history, hide skeleton immediately and show "no history" message
            if (skeletonContainer) skeletonContainer.classList.add('hidden');
            if (historyContent) historyContent.classList.remove('loading');
            updateHistoryDates(); // Run just once in case it was missed
        }
        // --- END: Skeleton Loader Logic ---


        // --- Status Modal Functionality ---
        const modal = document.getElementById("statusModal");
        const openBtn = document.getElementById("open-status-modal");
        const closeBtn = document.querySelector(".close-btn");
        const modalData = document.getElementById("modal-status-text");
        
        function showStatusModal() {
            if (!modalData || modalData.getAttribute('data-calculation-success') !== 'true') return;
            
            const statusClass = modalData.getAttribute('data-status-class');
            const statusEmoji = modalData.getAttribute('data-status-emoji');
            const statusTitle = modalData.getAttribute('data-status-title');
            const statusMessage = modalData.getAttribute('data-status-message');
            const currentPercent = modalData.getAttribute('data-current-percent');
            const neededClasses = modalData.getAttribute('data-needed-classes');
            const bunkClasses = modalData.getAttribute('data-bunk-classes');
            
            // Set Modal Content
            document.getElementById('modal-icon').textContent = statusEmoji;
            document.getElementById('modal-title').textContent = statusTitle;
            
            // Use innerHTML for message to render bold tags from PHP
            document.getElementById('modal-message').innerHTML = statusMessage; 
            
            document.getElementById('modal-stat-current').textContent = currentPercent + "%";
            document.getElementById('modal-stat-needed').textContent = neededClasses;
            document.getElementById('modal-stat-bunk').textContent = bunkClasses;
            
            // Apply status color to modal items
            document.getElementById('modal-stat-current-item').className = 'result-item ' + statusClass;
            document.getElementById('modal-stat-needed-item').className = 'result-item ' + (neededClasses == 0 ? 'success' : 'danger');
            document.getElementById('modal-stat-bunk-item').className = 'result-item ' + (bunkClasses > 0 ? 'success' : 'danger');

            // Set Modal Class for Styling
            modal.querySelector('.modal-content').className = 'modal-content ' + statusClass + '-modal';
            
            modal.style.display = "block";
            
            // Animate values within the modal
            animateValue("modal-stat-current", 0, parseFloat(currentPercent), 800, true, 2);
            animateValue("modal-stat-needed", 0, parseInt(neededClasses), 800);
            animateValue("modal-stat-bunk", 0, parseInt(bunkClasses), 800);
        }

        // 1. Manual trigger (click on result box)
        if (openBtn) {
            openBtn.onclick = showStatusModal;
        }
        
        // 2. Auto-trigger after successful form submission 
        if (modalData && modalData.getAttribute('data-calculation-success') === 'true') {
             // Use a slight delay to ensure smooth page render before popup
             setTimeout(showStatusModal, 500); 
        }

        // Close modal listeners
        if (closeBtn) {
            closeBtn.onclick = function() {
                modal.style.display = "none";
            }
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
        
        // --- Client-Side Form Validation ---
        document.querySelector('form').addEventListener('submit', function(e) {
            const totalClassesInput = document.getElementById('total_classes');
            const attendedClassesInput = document.getElementById('attended_classes');
            let errorContainer = document.querySelector('.alert-error');

            // If error container doesn't exist, create it (in case no server-side error occurred)
            if (!errorContainer) {
                const form = this;
                errorContainer = document.createElement('div');
                errorContainer.className = 'alert-error';
                errorContainer.style.display = 'none';
                form.parentNode.insertBefore(errorContainer, form.nextSibling);
            }
            
            const totalClasses = parseInt(totalClassesInput.value);
            const attendedClasses = parseInt(attendedClassesInput.value);
            
            errorContainer.style.display = 'none'; // Hide previous error
            errorContainer.innerHTML = '';


            if (isNaN(totalClasses) || totalClasses <= 0) {
                e.preventDefault();
                const msg = 'Total classes must be a number greater than zero!';
                errorContainer.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + msg;
                errorContainer.style.display = 'flex';
                totalClassesInput.focus();
                return false;
            }
            
            if (attendedClasses > totalClasses) {
                e.preventDefault();
                const msg = 'Attended classes cannot be more than total classes!';
                errorContainer.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + msg;
                errorContainer.style.display = 'flex';
                attendedClassesInput.focus();
                return false;
            }
        });
    </script>
</body>
</html>
