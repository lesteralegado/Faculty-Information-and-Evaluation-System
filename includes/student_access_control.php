<?php
/**
 * Centralized access control for student pages.
 *
 * Rules:
 * - If account status is inactive: allow login but restrict to Credential Request only.
 * - If not enrolled in current school year & semester: allow ONLY Credential Request page.
 * - If enrolled in current school year & semester: full student access.
 */
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/db_connection.php';

function normalizeSchoolYear(string $sy): string
{
    return str_replace(["\u{2013}", "\u{2014}"], "-", $sy);
}

/**
 * @return array{enrolled: bool, current_school_year: string, current_semester_num: int, user_status: string}
 */
function getStudentAccessContext(mysqli $conn, int $userId): array
{
    // 1) Fetch user status from DB (do not trust session)
    $uStmt = $conn->prepare("SELECT status FROM users WHERE user_id = ? AND role = 'student' LIMIT 1");
    $uStmt->bind_param("i", $userId);
    $uStmt->execute();
    $uRes = $uStmt->get_result();
    $uRow = $uRes ? $uRes->fetch_assoc() : null;
    $uStmt->close();

    $status = $uRow['status'] ?? 'inactive';

    // 2) Current SY/Sem
    $currentSy = '2025-2026';
    $currentSem = 1;
    $csStmt = $conn->prepare("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
    $csStmt->execute();
    $csRes = $csStmt->get_result();
    if ($csRes && $csRes->num_rows > 0) {
        $csRow = $csRes->fetch_assoc();
        $currentSy = (string)($csRow['school_year'] ?? $currentSy);
        $currentSem = (int)($csRow['semester'] ?? $currentSem);
    }
    $csStmt->close();

    $currentSyNorm = normalizeSchoolYear($currentSy);

    // 3) Enrollment check: must have a students row for current SY/Sem
    $enrolled = false;
    $sStmt = $conn->prepare(
        "SELECT student_id
         FROM students
         WHERE user_id = ?
           AND REPLACE(REPLACE(school_year,'–','-'),'—','-') = ?
           AND semester = ?
         LIMIT 1"
    );
    $sStmt->bind_param("isi", $userId, $currentSyNorm, $currentSem);
    $sStmt->execute();
    $sRes = $sStmt->get_result();
    if ($sRes && $sRes->num_rows > 0) {
        $enrolled = true;
    }
    $sStmt->close();

    return [
        'enrolled' => $enrolled,
        'current_school_year' => $currentSy,
        'current_semester_num' => $currentSem,
        'user_status' => $status,
    ];
}

/**
 * Enforce student access.
 *
 * @param 'full'|'credential_only' $requiredAccess
 */
function requireStudentAccess(string $requiredAccess = 'full'): void
{
    if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student' || !isset($_SESSION['user_id'])) {
        header("Location: /login.php?error=2");
        exit();
    }

    /** @var mysqli $conn */
    global $conn;

    $ctx = getStudentAccessContext($conn, (int)$_SESSION['user_id']);

    // Keep session in sync for UI pieces that rely on it (do not trust existing session values)
    $statusNorm = strtolower((string)($ctx['user_status'] ?? 'inactive'));
    $_SESSION['status'] = $statusNorm;
    $_SESSION['student_enrolled_current_term'] = $ctx['enrolled'] ? '1' : '0';

    // Inactive students: restricted to Credential Request only (still allowed to stay logged in)
    if ($statusNorm !== 'active') {
        if ($requiredAccess !== 'credential_only') {
            header("Location: /student/credential_form_request.php?restricted=inactive");
            exit();
        }
        return;
    }

    // Active but not enrolled in current SY/Sem: restricted to Credential Request only
    if ($requiredAccess === 'full' && !$ctx['enrolled']) {
        header("Location: /student/credential_form_request.php?restricted=1");
        exit();
    }
}

