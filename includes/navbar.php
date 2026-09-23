<?php
if (!isset($_SESSION)) {
    session_start();
}

// Include database connection for notifications (ensure connection is alive)
include __DIR__ . '/db_connection.php';
// Get user information
$firstName = strtoupper($_SESSION['first_name'] ?? $_SESSION['username'] ?? '');
$lastName = strtoupper($_SESSION['last_name'] ?? '');
$role = ucfirst($_SESSION['role'] ?? 'User');
$fullName = trim($firstName . ' ' . $lastName);

$notifications = [];
$unreadCount = 0;

if (isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $user_role = $_SESSION['role'];
    
    // Get user ID based on role
    if ($user_role === 'student') {
        $user_stmt = $conn->prepare("SELECT s.student_id as user_id FROM students s 
                                     JOIN users u ON s.user_id = u.user_id 
                                     WHERE u.account_number = ?");
        $user_stmt->bind_param("s", $username);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_data = $user_result->fetch_assoc()) {
            $current_user_id = $user_data['user_id'];
        }
        $user_stmt->close();
    } elseif ($user_role === 'teacher') {
        $user_stmt = $conn->prepare("SELECT t.teacher_id as user_id FROM teachers t 
                                     JOIN users u ON t.user_id = u.user_id 
                                     WHERE u.account_number = ?");
        $user_stmt->bind_param("s", $username);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_data = $user_result->fetch_assoc()) {
            $current_user_id = $user_data['user_id'];
        }
        $user_stmt->close();
    } elseif ($user_role === 'registrar' || $user_role === 'admin') {
        $user_stmt = $conn->prepare("SELECT u.user_id FROM users u 
                                     WHERE u.account_number = ? AND (u.role = 'registrar' OR u.role = 'admin')");
        $user_stmt->bind_param("s", $username);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_data = $user_result->fetch_assoc()) {
            $current_user_id = $user_data['user_id'];
        }
        $user_stmt->close();
    }
    
    // Get notifications for current user
    if (isset($current_user_id)) {
        $notifications_stmt = $conn->prepare("SELECT * FROM notifications 
                                              WHERE user_id = ? AND user_type = ? 
                                              ORDER BY created_at DESC 
                                              LIMIT 10");
        $notifications_stmt->bind_param("is", $current_user_id, $user_role);
        $notifications_stmt->execute();
        $notifications_result = $notifications_stmt->get_result();
        
        while ($notification = $notifications_result->fetch_assoc()) {
            $time_diff = time() - strtotime($notification['created_at']);
            if ($time_diff < 60) {
                $time_ago = 'Just now';
            } elseif ($time_diff < 3600) {
                $time_ago = floor($time_diff / 60) . ' minutes ago';
            } elseif ($time_diff < 86400) {
                $time_ago = floor($time_diff / 3600) . ' hours ago';
            } else {
                $time_ago = floor($time_diff / 86400) . ' days ago';
            }
            // Prefer explicit link stored in DB; fallback to heuristic
            $destination_url = isset($notification['link']) && $notification['link'] ? $notification['link'] : '/';
            if (!$destination_url) {
                $title_lower = strtolower($notification['title']);
                $message_lower = strtolower($notification['message']);
                if (strpos($title_lower, 'evaluation') !== false || strpos($message_lower, 'evaluation') !== false) {
                    if ($user_role === 'student') {
                        $destination_url = '/student/evaluation_form.php';
                    } elseif ($user_role === 'teacher') {
                        $destination_url = '/evaluation_result.php';
                    } else {
                        $destination_url = '/evaluation_result.php';
                    }
                } elseif (strpos($title_lower, 'request status updated') !== false || strpos($message_lower, 'request') !== false) {
                    if ($user_role === 'student') {
                        $destination_url = '/student/credential_form_request.php';
                    } elseif ($user_role === 'registrar') {
                        $destination_url = '/registrar/faculty_information_management.php';
                    } else {
                        $destination_url = '/';
                    }
                }
            }
            
            $notifications[] = [
                'id' => $notification['id'],
                'title' => $notification['title'],
                'message' => $notification['message'],
                'type' => $notification['type'],
                'time' => $time_ago,
                'read' => (bool)$notification['is_read'],
                'url' => $destination_url
            ];
        }
        
        $notifications_stmt->close();
        
        // Get unread count
        $unread_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications 
                                       WHERE user_id = ? AND user_type = ? AND is_read = 0");
        $unread_stmt->bind_param("is", $current_user_id, $user_role);
        $unread_stmt->execute();
        $unread_result = $unread_stmt->get_result();
        if ($unread_data = $unread_result->fetch_assoc()) {
            $unreadCount = $unread_data['unread_count'];
        }
        $unread_stmt->close();
    }
}
?>

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --primary-color: #800000;
    --primary-hover: #a00000;
    --primary-light: rgba(128, 0, 0, 0.1);
    --navbar-height: 60px;
    --sidebar-width: 250px;
    --success-color: #28a745;
    --warning-color: #ffc107;
    --danger-color: #dc3545;
    --info-color: #17a2b8;
}

