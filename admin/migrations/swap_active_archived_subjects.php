<?php
/**
 * Migration: Swap Active and Archived Subjects
 * 
 * This script archives all currently active subjects and restores all archived subjects to active status.
 * Run this once to perform the swap, then delete this file.
 * 
 * Access: http://localhost/admin/migrations/swap_active_archived_subjects.php
 */

session_start();

// Basic security check
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die('Access denied. Admin login required.');
}

include '../../includes/db_connection.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Swap Active/Archived Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Poppins', sans-serif;
        }
        .container {
            max-width: 600px;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background: linear-gradient(135deg, #800000, #a00000);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 25px;
            border: none;
        }
        .card-title {
            margin: 0;
            font-weight: 700;
            font-size: 1.5rem;
        }
        .card-body {
            padding: 30px;
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .btn-swap {
            background: linear-gradient(135deg, #800000, #a00000);
            border: none;
            color: white;
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-swap:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(128, 0, 0, 0.4);
            color: white;
        }
        .btn-swap:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border-left: 4px solid #800000;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #800000;
        }
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 5px;
        }
        .result-box {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            display: none;
        }
        .result-box.error {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        .spinner {
            display: none;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title"><i class="fas fa-sync me-2"></i>Swap Active & Archived Subjects</h5>
            </div>
            <div class="card-body">
                <?php
                // Get current stats
                $activeCount = 0;
                $archivedCount = 0;
                
                $statsSql = "SELECT 
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived_count
                    FROM subjects";
                $statsRes = $conn->query($statsSql);
                if ($statsRes) {
                    $stats = $statsRes->fetch_assoc();
                    $activeCount = (int)($stats['active_count'] ?? 0);
                    $archivedCount = (int)($stats['archived_count'] ?? 0);
                }
                ?>
                
                <div class="alert alert-warning mb-3">
                    <strong><i class="fas fa-exclamation-triangle me-2"></i>Warning:</strong>
                    This operation will archive all active subjects and restore all archived subjects to active status. This action cannot be undone!
                </div>
                
                <div class="stats">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $activeCount; ?></div>
                        <div class="stat-label">Active Subjects</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $archivedCount; ?></div>
                        <div class="stat-label">Archived Subjects</div>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <strong>After swap:</strong>
                    <ul class="mb-0 mt-2">
                        <li><?php echo $archivedCount; ?> subjects will become active</li>
                        <li><?php echo $activeCount; ?> subjects will become archived</li>
                    </ul>
                </div>
                
                <form method="POST" id="swapForm">
                    <div class="mb-3">
                        <label class="form-check">
                            <input type="checkbox" name="confirm" id="confirmCheck" class="form-check-input">
                            <span class="form-check-label">I understand this action and want to proceed</span>
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-swap w-100" id="swapBtn" disabled>
                        <span class="spinner" id="spinner">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        </span>
                        <span id="btnText">Perform Swap</span>
                    </button>
                </form>
                
                <div class="result-box" id="resultBox"></div>
            </div>
        </div>
    </div>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.getElementById('confirmCheck').addEventListener('change', function() {
            document.getElementById('swapBtn').disabled = !this.checked;
        });

        document.getElementById('swapForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const swapBtn = document.getElementById('swapBtn');
            const spinner = document.getElementById('spinner');
            const btnText = document.getElementById('btnText');
            const resultBox = document.getElementById('resultBox');
            
            swapBtn.disabled = true;
            spinner.style.display = 'inline-block';
            btnText.textContent = 'Processing...';
            resultBox.style.display = 'none';
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=swap'
            })
            .then(response => response.json())
            .then(data => {
                spinner.style.display = 'none';
                resultBox.style.display = 'block';
                
                if (data.success) {
                    resultBox.classList.remove('error');
                    resultBox.innerHTML = `
                        <strong><i class="fas fa-check-circle me-2"></i>Success!</strong>
                        <p class="mb-0 mt-2">${data.message}</p>
                    `;
                    btnText.textContent = 'Swap Complete';
                    setTimeout(() => {
                        window.location.href = '/admin/subject_management.php';
                    }, 2000);
                } else {
                    resultBox.classList.add('error');
                    resultBox.innerHTML = `
                        <strong><i class="fas fa-exclamation-circle me-2"></i>Error!</strong>
                        <p class="mb-0 mt-2">${data.message}</p>
                    `;
                    btnText.textContent = 'Perform Swap';
                    swapBtn.disabled = false;
                }
            })
            .catch(error => {
                spinner.style.display = 'none';
                resultBox.classList.add('error');
                resultBox.style.display = 'block';
                resultBox.innerHTML = `
                    <strong><i class="fas fa-exclamation-circle me-2"></i>Error!</strong>
                    <p class="mb-0 mt-2">An error occurred: ${error.message}</p>
                `;
                btnText.textContent = 'Perform Swap';
                swapBtn.disabled = false;
            });
        });
    </script>
</body>
</html>

<?php
// Handle the swap action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'swap') {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // Step 1: Archive all active subjects (set status to 'archived')
        $archiveStmt = $conn->prepare("UPDATE subjects SET status = 'archived' WHERE status = 'active'");
        if (!$archiveStmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }
        if (!$archiveStmt->execute()) {
            throw new RuntimeException('Archive failed: ' . $archiveStmt->error);
        }
        $archivedNow = $archiveStmt->affected_rows;
        $archiveStmt->close();
        
        // Step 2: Restore all archived subjects (set status to 'active')
        $restoreStmt = $conn->prepare("UPDATE subjects SET status = 'active' WHERE status = 'archived'");
        if (!$restoreStmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }
        if (!$restoreStmt->execute()) {
            throw new RuntimeException('Restore failed: ' . $restoreStmt->error);
        }
        $restoredNow = $restoreStmt->affected_rows;
        $restoreStmt->close();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Swap completed successfully! Archived $archivedNow subject(s). Restored $restoredNow subject(s) to active."
        ]);
        
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Swap failed: ' . $e->getMessage()
        ]);
    }
    exit();
}
?>
