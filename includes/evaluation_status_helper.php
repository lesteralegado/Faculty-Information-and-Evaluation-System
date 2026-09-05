<?php
// Helper function to get evaluation status from database
function getEvaluationStatus($conn) {
    // Ensure all date comparisons use the intended timezone
    date_default_timezone_set('Asia/Manila');
    // Get evaluation period from evaluation_status table
    $evalStatusRes = $conn->query("SELECT * FROM evaluation_status ORDER BY id DESC LIMIT 1");
    $evalStatus = $evalStatusRes && $evalStatusRes->num_rows > 0 ? $evalStatusRes->fetch_assoc() : null;

    // Handle zero dates and nulls properly
    $evaluation_start_date = '';
    $evaluation_end_date = '';
    
    if ($evalStatus) {
        $evaluation_start_date = ($evalStatus['date_start'] && 
                                  $evalStatus['date_start'] != '0000-00-00 00:00:00' && 
                                  $evalStatus['date_start'] != '0000-00-00') ? $evalStatus['date_start'] : '';
        $evaluation_end_date = ($evalStatus['date_end'] && 
                                $evalStatus['date_end'] != '0000-00-00 00:00:00' && 
                                $evalStatus['date_end'] != '0000-00-00') ? $evalStatus['date_end'] : '';
    }
    $is_evaluation_enabled = $evalStatus ? intval($evalStatus['status']) : 0; // kept for backward compatibility

    // Get school year and semester from currentschoolyearandsemester
    $sy_sem_result = $conn->query("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
    $school_year = '';
    $semester = '';
    if ($sy_sem_result && $sy_sem_result->num_rows > 0) {
        $sy_sem_row = $sy_sem_result->fetch_assoc();
        $school_year = $sy_sem_row['school_year'];
        $semester = ($sy_sem_row['semester'] == 1) ? '1st Semester' : '2nd Semester';
    }

    // Determine phase strictly by date range for consistency across dashboards
    // Check both status field and date range - evaluation is ongoing if status=1 AND within date range
    $phase = 'not set';
    $is_ongoing = false;
    
    if ($evaluation_start_date && $evaluation_end_date) {
        $now = time();
        $start = strtotime($evaluation_start_date);
        $end = strtotime($evaluation_end_date);
        
        if ($now < $start) {
            $phase = 'upcoming';
        } elseif ($now >= $start && $now <= $end) {
            // Only mark as ongoing if status is enabled (status = 1)
            if ($is_evaluation_enabled == 1) {
                $phase = 'ongoing';
                $is_ongoing = true;
            } else {
                $phase = 'not set';
            }
        } elseif ($now > $end) {
            $phase = 'ended';
        }
    }

    return [
        'phase' => $phase,
        'is_ongoing' => $is_ongoing,
        'school_year' => $school_year,
        'semester' => $semester,
        'start_date' => $evaluation_start_date,
        'end_date' => $evaluation_end_date,
        'status_enabled' => $is_evaluation_enabled
    ];
}

// Helper function to check if evaluation is currently ongoing
function isEvaluationOngoing($conn) {
    date_default_timezone_set('Asia/Manila');
    $evalStatusRes = $conn->query("SELECT * FROM evaluation_status ORDER BY id DESC LIMIT 1");
    $evalStatus = $evalStatusRes && $evalStatusRes->num_rows > 0 ? $evalStatusRes->fetch_assoc() : null;
    
    if (!$evalStatus) {
        return false;
    }
    
    $status_enabled = isset($evalStatus['status']) ? intval($evalStatus['status']) : 0;
    if ($status_enabled != 1) {
        return false;
    }
    
    $start_date = ($evalStatus['date_start'] && 
                   $evalStatus['date_start'] != '0000-00-00 00:00:00' && 
                   $evalStatus['date_start'] != '0000-00-00') ? $evalStatus['date_start'] : '';
    $end_date = ($evalStatus['date_end'] && 
                 $evalStatus['date_end'] != '0000-00-00 00:00:00' && 
                 $evalStatus['date_end'] != '0000-00-00') ? $evalStatus['date_end'] : '';
    
    if (empty($start_date) || empty($end_date)) {
        return false;
    }
    
    $now = time();
    $start = strtotime($start_date);
    $end = strtotime($end_date);
    
    return ($now >= $start && $now <= $end);
}

function renderEvaluationStatus($status) {
    // Choose color class based on phase
    $colorClass = 'status-not-set';
    $icon = 'fa-ban';
    if ($status['phase'] === 'ongoing') { $colorClass = 'status-ongoing'; $icon = 'fa-play-circle'; }
    elseif ($status['phase'] === 'upcoming') { $colorClass = 'status-upcoming'; $icon = 'fa-clock'; }
    elseif ($status['phase'] === 'ended') { $colorClass = 'status-finished'; $icon = 'fa-check-circle'; }

    ob_start();
    ?>
    <div class="evaluation-status-card <?= $colorClass ?>">
        <div class="status-content">
            <div class="d-flex align-items-center mb-2">
                <div class="status-icon-wrapper me-3">
                    <i class="fas <?= $icon ?> status-icon"></i>
                </div>
                <h5 class="status-title mb-0">
                    <?= ucfirst($status['phase']) ?>
                </h5>
            </div>
            <div class="academic-info">
                <div><strong>School Year:</strong> <?= htmlspecialchars($status['school_year'] ?: 'Not Set') ?></div>
                <div><strong>Semester:</strong> <?= htmlspecialchars($status['semester'] ?: 'Not Set') ?></div>
                <div><strong>Start:</strong> <?= ($status['start_date'] && $status['start_date'] !== '' && strtotime($status['start_date']) !== false) ? date('M d, Y g:i A', strtotime($status['start_date'])) : 'Not Set' ?></div>
                <div><strong>End:</strong> <?= ($status['end_date'] && $status['end_date'] !== '' && strtotime($status['end_date']) !== false) ? date('M d, Y g:i A', strtotime($status['end_date'])) : 'Not Set' ?></div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
