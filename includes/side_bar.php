<?php 
if (!isset($_SESSION)) {
    session_start();
}

if (!isset($_SESSION['role'])) {
    header("Location: index.php");
    exit;
}

$role = $_SESSION['role'];

// Student access is based on account status + current SY/semester enrollment.
$student_restricted = false;
if ($role === 'student') {
    $statusNorm = strtolower((string)($_SESSION['status'] ?? 'inactive'));
    $notEnrolled = isset($_SESSION['student_enrolled_current_term']) && $_SESSION['student_enrolled_current_term'] === '0';
    $student_restricted = ($statusNorm !== 'active') || $notEnrolled;
}

$sidebarLinks = [
    'student' => $student_restricted ? [
        // Not enrolled in current SY/semester: only Credential Request
        'Credential Request' => ['icon' => 'fa-file-signature', 'url' => 'credential_form_request.php'],
    ] : [
        // Enrolled: full access
        'Dashboard' => ['icon' => 'fa-home', 'url' => '/student/student_dashboard.php'],
        'Credential Request' => ['icon' => 'fa-file-signature', 'url' => '/student/credential_form_request.php'],
        'Evaluation Form' => ['icon' => 'fa-check-circle', 'url' => '/student/evaluation_form.php'],
    ],
    'teacher' => [
        'Dashboard' => ['icon' => 'fa-home', 'url' => '/teacher/teacher_dashboard.php'],
        'Evaluation Result' => ['icon' => 'fa-chart-bar', 'url' => '/evaluation_result.php'],
        'Data Analytics' => ['icon' => 'fa-chart-line', 'url' => '/data_analytics.php'],
    ],
    'registrar' => [
        'Dashboard' => ['icon' => 'fa-home', 'url' => '/registrar/registrar_dashboard.php'],
        'Faculty Information' => ['icon' => 'fa-user-graduate', 'url' => '/registrar/faculty_information_management.php'],
    ],
    'admin' => [
        'Admin Dashboard' => ['icon' => 'fa-user-shield', 'url' => '/admin/admin_dashboard.php'],
        'Evaluation Result' => ['icon' => 'fa-chart-bar', 'url' => '/evaluation_result.php'],
        'Data Analytics' => ['icon' => 'fa-chart-line', 'url' => '/data_analytics.php'],
        'User Management' => ['icon' => 'fa-users-cog', 'url' => '/admin/user_management.php'],
        'Section Management' => ['icon' => 'fa-chalkboard', 'url' => '/admin/section_management.php'],
        'Subject Management' => ['icon' => 'fa-book', 'url' => '/admin/subject_management.php'],
        'Evaluation Management' => ['icon' => 'fa-tasks', 'url' => '/admin/evaluation_management.php'],
    ],
];

$links = $sidebarLinks[$role] ?? [];
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Font Awesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --primary-color: #800000;
    --primary-hover: #a00000;
    --sidebar-width: 250px;
    --navbar-height: 60px;
}

/* Standardized Sidebar Styles */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: var(--sidebar-width);
    height: 100vh;
    background: linear-gradient(180deg, var(--primary-color) 0%, #600000 100%);
    padding: 0;
    box-sizing: border-box;
    color: #fff;
    z-index: 1000;
    overflow-y: auto;
    box-shadow: 4px 0 15px rgba(0, 0, 0, 0.15);
}

.sidebar-header {
    padding: 20px;
    text-align: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    background: rgba(0, 0, 0, 0.1);
}

.sidebar-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #fff;
    line-height: 1.4;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}

.sidebar-header .school-logo {
    width: 60px;
    height: 60px;
    margin: 0 auto 10px;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.2);
}

.sidebar-header .school-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.sidebar-nav {
    padding: 20px 0;
    flex: 1;
}

.sidebar ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar ul li {
    margin: 0;
}

.sidebar ul li a {
    color: rgba(255, 255, 255, 0.9);
    text-decoration: none;
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    padding: 15px 25px;
    font-weight: 600;
    position: relative;
    border-left: 3px solid transparent;
}

.sidebar ul li a::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    height: 100%;
    width: 0;
    background: rgba(255, 255, 255, 0.1);
    transition: width 0.3s ease;
    z-index: -1;
}

.sidebar ul li a:hover::before {
    width: 100%;
}

.sidebar ul li a:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.05);
    border-left-color: rgba(255, 255, 255, 0.3);
    transform: translateX(5px);
}

.sidebar ul li a.active {
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
    border-left-color: #fff;
    font-weight: 700;
    box-shadow: inset 0 0 20px rgba(255, 255, 255, 0.1);
}

.sidebar ul li a.active::before {
    width: 100%;
}

.sidebar ul li a i {
    min-width: 22px;
    text-align: center;
    font-size: 18px;
    opacity: 0.9;
}

.sidebar ul li a.active i {
    opacity: 1;
}

/* Main Content Layout */
.main-content {
    margin-left: var(--sidebar-width);
    margin-top: var(--navbar-height);
    padding: 30px;
    min-height: calc(100vh - var(--navbar-height));
    background-color: #f8f9fa;
}

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        z-index: 1002;
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
    
    .main-content {
        margin-left: 0;
    }
    
    .sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1001;
        display: none;
    }
    
    .sidebar-overlay.show {
        display: block;
    }
}

/* Scrollbar Styling */
.sidebar::-webkit-scrollbar {
    width: 6px;
}

.sidebar::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
}

.sidebar::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.3);
    border-radius: 3px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.5);
}
</style>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar Markup -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="school-logo">
            <img src="/images/school-logo.png" alt="HRSSI Logo">
        </div>
        <h3>Holy Redeemer School<br>of San Isidro</h3>
    </div>
    
    <div class="sidebar-nav">
        <ul>
            <?php foreach ($links as $name => $data): ?>
                <li>
                    <a href="<?= htmlspecialchars($data['url']) ?>" 
                       class="<?= ($currentPage == basename($data['url'])) ? 'active' : '' ?>">
                        <i class="fas <?= htmlspecialchars($data['icon']) ?>"></i>
                        <?= htmlspecialchars($name) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}

// Close sidebar when clicking on a link (mobile)
document.querySelectorAll('.sidebar a').forEach(link => {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});
</script>