/* Enhanced Navbar Styles */
.navbar {
    width: calc(100% - var(--sidebar-width));
    height: var(--navbar-height);
    background: linear-gradient(135deg, var(--primary-color) 0%, #600000 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 25px;
    box-sizing: border-box;
    position: fixed;
    top: 0;
    left: var(--sidebar-width);
    z-index: 1001;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    backdrop-filter: blur(10px);
}

.navbar-left {
    display: flex;
    align-items: center;
    gap: 20px;
}

.mobile-toggle {
    display: none;
    background: none;
    border: none;
    color: white;
    font-size: 20px;
    cursor: pointer;
    padding: 8px;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.mobile-toggle:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: scale(1.05);
}

.navbar-right {
    display: flex;
    align-items: center;
    gap: 20px;
    position: relative;
}

.navbar-time {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.9);
    font-weight: 500;
    font-family: 'Inter', sans-serif;
    background: rgba(255, 255, 255, 0.1);
    padding: 8px 15px;
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

/* Modern Notification Bell */
.notification-container {
    position: relative;
}

.notification-bell {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: white;
    padding: 10px;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notification-bell:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-1px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}

.notification-bell i {
    font-size: 18px;
}

.notification-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #ff4757;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    border: 2px solid var(--primary-color);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

/* Modern Notification Dropdown */
.notification-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    width: 380px;
    max-height: 500px;
    overflow: hidden;
    z-index: 1003;
    display: none;
    border: 1px solid #e9ecef;
}

.notification-dropdown.show {
    display: block;
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.notification-header {
    background: linear-gradient(135deg, var(--primary-color), #600000);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notification-header h6 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    font-family: 'Inter', sans-serif;
}

.mark-all-read {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.mark-all-read:hover {
    background: rgba(255, 255, 255, 0.3);
}

.notification-list {
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    padding: 16px 20px;
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
}

.notification-item:hover {
    background: #f8f9fa;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-item.unread {
    background: linear-gradient(90deg, rgba(128, 0, 0, 0.02) 0%, transparent 100%);
    border-left: 3px solid var(--primary-color);
}

.notification-item.unread::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 8px;
    height: 8px;
    background: var(--primary-color);
    border-radius: 50%;
}

.notification-content {
    margin-left: 15px;
}

.notification-title {
    font-size: 14px;
    font-weight: 600;
    color: #333;
    margin-bottom: 4px;
    font-family: 'Inter', sans-serif;
}

.notification-message {
    font-size: 13px;
    color: #666;
    line-height: 1.4;
    margin-bottom: 6px;
}

.notification-time {
    font-size: 11px;
    color: #999;
    font-weight: 500;
}

.notification-type-icon {
    position: absolute;
    right: 15px;
    top: 16px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
}

.notification-type-icon.info {
    background: rgba(23, 162, 184, 0.1);
    color: var(--info-color);
}

.notification-type-icon.warning {
    background: rgba(255, 193, 7, 0.1);
    color: var(--warning-color);
}

.notification-type-icon.success {
    background: rgba(40, 167, 69, 0.1);
    color: var(--success-color);
}

.notification-type-icon.danger {
    background: rgba(220, 53, 69, 0.1);
    color: var(--danger-color);
}

.notification-footer {
    padding: 15px 20px;
    background: #f8f9fa;
    text-align: center;
}

.view-all-notifications {
    color: var(--primary-color);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.view-all-notifications:hover {
    color: var(--primary-hover);
    text-decoration: underline;
}

.navbar-user {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.1);
    padding: 8px 16px;
    border-radius: 25px;
    transition: all 0.3s ease;
    cursor: pointer;
    border: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
}

.navbar-user:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-1px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}

.user-avatar {
    width: 32px;
    height: 32px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.3);
    font-family: 'Inter', sans-serif;
}

.user-info {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.user-name {
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    line-height: 1.2;
    color: white;
    font-family: 'Inter', sans-serif;
}

.user-role {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 500;
    text-transform: capitalize;
    font-family: 'Inter', sans-serif;
}

/* User Dropdown */
.user-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
    padding: 8px 0;
    min-width: 180px;
    z-index: 1002;
    display: none;
    margin-top: 5px;
    border: 1px solid #e9ecef;
}

.user-dropdown.show {
    display: block;
    animation: slideDown 0.3s ease-out;
}

.user-dropdown a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    color: #333;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.user-dropdown a:hover {
    background: #f8f9fa;
    color: var(--primary-color);
}

.user-dropdown a i {
    font-size: 16px;
    width: 16px;
    text-align: center;
}

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .navbar {
        width: 100%;
        left: 0;
        padding: 0 15px;
    }
    
    .mobile-toggle {
        display: block;
    }
    
    .navbar-time {
        display: none;
    }
    
    .user-info {
        display: none;
    }
    
    .navbar-user {
        padding: 8px;
        min-width: auto;
    }
    
    .notification-dropdown {
        width: 320px;
        right: -20px;
    }
}

/* Scrollbar Styling */
.notification-list::-webkit-scrollbar {
    width: 4px;
}

.notification-list::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.notification-list::-webkit-scrollbar-thumb {
    background: var(--primary-color);
    border-radius: 2px;
}

.notification-list::-webkit-scrollbar-thumb:hover {
    background: var(--primary-hover);
}
</style>

<div class="navbar">
    <div class="navbar-left">
        <button class="mobile-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <div class="navbar-right">
        <div class="navbar-time" id="currentTime"></div>
        
        <!-- Modern Notification Bell -->
        <div class="notification-container">
            <div class="notification-bell" onclick="toggleNotifications()">
                <i class="fas fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="notification-badge"><?= $unreadCount ?></span>
                <?php endif; ?>
            </div>
            
            <!-- Notification Dropdown -->
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <h6><i class="fas fa-bell me-2"></i>Notifications</h6>
                    <?php if ($unreadCount > 0): ?>
                        <button class="mark-all-read" onclick="markAllAsRead()">
                            Mark all read
                        </button>
                    <?php endif; ?>
                </div>
                
                <div class="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div class="notification-item">
                            <div class="notification-content">
                                <div class="notification-title">No notifications</div>
                                <div class="notification-message">You're all caught up!</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item <?= !$notification['read'] ? 'unread' : '' ?>" 
                                 data-url="<?= htmlspecialchars($notification['url']) ?>"
                                 onclick="openNotification(<?= $notification['id'] ?>)">
                                <div class="notification-content">
                                    <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                                    <div class="notification-message"><?= htmlspecialchars($notification['message']) ?></div>
                                    <div class="notification-time"><?= htmlspecialchars($notification['time']) ?></div>
                                </div>
                                <div class="notification-type-icon <?= $notification['type'] ?>">
                                    <?php
                                    $icons = [
                                        'info' => 'fas fa-info',
                                        'warning' => 'fas fa-exclamation-triangle',
                                        'success' => 'fas fa-check',
                                        'danger' => 'fas fa-times'
                                    ];
                                    ?>
                                    <i class="<?= $icons[$notification['type']] ?>"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Footer removed per requirement: no View All link -->
            </div>
        </div>
        
        <div class="navbar-user" onclick="toggleUserDropdown()">
            <div class="user-avatar">
                <?= substr($firstName, 0, 1) . substr($lastName, 0, 1) ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($fullName) ?></div>
                <div class="user-role"><?= htmlspecialchars($role) ?></div>
            </div>
            <div class="user-dropdown" id="userDropdown">
                <a href="/logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Update time every second
function updateTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', {
        hour12: true,
        hour: '2-digit',
        minute: '2-digit'
    });
    const dateString = now.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric'
    });
    
    const timeElement = document.getElementById('currentTime');
    if (timeElement) {
        timeElement.textContent = `${dateString} • ${timeString}`;
    }
}

// Update time immediately and then every second
updateTime();
setInterval(updateTime, 1000);

// Toggle notification dropdown
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    const userDropdown = document.getElementById('userDropdown');
    
    // Close user dropdown if open
    userDropdown.classList.remove('show');
    
    dropdown.classList.toggle('show');
}

// Toggle user dropdown
function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    // Close notification dropdown if open
    notificationDropdown.classList.remove('show');
    
    dropdown.classList.toggle('show');
}

function openNotification(notificationId) {
    window.location.href = '/includes/notification_redirect.php?id=' + encodeURIComponent(notificationId);
}

function markAllAsRead() {
    // Make AJAX call to mark all notifications as read
    fetch('/includes/mark_all_notifications_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Visual feedback
            const unreadItems = document.querySelectorAll('.notification-item.unread');
            unreadItems.forEach(item => {
                item.classList.remove('unread');
            });
            
            // Update badge
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                badge.style.display = 'none';
            }
            
            // Hide mark all read button
            const markAllBtn = document.querySelector('.mark-all-read');
            if (markAllBtn) {
                markAllBtn.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Error marking all notifications as read:', error);
    });
}

// Update notification badge count
function updateNotificationBadge() {
    const unreadItems = document.querySelectorAll('.notification-item.unread');
    const badge = document.querySelector('.notification-badge');
    
    if (unreadItems.length === 0) {
        if (badge) {
            badge.style.display = 'none';
        }
    } else {
        if (badge) {
            badge.textContent = unreadItems.length;
            badge.style.display = 'flex';
        }
    }
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    const userSection = document.querySelector('.navbar-user');
    const userDropdown = document.getElementById('userDropdown');
    const notificationContainer = document.querySelector('.notification-container');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    if (!userSection.contains(event.target)) {
        userDropdown.classList.remove('show');
    }
    
    if (!notificationContainer.contains(event.target)) {
        notificationDropdown.classList.remove('show');
    }
});

// Mobile sidebar toggle function
function toggleSidebar() {
    // This function would be implemented based on your sidebar structure
    console.log('Toggle sidebar');
}
</script>
