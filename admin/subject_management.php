<?php
session_start();

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");  
    exit();
}

// Include database connection and helper
include '../includes/db_connection.php';
include '../includes/evaluation_status_helper.php';
require_once __DIR__ . '/../includes/modern_alert_system.php';

function normalizeSchoolYear(string $sy): string {
    return str_replace(["–", "—"], "-", trim($sy));
}

function subjectManagementEnsureSpreadsheet(): bool {
    if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        return true;
    }
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }
    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        $manualPaths = [
            __DIR__ . '/../includes/phpspreadsheet/src/phpspreadsheet/IOFactory.php',
            __DIR__ . '/../includes/phpspreadsheet/src/PhpSpreadsheet/IOFactory.php',
        ];
        foreach ($manualPaths as $path) {
            if (file_exists($path)) {
                if (file_exists(__DIR__ . '/../includes/phpspreadsheet_autoload.php')) {
                    require_once __DIR__ . '/../includes/phpspreadsheet_autoload.php';
                }
                require_once $path;
                break;
            }
        }
    }
    return class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
}

/** Convert column index (1-based) to column letter (A, B, Z, AA, etc.) */
function indexToColumnLetter(int $index): string {
    $letter = '';
    while ($index > 0) {
        $index--;
        $letter = chr(65 + ($index % 26)) . $letter;
        $index = intdiv($index, 26);
    }
    return $letter;
}

/** @return array{canonical: string, map: array<string,string>} canonical full names + abbreviation lookup */
function subjectManagementStrandDictionary(): array {
    $canonical = [
        'Accountancy, Business, and Management',
        'Humanities and Social Sciences',
        'Science, Technology, Engineering, Mathematics',
        'Information and Communication Technology',
    ];
    $map = [];
    foreach ($canonical as $full) {
        $lk = strtolower($full);
        $map[$lk] = $full;
    }
    $map[strtolower('ABM')] = $canonical[0];
    $map[strtolower('HUMSS')] = $canonical[1];
    $map[strtolower('STEM')] = $canonical[2];
    $map[strtolower('ICT')] = $canonical[3];
    return ['canonical' => $canonical, 'map' => $map];
}

function subjectManagementNormalizeStrandInput(string $raw): ?string {
    $t = strtolower(trim(str_replace(["\xC2\xA0"], ' ', $raw)));
    if ($t === '') {
        return null;
    }
    $dict = subjectManagementStrandDictionary();
    if (isset($dict['map'][$t])) {
        return $dict['map'][$t];
    }
    foreach ($dict['canonical'] as $full) {
        if (strtolower($full) === $t) {
            return $full;
        }
    }
    return null;
}

/** @return array{sem:int,is_empty:bool} */
function subjectManagementParseSemesterCell($value, int $defaultSem): array {
    if ($value === null || $value === '') {
        return ['sem' => ($defaultSem === 2 ? 2 : 1), 'is_empty' => true];
    }
    if (is_numeric($value)) {
        $n = (int)$value;
        return ['sem' => ($n === 2 ? 2 : 1), 'is_empty' => false];
    }
    $s = strtolower(trim((string)$value));
    if ($s === '' || strpos($s, '1') === 0 || strpos($s, 'first') !== false) {
        return ['sem' => 1, 'is_empty' => false];
    }
    if (strpos($s, '2') === 0 || strpos($s, 'second') !== false) {
        return ['sem' => 2, 'is_empty' => false];
    }
    return ['sem' => ($defaultSem === 2 ? 2 : 1), 'is_empty' => false];
}

/** Normalizes a free-text name/search token for comparisons. */
function subjectManagementCanonNameKey(string $in): string {
    $in = str_replace(',', ' ', strtolower(trim($in)));
    return trim(preg_replace('/\s+/', ' ', $in));
}

/** Normalize subject title for storage and duplicate checks. */
function subjectManagementNormalizeSubjectName(string $raw): string {
    $name = trim(preg_replace('/\s+/', ' ', $raw));
    return $name;
}

/**
 * Checks if an active subject already exists in the same strand.
 */
function subjectManagementActiveSubjectExists(
    mysqli $conn,
    string $subjectName,
    string $yearLevel,
    string $strand,
    int $semester,
    string $schoolYear
): bool {
    $sql = "SELECT subject_name
            FROM subjects
            WHERE status = 'active'
              AND strand = ?
            ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $strand);
    $stmt->execute();
    $res = $stmt->get_result();
    $incomingKey = subjectManagementCanonNameKey(subjectManagementNormalizeSubjectName($subjectName));
    $exists = false;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rowName = (string)($row['subject_name'] ?? '');
            if (subjectManagementCanonNameKey(subjectManagementNormalizeSubjectName($rowName)) === $incomingKey) {
                $exists = true;
                break;
            }
        }
    }
    $stmt->close();
    return $exists;
}

/** Stable identity key for active duplicate checks. */
function subjectManagementBuildSubjectIdentityKey(
    string $subjectName,
    string $yearLevel,
    string $strand,
    int $semester,
    string $schoolYear
): string {
    return implode('|', [
        subjectManagementCanonNameKey(subjectManagementNormalizeSubjectName($subjectName)),
        trim($yearLevel),
        trim($strand),
        ($semester === 2 ? '2' : '1'),
        normalizeSchoolYear($schoolYear),
    ]);
}

/**
 * Fetch the current active school year and semester fresh from the database.
 * This ensures we always use the system's latest configuration, preventing mismatches
 * between page load time and form submission time.
 *
 * @return array{school_year: string, semester: int, semester_text: string}
 */
function subjectManagementFetchCurrentContext(mysqli $conn): array {
    $ctx = ['school_year' => '', 'semester' => 1, 'semester_text' => '1st Semester'];
    $stmt = $conn->prepare("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $ctx['school_year'] = (string)($row['school_year'] ?? '');
            $ctx['semester'] = ((int)($row['semester'] ?? 1) === 2) ? 2 : 1;
            $ctx['semester_text'] = ($ctx['semester'] === 2) ? '2nd Semester' : '1st Semester';
        }
        $stmt->close();
    }
    return $ctx;
}

/**
 * Teachers roster for the active school year/semester (same source as Evaluation Management UI).
 *
 * @return list<array{teacher_id:int,first_name:string,last_name:string,email:string,account_number:?string}>
 */
function subjectManagementFetchImportRoster(mysqli $conn, string $schoolYearNorm, int $semester): array {
    $list = [];
    $sql = "SELECT t.teacher_id, u.first_name, u.last_name, u.email, u.account_number
            FROM teachers t
            INNER JOIN users u ON t.user_id = u.user_id
            WHERE u.role = 'teacher'
              AND u.status = 'active'
              AND REPLACE(REPLACE(t.school_year,'–','-'),'—','-') = ?
              AND t.semester = ?
            ORDER BY t.teacher_id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $list;
    }
    $stmt->bind_param('si', $schoolYearNorm, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $list[] = [
            'teacher_id' => (int)$row['teacher_id'],
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name' => (string)($row['last_name'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'account_number' => isset($row['account_number']) ? (string)$row['account_number'] : '',
        ];
    }
    $stmt->close();

    return $list;
}

/**
 * Indexes roster for resolving import tokens (email, employee/account number, name, roster teacher id).
 *
 * @return array{by_email: array<string,int[]>, by_account: array<string,int[]>, by_name: array<string,int[]>, roster_tid: array<int,true>}
 */
function subjectManagementBuildTeacherLookupFromRoster(array $roster): array {
    $byEmail = [];
    $byAccount = [];
    $byName = [];

    foreach ($roster as $r) {
        $tid = (int)$r['teacher_id'];
        $fn = trim((string)$r['first_name']);
        $ln = trim((string)$r['last_name']);

        $em = strtolower(trim((string)$r['email']));
        if ($em !== '') {
            if (!isset($byEmail[$em])) {
                $byEmail[$em] = [];
            }
            $byEmail[$em][] = $tid;
        }

        $acctRaw = trim((string)($r['account_number'] ?? ''));
        if ($acctRaw !== '') {
            $ak = strtolower($acctRaw);
            if (!isset($byAccount[$ak])) {
                $byAccount[$ak] = [];
            }
            $byAccount[$ak][] = $tid;
        }

        $forms = [];
        if ($fn !== '' && $ln !== '') {
            $forms[] = subjectManagementCanonNameKey($fn . ' ' . $ln);
            $forms[] = subjectManagementCanonNameKey($ln . ' ' . $fn);
        }
        if ($fn !== '') {
            $forms[] = subjectManagementCanonNameKey($fn);
        }
        if ($ln !== '') {
            $forms[] = subjectManagementCanonNameKey($ln);
        }

        foreach (array_unique(array_filter($forms)) as $form) {
            if ($form === '') {
                continue;
            }
            if (!isset($byName[$form])) {
                $byName[$form] = [];
            }
            $byName[$form][] = $tid;
        }
    }

    foreach (array_keys($byEmail) as $k) {
        $byEmail[$k] = array_values(array_unique($byEmail[$k]));
    }
    foreach (array_keys($byAccount) as $k) {
        $byAccount[$k] = array_values(array_unique($byAccount[$k]));
    }
    foreach (array_keys($byName) as $k) {
        $byName[$k] = array_values(array_unique($byName[$k]));
    }

    $rosterTid = [];
    foreach ($roster as $r) {
        $rosterTid[(int)$r['teacher_id']] = true;
    }

    return [
        'by_email' => $byEmail,
        'by_account' => $byAccount,
        'by_name' => $byName,
        'roster_tid' => $rosterTid,
    ];
}

/**
 * Map one cell token to a roster teacher_id. Priority: email → account number → full/partial name → numeric teacher row id on roster.
 *
 * @param array<string,mixed> $lookup from subjectManagementBuildTeacherLookupFromRoster
 * @return array{id:?int,err:?string}
 */
function subjectManagementResolveImportTeacher(string $token, array $lookup): array {
    $token = trim($token);
    if ($token === '') {
        return ['id' => null, 'err' => 'empty'];
    }

    $byEmail = $lookup['by_email'] ?? [];
    $byAccount = $lookup['by_account'] ?? [];
    $byName = $lookup['by_name'] ?? [];
    $rosterTid = $lookup['roster_tid'] ?? [];

    $normEmail = strtolower($token);

    if (strpos($token, '@') !== false || filter_var($token, FILTER_VALIDATE_EMAIL)) {
        $hits = $byEmail[$normEmail] ?? [];
        if (count($hits) === 1) {
            return ['id' => (int)$hits[0], 'err' => null];
        }
        if (count($hits) > 1) {
            return ['id' => null, 'err' => "multiple teachers match email `{$token}`"];
        }

        return ['id' => null, 'err' => "no roster teacher matches email `{$token}`"];
    }

    $acctKey = strtolower(trim($token));
    if ($acctKey !== '') {
        $hits = $byAccount[$acctKey] ?? [];
        if (count($hits) === 1) {
            return ['id' => (int)$hits[0], 'err' => null];
        }
        if (count($hits) > 1) {
            return ['id' => null, 'err' => "multiple teachers match employee/account number `{$token}`"];
        }
    }

    $nk = subjectManagementCanonNameKey($token);
    if ($nk !== '') {
        $hits = $byName[$nk] ?? [];
        if (count($hits) === 1) {
            return ['id' => (int)$hits[0], 'err' => null];
        }
        if (count($hits) > 1) {
            return ['id' => null, 'err' => "multiple teachers match name `{$token}`"];
        }
    }

    if (ctype_digit($token)) {
        $tid = (int)$token;
        if ($tid > 0 && isset($rosterTid[$tid])) {
            return ['id' => $tid, 'err' => null];
        }

        return ['id' => null, 'err' => "employee number or ID `{$token}` does not match an active roster teacher"];
    }

    return ['id' => null, 'err' => "could not resolve teacher `{$token}` for this roster"];
}

function resolveTeacherUserId(mysqli $conn, $teacherReference) {
    if ($teacherReference === null || $teacherReference === '') {
        return null;
    }

    $teacherReference = (int)$teacherReference;
    if ($teacherReference <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT resolved_user_id
         FROM (
             SELECT t.user_id AS resolved_user_id, 1 AS priority
             FROM teachers t
             WHERE t.user_id = ?
             UNION ALL
             SELECT t.user_id AS resolved_user_id, 2 AS priority
             FROM teachers t
             WHERE t.teacher_id = ?
             UNION ALL
             SELECT u.user_id AS resolved_user_id, 3 AS priority
             FROM users u
             WHERE u.user_id = ? AND u.role = 'teacher'
         ) teacher_matches
         ORDER BY priority
         LIMIT 1"
    );
    $stmt->bind_param("iii", $teacherReference, $teacherReference, $teacherReference);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result ? (int)$result['resolved_user_id'] : null;
}

function resolveSubjectName(mysqli $conn, $subjectInput): string {
    $subjectInput = trim((string)$subjectInput);
    if ($subjectInput === '') {
        return '';
    }

    // Dynamic dropdown posts subject_id as value; resolve it to actual subject name.
    if (ctype_digit($subjectInput)) {
        $subjectId = (int)$subjectInput;
        if ($subjectId > 0) {
            $stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE subject_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $subjectId);
                $stmt->execute();
                $resolved = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($resolved && !empty($resolved['subject_name'])) {
                    return trim((string)$resolved['subject_name']);
                }
            }
        }
    }

    return $subjectInput;
}

/**
 * Fetch all unique subjects from past curricula for reuse selection.
 * Groups by strand and year level for easier browsing.
 * 
 * @return array<string, array<string, array{subject_id:int, subject_name:string, year_level:string, strand:string, school_year:string}>>
 */
function subjectManagementFetchPastSubjectsForReuse(mysqli $conn): array {
    $byStrandYear = [];
    
    $sql = "SELECT DISTINCT
                s.subject_id,
                s.subject_name,
                s.year_level,
                s.strand,
                s.school_year,
                CASE
                    WHEN s.subject_name REGEXP '^[0-9]+$' THEN COALESCE(s_ref.subject_name, s.subject_name)
                    ELSE s.subject_name
                END AS display_subject_name
            FROM subjects s
            LEFT JOIN subjects s_ref
                ON s.subject_name REGEXP '^[0-9]+$'
               AND CAST(s.subject_name AS UNSIGNED) = s_ref.subject_id
            WHERE s.status = 'archived'
              AND s.school_year IS NOT NULL
              AND TRIM(s.school_year) <> ''
            ORDER BY s.school_year DESC, s.strand ASC, s.year_level ASC, display_subject_name ASC";
    
    $result = $conn->query($sql);
    if (!$result) {
        return $byStrandYear;
    }
    
    while ($row = $result->fetch_assoc()) {
        $strandKey = subjectManagementNormalizeStrandInput((string)($row['strand'] ?? '')) ?? (string)($row['strand'] ?? '');
        $yearKey = (string)($row['year_level'] ?? '');
        
        if ($strandKey === '' || $yearKey === '') {
            continue;
        }
        
        $key = $strandKey . '|' . $yearKey;
        if (!isset($byStrandYear[$key])) {
            $byStrandYear[$key] = [
                'strand' => $strandKey,
                'year_level' => $yearKey,
                'subjects' => []
            ];
        }
        
        $displayName = subjectManagementNormalizeSubjectName((string)($row['display_subject_name'] ?? ''));
        if ($displayName === '') {
            $displayName = subjectManagementNormalizeSubjectName((string)($row['subject_name'] ?? ''));
        }
        
        if ($displayName === '') {
            continue;
        }
        
        $byStrandYear[$key]['subjects'][] = [
            'subject_id' => (int)$row['subject_id'],
            'subject_name' => $displayName,
            'year_level' => $yearKey,
            'strand' => $strandKey,
            'school_year' => (string)($row['school_year'] ?? '')
        ];
    }
    
    // Remove duplicates within each group (keep latest by school_year)
    foreach ($byStrandYear as &$group) {
        $seen = [];
        $unique = [];
        foreach ($group['subjects'] as $subj) {
            $key = strtolower($subj['subject_name']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $subj;
            }
        }
        $group['subjects'] = $unique;
    }
    unset($group);
    
    return $byStrandYear;
}

/**
 * Build a curriculum from manual inputs and reused subjects.
 * Validates all data before creating.
 * 
 * When allStrands is true, each subject is expanded to all 4 strands.
 * In new 6-step design, year_level is extracted per-subject (not global).
 * 
 * @return array{success:bool, subjects:array, errors:list<string>}
 */
function subjectManagementValidateAndBuildCurriculum(
    mysqli $conn,
    string $schoolYear,
    int $semester,
    ?string $yearLevel,  // Now nullable since year_level is per-subject in 6-step design
    array $manualSubjects,
    array $reuseSubjectIds,
    bool $allStrands = false
): array {
    $result = [
        'success' => false,
        'subjects' => [],
        'errors' => []
    ];
    
    $sy_norm = normalizeSchoolYear($schoolYear);
    if ($sy_norm === '') {
        $result['errors'][] = 'Invalid school year format.';
        return $result;
    }
    
    if (!in_array((int)$semester, [1, 2], true)) {
        $result['errors'][] = 'Semester must be 1 or 2.';
        return $result;
    }
    
    // In the new 6-step design, year_level is per-subject, so the global parameter may be null
    // Each subject carries its own year_level and strand from the form data
    
    $all_subjects = [];
    $seen_keys = [];
    
    // Process manual subjects
    if (is_array($manualSubjects)) {
        foreach ($manualSubjects as $idx => $manual) {
            if (!is_array($manual)) {
                continue;
            }
            
            $name = trim((string)($manual['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            
            if (strlen($name) < 3 || strlen($name) > 120) {
                $result['errors'][] = "Manual subject #{$idx}: Name must be 3-120 characters.";
                continue;
            }
            
            $name = subjectManagementNormalizeSubjectName($name);
            
            // Extract per-subject strand and year_level (NEW in 6-step design)
            $subjectStrand = trim((string)($manual['strand'] ?? ''));
            $subjectYearLevel = trim((string)($manual['year_level'] ?? ''));
            
            // Validate year level for this subject
            if (!in_array($subjectYearLevel, ['11', '12'], true)) {
                $result['errors'][] = "Subject '{$name}' has invalid year level: {$subjectYearLevel}";
                continue;
            }
            
            // Validate strand for this subject
            if ($subjectStrand === '') {
                $result['errors'][] = "Subject '{$name}' missing strand information.";
                continue;
            }
            
            // Normalize the strand
            $normalizedStrand = subjectManagementNormalizeStrandInput($subjectStrand) ?? $subjectStrand;
            
            // Check for duplicates within this curriculum
            $dedupeKey = subjectManagementCanonNameKey($name) . '|' . $subjectYearLevel . '|' . $normalizedStrand . '|' . ((int)$semester);
            if (isset($seen_keys[$dedupeKey])) {
                $result['errors'][] = "Subject '{$name}' is duplicated for Grade {$subjectYearLevel} in {$normalizedStrand}.";
                continue;
            }
            $seen_keys[$dedupeKey] = true;
            
            $all_subjects[] = [
                'type' => 'manual',
                'subject_name' => $name,
                'year_level' => $subjectYearLevel,
                'strand' => $normalizedStrand,
                'semester' => (int)$semester,
                'display_name' => $name
            ];
        }
    }
    
    // Process reused subjects
    if (is_array($reuseSubjectIds)) {
        $reuseIds = array_values(array_unique(array_filter(array_map('intval', $reuseSubjectIds), fn($v) => $v > 0)));
        
        if (count($reuseIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($reuseIds), '?'));
            $types = str_repeat('i', count($reuseIds));
            
            $sql = "SELECT s.subject_id,
                           s.subject_name,
                           s.year_level,
                           s.strand,
                           CASE
                               WHEN s.subject_name REGEXP '^[0-9]+$' THEN COALESCE(s_ref.subject_name, s.subject_name)
                               ELSE s.subject_name
                           END AS display_subject_name
                    FROM subjects s
                    LEFT JOIN subjects s_ref
                        ON s.subject_name REGEXP '^[0-9]+$'
                       AND CAST(s.subject_name AS UNSIGNED) = s_ref.subject_id
                    WHERE s.subject_id IN ({$placeholders})
                      AND s.status = 'archived'";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $result['errors'][] = 'Error fetching reused subjects.';
                return $result;
            }
            
            $stmt->bind_param($types, ...$reuseIds);
            $stmt->execute();
            $res = $stmt->get_result();
            
            while ($row = $res->fetch_assoc()) {
                $reuseName = subjectManagementNormalizeSubjectName((string)($row['display_subject_name'] ?? ''));
                if ($reuseName === '') {
                    $reuseName = subjectManagementNormalizeSubjectName((string)($row['subject_name'] ?? ''));
                }
                
                if ($reuseName === '') {
                    continue;
                }
                
                // Use the original strand and year_level from the archived subject
                $reuseStrand = (string)($row['strand'] ?? '');
                $reuseYearLevel = (string)($row['year_level'] ?? '');
                
                // Normalize the strand
                $normalizedStrand = subjectManagementNormalizeStrandInput($reuseStrand) ?? $reuseStrand;
                
                // Validate that the archived subject has valid data
                if (!in_array($reuseYearLevel, ['11', '12'], true)) {
                    continue; // Skip archived subjects with invalid year levels
                }
                
                if ($normalizedStrand === '') {
                    continue; // Skip archived subjects with no strand
                }
                
                // Check for duplicates within this curriculum
                $dedupeKey = subjectManagementCanonNameKey($reuseName) . '|' . $reuseYearLevel . '|' . $normalizedStrand . '|' . ((int)$semester);
                if (isset($seen_keys[$dedupeKey])) {
                    $result['errors'][] = "Reused subject '{$reuseName}' conflicts with other curriculum subjects for Grade {$reuseYearLevel}.";
                    continue;
                }
                $seen_keys[$dedupeKey] = true;
                
                // Add the reused subject with its original strand/year_level
                $all_subjects[] = [
                    'type' => 'reuse',
                    'source_subject_id' => (int)$row['subject_id'],
                    'subject_name' => $reuseName,
                    'year_level' => $reuseYearLevel,
                    'strand' => $normalizedStrand,
                    'semester' => (int)$semester,
                    'display_name' => $reuseName
                ];
            }
            
            $stmt->close();
        }
    }
    
    if (empty($all_subjects)) {
        $result['errors'][] = 'Curriculum must include at least one subject.';
        return $result;
    }
    
    // ======= CROSS-GRADE DUPLICATE VALIDATION =======
    // Check if any subject names already exist in active records for the same strand
    // This prevents duplicate subjects across Grade 11 and Grade 12 within the same strand
    $activeSubjectsByStrand = [];
    $activeStmt = $conn->prepare(
        "SELECT DISTINCT 
            s.strand,
            LOWER(CONCAT(s.subject_name REGEXP '^[0-9]+$', 
                CASE WHEN s.subject_name REGEXP '^[0-9]+$' THEN COALESCE(s_ref.subject_name, s.subject_name) 
                     ELSE s.subject_name END)) AS canonical_name
         FROM subjects s
         LEFT JOIN subjects s_ref
             ON s.subject_name REGEXP '^[0-9]+$'
            AND CAST(s.subject_name AS UNSIGNED) = s_ref.subject_id
         WHERE s.status = 'active'"
    );
    
    if ($activeStmt) {
        $activeStmt->execute();
        $activeRes = $activeStmt->get_result();
        while ($row = $activeRes->fetch_assoc()) {
            $strand = (string)($row['strand'] ?? '');
            $canonName = subjectManagementCanonNameKey((string)($row['canonical_name'] ?? ''));
            if ($strand === '' || $canonName === '') continue;
            
            $strandKey = strtolower(trim($strand));
            if (!isset($activeSubjectsByStrand[$strandKey])) {
                $activeSubjectsByStrand[$strandKey] = [];
            }
            $activeSubjectsByStrand[$strandKey][$canonName] = true;
        }
        $activeStmt->close();
    }
    
    // Check if any new subjects duplicate existing active subjects
    foreach ($all_subjects as $subj) {
        $subjectName = (string)($subj['subject_name'] ?? '');
        $strand = (string)($subj['strand'] ?? '');
        
        if ($subjectName === '' || $strand === '') continue;
        
        $canonName = subjectManagementCanonNameKey($subjectName);
        $strandKey = strtolower(trim($strand));
        
        if (isset($activeSubjectsByStrand[$strandKey][$canonName])) {
            $result['errors'][] = "Subject name '{$subjectName}' already exists in {$strand} strand. Duplicate subjects are not allowed across Grade 11 and Grade 12.";
        }
    }
    
    // If any duplicates found, return early with errors
    if (!empty($result['errors'])) {
        return $result;
    }
    
    // ======= END CROSS-GRADE DUPLICATE VALIDATION =======
    
    // Original check for curriculum-level duplicates
    if (!empty($result['errors'])) {
        return $result;
    }
    
    $result['success'] = true;
    $result['subjects'] = $all_subjects;
    return $result;
}

/**
 * Create a new curriculum from validated subject list.
 * Archives existing active subjects and inserts new ones.
 * 
 * @return array{success:bool, message:string, created_count:int}
 */
function subjectManagementSaveNewCurriculum(
    mysqli $conn,
    string $schoolYear,
    int $semester,
    array $validatedSubjects,
    ?string $curriculumLabel = null
): array {
    $result = [
        'success' => false,
        'message' => '',
        'created_count' => 0
    ];
    
    $sy_norm = normalizeSchoolYear($schoolYear);
    if ($sy_norm === '' || empty($validatedSubjects)) {
        $result['message'] = 'Invalid curriculum data.';
        return $result;
    }
    
    $subject_has_archive_meta = false;
    $chk_aa = $conn->query("SHOW COLUMNS FROM subjects LIKE 'archived_at'");
    $chk_ab = $conn->query("SHOW COLUMNS FROM subjects LIKE 'archive_batch_id'");
    if ($chk_aa && $chk_aa->num_rows > 0 && $chk_ab && $chk_ab->num_rows > 0) {
        $subject_has_archive_meta = true;
    }
    
    $prior_active_count = 0;
    $cntRes = $conn->query("SELECT COUNT(*) AS c FROM subjects WHERE status = 'active'");
    if ($cntRes) {
        $prior_active_count = (int)($cntRes->fetch_assoc()['c'] ?? 0);
        $cntRes->close();
    }
    
    $archive_batch_id = 'man-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    
    $conn->begin_transaction();
    try {
        // Archive existing active subjects
        if ($subject_has_archive_meta) {
            $archStmt = $conn->prepare("UPDATE subjects SET status = 'archived', archived_at = NOW(), archive_batch_id = ? WHERE status = 'active'");
            if (!$archStmt) {
                throw new RuntimeException($conn->error);
            }
            $archStmt->bind_param('s', $archive_batch_id);
            if (!$archStmt->execute()) {
                throw new RuntimeException($archStmt->error);
            }
            $archStmt->close();
        } elseif (!$conn->query("UPDATE subjects SET status = 'archived' WHERE status = 'active'")) {
            throw new RuntimeException($conn->error);
        }
        
        // Insert new subjects
        $insSubject = $conn->prepare(
            "INSERT INTO subjects (subject_name, year_level, strand, teacher_id, status, semester, school_year) VALUES (?,?,?,NULL,'active',?,?)"
        );
        if (!$insSubject) {
            throw new RuntimeException($conn->error);
        }
        
        $created_count = 0;
        foreach ($validatedSubjects as $subj) {
            $subName = (string)($subj['subject_name'] ?? '');
            $yl = (string)($subj['year_level'] ?? '');
            $st = (string)($subj['strand'] ?? '');
            $sem = (int)($subj['semester'] ?? 1);
            
            if ($subName === '' || $yl === '' || $st === '') {
                continue;
            }
            
            $insSubject->bind_param('sssis', $subName, $yl, $st, $sem, $sy_norm);
            if (!$insSubject->execute()) {
                throw new RuntimeException($insSubject->error);
            }
            $created_count++;
        }
        
        $insSubject->close();
        $conn->commit();
        
        // Log the curriculum creation
        $imp_uname = isset($_SESSION['username']) ? (string)$_SESSION['username'] : '';
        $imp_uid_final = 0;
        if ($imp_uname !== '') {
            $uq = $conn->prepare('SELECT user_id FROM users WHERE account_number = ? LIMIT 1');
            if ($uq) {
                $uq->bind_param('s', $imp_uname);
                $uq->execute();
                $ur = $uq->get_result()->fetch_assoc();
                $uq->close();
                if ($ur && !empty($ur['user_id'])) {
                    $imp_uid_final = (int)$ur['user_id'];
                }
            }
        }
        
        $logIns = $conn->prepare(
            'INSERT INTO curriculum_import_log (school_year, semester, subjects_imported, subjects_archived, archive_batch_id, file_name, imported_by_username, imported_by_user_id) VALUES (?,?,?,?,?,?,?,?)'
        );
        if ($logIns) {
            $methodLabel = 'Manual Curriculum: ' . ($curriculumLabel ?? 'Grade ' . $validatedSubjects[0]['year_level'] . ' - ' . $validatedSubjects[0]['strand']);
            $logIns->bind_param(
                'siiisssi',
                $sy_norm,
                $semester,
                $created_count,
                $prior_active_count,
                $archive_batch_id,
                $methodLabel,
                $imp_uname,
                $imp_uid_final
            );
            $logIns->execute();
            $logIns->close();
        }
        
        $result['success'] = true;
        $result['message'] = "Created curriculum with {$created_count} subject(s). Previous active subjects archived.";
        $result['created_count'] = $created_count;
    } catch (Throwable $e) {
        $conn->rollback();
        $result['message'] = 'Curriculum creation failed: ' . $e->getMessage();
    }
    
    return $result;
}

// Check evaluation status using helper function
$evalStatus = getEvaluationStatus($conn);
$is_evaluation_ongoing = $evalStatus['is_ongoing'];
$evaluation_start_date = $evalStatus['start_date'];
$evaluation_end_date = $evalStatus['end_date'];
$evaluation_phase = $evalStatus['phase'];

$check_teacher_column = $conn->query("SHOW COLUMNS FROM subjects LIKE 'teacher_id'");
if ($check_teacher_column->num_rows == 0) {
    $conn->query("ALTER TABLE subjects ADD COLUMN teacher_id INT DEFAULT NULL");
}

$check_status_column = $conn->query("SHOW COLUMNS FROM subjects LIKE 'status'");
if ($check_status_column->num_rows == 0) {
    $conn->query("ALTER TABLE subjects ADD COLUMN status ENUM('active', 'archived') DEFAULT 'active'");
}

$check_subject_semester = $conn->query("SHOW COLUMNS FROM subjects LIKE 'semester'");
if ($check_subject_semester->num_rows == 0) {
    $conn->query("ALTER TABLE subjects ADD COLUMN semester TINYINT(1) NOT NULL DEFAULT 1");
}

// Create assignment table if missing (preferred multi-teacher model, per SY/Sem)
$conn->query(
    "CREATE TABLE IF NOT EXISTS subject_teacher_assignments (
        assignment_id INT(11) NOT NULL AUTO_INCREMENT,
        subject_id INT(11) NOT NULL,
        teacher_id INT(11) NOT NULL,
        school_year VARCHAR(9) NOT NULL,
        semester TINYINT(1) NOT NULL,
        role ENUM('primary','assistant') NOT NULL DEFAULT 'primary',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (assignment_id),
        UNIQUE KEY uniq_subject_teacher_term (subject_id, teacher_id, school_year, semester),
        KEY idx_subject_term (subject_id, school_year, semester),
        KEY idx_teacher_term (teacher_id, school_year, semester)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS curriculum_import_log (
        log_id INT NOT NULL AUTO_INCREMENT,
        school_year VARCHAR(30) NOT NULL,
        semester TINYINT(1) NOT NULL,
        subjects_imported INT NOT NULL DEFAULT 0,
        subjects_archived INT NOT NULL DEFAULT 0,
        archive_batch_id VARCHAR(36) NOT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        imported_by_username VARCHAR(150) DEFAULT NULL,
        imported_by_user_id INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (log_id),
        KEY idx_curriculum_log_sy_sem (school_year, semester),
        KEY idx_curriculum_log_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$chk_archived_at = $conn->query("SHOW COLUMNS FROM subjects LIKE 'archived_at'");
if ($chk_archived_at && $chk_archived_at->num_rows === 0) {
    $conn->query("ALTER TABLE subjects ADD COLUMN archived_at DATETIME NULL DEFAULT NULL");
}
$chk_archive_batch = $conn->query("SHOW COLUMNS FROM subjects LIKE 'archive_batch_id'");
if ($chk_archive_batch && $chk_archive_batch->num_rows === 0) {
    $conn->query("ALTER TABLE subjects ADD COLUMN archive_batch_id VARCHAR(36) NULL DEFAULT NULL");
}

$subject_has_archive_meta = false;
$chk_aa = $conn->query("SHOW COLUMNS FROM subjects LIKE 'archived_at'");
$chk_ab = $conn->query("SHOW COLUMNS FROM subjects LIKE 'archive_batch_id'");
if ($chk_aa && $chk_aa->num_rows > 0 && $chk_ab && $chk_ab->num_rows > 0) {
    $subject_has_archive_meta = true;
}

// Export archived subjects to Excel (POST) — similar pattern to Evaluation Management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export_archived_subjects') {
    try {
        if (!subjectManagementEnsureSpreadsheet()) {
            throw new Exception('Excel export requires PhpSpreadsheet. Install Composer dependencies or includes/phpspreadsheet.');
        }

        // Get filter values from POST
        $exportSy = isset($_POST['export_school_year']) ? normalizeSchoolYear(trim((string)$_POST['export_school_year'])) : '';
        $subjectSemRaw = isset($_POST['export_semester']) ? trim((string)$_POST['export_semester']) : '';
        $subjectRowSem = $subjectSemRaw === '' ? null : ((((int)$subjectSemRaw === 2) ? 2 : 1));
        $yearFilter = isset($_POST['export_year_level']) ? trim((string)$_POST['export_year_level']) : '';
        $strandFilterRaw = isset($_POST['export_strand']) ? trim((string)$_POST['export_strand']) : '';
        $strandFilter = $strandFilterRaw === '' ? '' : (subjectManagementNormalizeStrandInput($strandFilterRaw) ?? $strandFilterRaw);
        $searchQ = isset($_POST['export_search']) ? trim((string)$_POST['export_search']) : '';

        if ($exportSy === '') {
            $ctxPeek = $conn->prepare('SELECT school_year FROM currentschoolyearandsemester LIMIT 1');
            if ($ctxPeek) {
                $ctxPeek->execute();
                $r = $ctxPeek->get_result()->fetch_assoc();
                $ctxPeek->close();
                if ($r && !empty($r['school_year'])) {
                    $exportSy = normalizeSchoolYear((string)$r['school_year']);
                }
            }
        }

        $sql = 'SELECT DISTINCT s.subject_id, s.subject_name, s.year_level, s.strand, s.semester, s.teacher_id, s.status,
                       CASE WHEN s.subject_name REGEXP \'"^[0-9]+$\"\' THEN COALESCE(s_ref.subject_name, s.subject_name) ELSE s.subject_name END AS display_subject_name
                FROM subjects s
                LEFT JOIN subjects s_ref ON s.subject_name REGEXP \'"^[0-9]+$\"\' AND CAST(s.subject_name AS UNSIGNED) = s_ref.subject_id
                LEFT JOIN subject_teacher_assignments sta ON sta.subject_id = s.subject_id
                  AND REPLACE(REPLACE(sta.school_year,\'–\',\'-\'),\'—\',\'-\') = ?
                WHERE s.status = \'archived\'
                  AND REPLACE(REPLACE(s.school_year,\'–\',\'-\'),\'—\',\'-\') = ?';
        $bindTypes = 'ss';
        $bindVals = [$exportSy, $exportSy];

        if ($yearFilter !== '') {
            $sql .= ' AND s.year_level = ?';
            $bindTypes .= 's';
            $bindVals[] = $yearFilter;
        }
        if ($strandFilter !== '') {
            $sql .= ' AND s.strand = ?';
            $bindTypes .= 's';
            $bindVals[] = $strandFilter;
        }
        if ($subjectRowSem !== null) {
            $sql .= ' AND s.semester = ?';
            $bindTypes .= 'i';
            $bindVals[] = $subjectRowSem;
        }

        $sql .= ' ORDER BY s.year_level ASC, s.strand ASC, s.subject_name ASC';

        $stmt = $conn->prepare($sql);
        $rows_out = [];
        if (!$stmt) {
            throw new Exception('Error preparing export query: ' . $conn->error);
        }
        
        $stmt->bind_param($bindTypes, ...$bindVals);
        if (!$stmt->execute()) {
            throw new Exception('Error executing export query: ' . $stmt->error);
        }
        
        $res = $stmt->get_result();
        if (!$res) {
            throw new Exception('Error fetching export data: ' . $stmt->error);
        }
        
        while ($srow = $res->fetch_assoc()) {
            $displayName = trim((string)($srow['subject_name'] ?? ''));
            if (preg_match('/^\d+$/', $displayName)) {
                $rid = (int)$displayName;
                $rs = $conn->prepare('SELECT subject_name FROM subjects WHERE subject_id = ? LIMIT 1');
                if ($rs) {
                    $rs->bind_param('i', $rid);
                    $rs->execute();
                    $rr = $rs->get_result()->fetch_assoc();
                    $rs->close();
                    if ($rr && trim((string)$rr['subject_name']) !== '') {
                        $displayName = trim((string)$rr['subject_name']);
                    }
                }
            }
            if ($searchQ !== '' && stripos($displayName, $searchQ) === false) {
                continue;
            }

            $tq = 'SELECT sta.role, u.first_name, u.last_name
                   FROM subject_teacher_assignments sta
                   INNER JOIN teachers t ON sta.teacher_id = t.teacher_id
                   INNER JOIN users u ON t.user_id = u.user_id
                   WHERE sta.subject_id = ?
                     AND REPLACE(REPLACE(sta.school_year,\'–\',\'-\'),\'—\',\'-\') = ?
                   ORDER BY CASE WHEN sta.role = \'primary\' THEN 0 ELSE 1 END, u.first_name ASC';

            $tstmt = $conn->prepare($tq);
            $primNames = [];
            $asstNames = [];
            if ($tstmt) {
                $tstmt->bind_param('is', $srow['subject_id'], $exportSy);
                $tstmt->execute();
                $trs = $tstmt->get_result();
                while ($tr = $trs->fetch_assoc()) {
                    $nm = trim($tr['first_name'] . ' ' . $tr['last_name']);
                    if (($tr['role'] ?? '') === 'primary') {
                        $primNames[] = $nm;
                    } else {
                        $asstNames[] = $nm;
                    }
                }
                $tstmt->close();
            }

            if (empty($primNames)) {
                $legacy_tid = isset($srow['teacher_id']) ? (int)$srow['teacher_id'] : 0;
                if ($legacy_tid > 0) {
                    $fb = $conn->prepare(
                        'SELECT u.first_name, u.last_name FROM teachers t INNER JOIN users u ON t.user_id = u.user_id WHERE t.teacher_id = ?'
                    );
                    if ($fb) {
                        $fb->bind_param('i', $legacy_tid);
                        $fb->execute();
                        $lr = $fb->get_result()->fetch_assoc();
                        $fb->close();
                        if ($lr) {
                            $primNames[] = trim($lr['first_name'] . ' ' . $lr['last_name']);
                        }
                    }
                }
            }

            $rows_out[] = [
                'subject_id' => (int)$srow['subject_id'],
                'stored_subject_name' => (string)$srow['subject_name'],
                'display_subject_name' => $displayName,
                'year_level' => (string)$srow['year_level'],
                'strand' => (string)$srow['strand'],
                'subject_row_semester' => isset($srow['semester']) ? (string)$srow['semester'] : '',
                'school_year' => $exportSy,
                'primary_teachers' => implode('; ', $primNames),
                'assistant_teachers' => implode('; ', $asstNames),
            ];
        }
        $stmt->close();

        // Create spreadsheet with clean, focused structure
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Archived Subjects');

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(30);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(30);
        $sheet->getColumnDimension('G')->setWidth(30);

        // Add headers - clean structure matching Evaluation Management
        $headers = [
            'Subject ID',
            'Subject Name',
            'Year Level',
            'Strand',
            'Semester',
            'Primary Teacher(s)',
            'Assistant Teacher(s)',
        ];
        foreach ($headers as $c => $h) {
            $col_letter = indexToColumnLetter($c + 1);
            $sheet->setCellValue($col_letter . '1', $h);
        }

        // Style headers like Evaluation Management
        $headerStyle = $sheet->getStyle('A1:G1');
        $headerStyle->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF800000');
        $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $rnum = 2;
        foreach ($rows_out as $row) {
            $sheet->setCellValue('A' . $rnum, $row['subject_id']);
            $sheet->setCellValue('B' . $rnum, $row['display_subject_name']);
            $sheet->setCellValue('C' . $rnum, $row['year_level']);
            $sheet->setCellValue('D' . $rnum, $row['strand']);
            $sheet->setCellValue('E' . $rnum, $row['subject_row_semester']);
            $sheet->setCellValue('F' . $rnum, $row['primary_teachers']);
            $sheet->setCellValue('G' . $rnum, $row['assistant_teachers']);
            
            // Style data rows
            $cellStyle = $sheet->getStyle('A' . $rnum . ':G' . $rnum);
            $cellStyle->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $cellStyle->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
            
            $rnum++;
        }

        // Freeze header row
        $sheet->freezePane('A2');
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Generate Excel file
        $filename = 'archived_subjects_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $exportSy) . '_' . date('Y-m-d_His') . '.xlsx';

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        // Clear output buffering to prevent corruption
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Send file to browser
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Expires: 0');
        
        $writer->save('php://output');
        exit();

    } catch (Exception $e) {
        $_SESSION['error'] = "Export failed: " . $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Current school year & semester (drives which assignments are editable/shown)
$current_ctx = subjectManagementFetchCurrentContext($conn);

// Teachers for current term (teacher_id is the stable key used by analytics/evaluation_responses)
$teachers_sql = "SELECT t.teacher_id, t.user_id, t.strand,
                        u.first_name, u.last_name
                 FROM teachers t
                 INNER JOIN users u ON t.user_id = u.user_id
                 WHERE u.role = 'teacher'
                   AND u.status = 'active'
                   AND REPLACE(REPLACE(t.school_year,'–','-'),'—','-') = ?
                   AND t.semester = ?
                 ORDER BY u.first_name ASC, u.last_name ASC";
$teachers_stmt = $conn->prepare($teachers_sql);
$teachers = [];
if ($teachers_stmt) {
    $sy_norm = normalizeSchoolYear($current_ctx['school_year']);
    $teachers_stmt->bind_param("si", $sy_norm, $current_ctx['semester']);
    $teachers_stmt->execute();
    $teachers = $teachers_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $teachers_stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-check evaluation status on POST to prevent bypassing - use helper function
    $post_is_evaluation_ongoing = isEvaluationOngoing($conn);
    
    // Block actions during ongoing evaluation - MUST be first check before any processing
    if ($post_is_evaluation_ongoing) {
        $blocked_actions = ['create', 'import_subject_set', 'update'];
        if (isset($_POST['action']) && in_array($_POST['action'], $blocked_actions)) {
            $_SESSION['error'] = "This action is not allowed while an evaluation is ongoing. Please wait until the evaluation period ends.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'import_subject_set':
                // Fetch current school year/semester FRESH (not from page load) to ensure consistency
                // This prevents mismatches if the system's active period changed between page load and form submission
                $fresh_ctx = subjectManagementFetchCurrentContext($conn);
                $import_sy = normalizeSchoolYear($fresh_ctx['school_year']);
                $import_sem_term = $fresh_ctx['semester'];

                // Validate that the system's school year hasn't changed since page load
                // If it has, use the fresh value and log the change
                $posted_sy = isset($_POST['import_system_school_year']) ? normalizeSchoolYear(trim((string)$_POST['import_system_school_year'])) : '';
                $posted_sem = isset($_POST['import_system_semester']) ? (int)$_POST['import_system_semester'] : 1;
                
                if ($posted_sy !== '' && $posted_sy !== $import_sy) {
                    // School year changed after page load - log this and use fresh value
                    error_log("Subject import: School year changed from '{$posted_sy}' to '{$import_sy}' between page load and submission. Using fresh system value.");
                }
                if ($posted_sem !== 0 && $posted_sem !== $import_sem_term) {
                    // Semester changed after page load - log this and use fresh value
                    error_log("Subject import: Semester changed from {$posted_sem} to {$import_sem_term} between page load and submission. Using fresh system value.");
                }

                if (!isset($_FILES['import_subject_file']) || ($_FILES['import_subject_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $err = $_FILES['import_subject_file']['error'] ?? UPLOAD_ERR_NO_FILE;
                    $msg = 'Please upload a valid Excel file.';
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                        $msg = 'The uploaded file is too large.';
                    }
                    $_SESSION['error'] = $msg;
                    break;
                }

                $ext = strtolower(pathinfo($_FILES['import_subject_file']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls'], true)) {
                    $_SESSION['error'] = 'Invalid file type. Use .xlsx or .xls.';
                    break;
                }

                if ($import_sy === '') {
                    $_SESSION['error'] = 'No active school year / semester is set. Configure them in Evaluation Management before importing.';
                    break;
                }

                if (!subjectManagementEnsureSpreadsheet()) {
                    $_SESSION['error'] = 'PhpSpreadsheet is not installed. Cannot import Excel files.';
                    break;
                }

                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['import_subject_file']['tmp_name']);
                    $ws = $spreadsheet->getActiveSheet();
                    $rows = $ws->toArray(null, true, true, true);
                } catch (Throwable $e) {
                    $_SESSION['error'] = 'Could not read the Excel file. Please verify the format and try again.';
                    break;
                }

                if (!is_array($rows) || count($rows) < 2) {
                    $_SESSION['error'] = 'The spreadsheet has no data rows.';
                    break;
                }

                $normalizeHeader = static function ($value): string {
                    $s = strtolower(trim((string)$value));
                    $s = str_replace('*', '', $s);
                    $s = preg_replace('/\s+/u', ' ', $s);
                    return str_replace(' ', '_', $s);
                };

                $headerRow = reset($rows);
                $headers = [];
                foreach ($headerRow as $colLetter => $label) {
                    $headers[$colLetter] = $normalizeHeader($label);
                }

                $headerByName = array_flip(array_filter($headers));

                $colSubject = null;
                foreach (['subject_name', 'subject', 'subject_title', 'subjects_name'] as $candidate) {
                    if (isset($headerByName[$candidate])) {
                        $colSubject = $headerByName[$candidate];
                        break;
                    }
                }
                $colYear = $headerByName['year_level'] ?? $headerByName['year'] ?? $headerByName['grade'] ?? null;
                $colStrand = $headerByName['strand'] ?? null;
                $colSemester = $headerByName['semester'] ?? $headerByName['sem'] ?? null;

                if ($colSubject === null || $colYear === null || $colStrand === null) {
                    $_SESSION['error'] = 'Missing required Excel columns: Subject Name, Year Level, and Strand. Optional: Semester.';
                    break;
                }

                $parsed_rows = [];
                $parse_errors = [];
                $seen_keys = [];
                $sheetFirstRow = array_key_first($rows);

                foreach ($rows as $rowNum => $row) {
                    if ($rowNum === $sheetFirstRow) {
                        continue;
                    }

                    $subjectRaw = isset($row[$colSubject]) ? trim((string)$row[$colSubject]) : '';
                    $yearRaw = isset($row[$colYear]) ? trim((string)$row[$colYear]) : '';
                    $strandRaw = isset($row[$colStrand]) ? trim((string)$row[$colStrand]) : '';
                    if ($subjectRaw === '' && $yearRaw === '' && $strandRaw === '') {
                        continue;
                    }

                    $rowErrs = [];
                    $subject_name = resolveSubjectName($conn, $subjectRaw);
                    if ($subject_name === '') {
                        $rowErrs[] = "Row {$rowNum}: Subject Name is empty.";
                    }

                    $year_digits = preg_replace('/\D+/', '', $yearRaw);
                    if (!in_array($year_digits, ['11', '12'], true)) {
                        $rowErrs[] = "Row {$rowNum}: Year Level must be 11 or 12.";
                    }

                    $strandCanon = subjectManagementNormalizeStrandInput($strandRaw);
                    if ($strandCanon === null) {
                        $rowErrs[] = "Row {$rowNum}: Strand is invalid (use ABM/HUMSS/STEM/ICT or full strand name).";
                    }

                    if ($colSemester !== null) {
                        $semParsed = subjectManagementParseSemesterCell($row[$colSemester] ?? '', $import_sem_term);
                        $rowSemester = ($semParsed['sem'] === 2) ? 2 : 1;
                    } else {
                        $rowSemester = $import_sem_term;
                    }

                    if ($rowErrs !== []) {
                        array_push($parse_errors, ...$rowErrs);
                        continue;
                    }

                    $dedupe_key = strtolower($subject_name) . '|' . $year_digits . '|' . $strandCanon . '|' . $rowSemester;
                    if (isset($seen_keys[$dedupe_key])) {
                        continue;
                    }
                    $seen_keys[$dedupe_key] = true;

                    $parsed_rows[] = [
                        'subject_name' => subjectManagementNormalizeSubjectName($subject_name),
                        'year_level' => $year_digits,
                        'strand' => $strandCanon,
                        'semester' => $rowSemester,
                    ];
                }

                if ($parse_errors !== []) {
                    $shown = array_slice($parse_errors, 0, 12);
                    $more = max(0, count($parse_errors) - count($shown));
                    $_SESSION['error'] = implode(' ', $shown) . ($more > 0 ? " (+{$more} more issue(s).)" : '');
                    break;
                }

                if ($parsed_rows === []) {
                    $_SESSION['error'] = 'No valid subject rows found in the file.';
                    break;
                }

                $import_file_label = isset($_FILES['import_subject_file']['name'])
                    ? substr(trim((string)$_FILES['import_subject_file']['name']), 0, 255)
                    : '';
                $prior_active_count = 0;
                $cntRes = $conn->query("SELECT COUNT(*) AS c FROM subjects WHERE status = 'active'");
                if ($cntRes) {
                    $prior_active_count = (int)($cntRes->fetch_assoc()['c'] ?? 0);
                    $cntRes->close();
                }
                $archive_batch_id = 'imp-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));

                $conn->begin_transaction();
                try {
                    if ($subject_has_archive_meta) {
                        $archStmt = $conn->prepare("UPDATE subjects SET status = 'archived', archived_at = NOW(), archive_batch_id = ? WHERE status = 'active'");
                        if (!$archStmt) {
                            throw new RuntimeException($conn->error);
                        }
                        $archStmt->bind_param('s', $archive_batch_id);
                        if (!$archStmt->execute()) {
                            throw new RuntimeException($archStmt->error);
                        }
                        $archStmt->close();
                    } elseif (!$conn->query("UPDATE subjects SET status = 'archived' WHERE status = 'active'")) {
                        throw new RuntimeException($conn->error);
                    }

                    $insSubject = $conn->prepare(
                        "INSERT INTO subjects (subject_name, year_level, strand, teacher_id, status, semester, school_year) VALUES (?,?,?,NULLIF(?,0),'active',?,?)"
                    );
                    if (!$insSubject) {
                        throw new RuntimeException($conn->error);
                    }

                    $imported_count = 0;
                    foreach ($parsed_rows as $pr) {
                        $subName = $pr['subject_name'];
                        $yl = $pr['year_level'];
                        $st = $pr['strand'];
                        $sem = (int)$pr['semester'];
                        $teacherColOrZero = 0;

                        $insSubject->bind_param('sssiis', $subName, $yl, $st, $teacherColOrZero, $sem, $import_sy);
                        if (!$insSubject->execute()) {
                            throw new RuntimeException($insSubject->error);
                        }
                        $imported_count++;
                    }

                    $insSubject->close();
                    $conn->commit();

                    $imp_uname = isset($_SESSION['username']) ? (string)$_SESSION['username'] : '';
                    $imp_uid_final = 0;
                    if ($imp_uname !== '') {
                        $uq = $conn->prepare('SELECT user_id FROM users WHERE account_number = ? LIMIT 1');
                        if ($uq) {
                            $uq->bind_param('s', $imp_uname);
                            $uq->execute();
                            $ur = $uq->get_result()->fetch_assoc();
                            $uq->close();
                            if ($ur && !empty($ur['user_id'])) {
                                $imp_uid_final = (int)$ur['user_id'];
                            }
                        }
                    }
                    $semLog = (int)$import_sem_term;
                    $logIns = $conn->prepare(
                        'INSERT INTO curriculum_import_log (school_year, semester, subjects_imported, subjects_archived, archive_batch_id, file_name, imported_by_username, imported_by_user_id) VALUES (?,?,?,?,?,?,?,?)'
                    );
                    if ($logIns) {
                        $logIns->bind_param(
                            'siiisssi',
                            $import_sy,
                            $semLog,
                            $imported_count,
                            $prior_active_count,
                            $archive_batch_id,
                            $import_file_label,
                            $imp_uname,
                            $imp_uid_final
                        );
                        $logIns->execute();
                        $logIns->close();
                    }

                    $_SESSION['success'] = 'Imported ' . $imported_count . ' subject(s); previous active subjects were moved to Archive. Teachers are not assigned during import.';
                    $_SESSION['subject_mgmt_tab'] = 'curriculum';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $_SESSION['error'] = 'Import rolled back due to an error while saving.';
                }
                break;

            case 'create':
                $subject_name_raw = (string)($_POST['subject_name'] ?? '');
                $subject_name = subjectManagementNormalizeSubjectName(resolveSubjectName($conn, $subject_name_raw));
                $strand = (string)($_POST['strand'] ?? '');
                $year_level = (string)($_POST['year_level'] ?? '');
                $semester = ((int)($_POST['semester'] ?? $current_ctx['semester']) === 2) ? 2 : 1;
                $school_year = normalizeSchoolYear((string)$current_ctx['school_year']);

                if ($school_year === '') {
                    $_SESSION['error'] = 'No active school year / semester is set. Configure them in Evaluation Management before adding subjects.';
                    break;
                }
                if (strlen($subject_name) < 3 || strlen($subject_name) > 120) {
                    $_SESSION['error'] = 'Subject Name must be between 3 and 120 characters.';
                    break;
                }
                if (!in_array($year_level, ['11', '12'], true)) {
                    $_SESSION['error'] = 'Year Level must be Grade 11 or Grade 12.';
                    break;
                }
                if ($strand === '' || !in_array($strand, [
                    'Accountancy, Business, and Management',
                    'Humanities and Social Sciences',
                    'Science, Technology, Engineering, Mathematics',
                    'Information and Communication Technology',
                ], true)) {
                    $_SESSION['error'] = 'Invalid strand selected.';
                    break;
                }

                // Check for duplicates in the SELECTED STRAND only
                if (subjectManagementActiveSubjectExists($conn, $subject_name, $year_level, $strand, $semester, $school_year)) {
                    $_SESSION['error'] = 'Subject already exists in the ' . $strand . ' strand.';
                    break;
                }

                // Prepare for insertion into SELECTED STRAND only
                $insCreate = $conn->prepare(
                    "INSERT INTO subjects (subject_name, year_level, strand, teacher_id, status, semester, school_year)
                     VALUES (?, ?, ?, NULL, 'active', ?, ?)"
                );
                if (!$insCreate) {
                    $_SESSION['error'] = 'Error preparing subject creation.';
                    break;
                }

                // Insert subject for the selected strand only
                $insCreate->bind_param('sssis', $subject_name, $year_level, $strand, $semester, $school_year);
                if ($insCreate->execute()) {
                    $_SESSION['success'] = "Subject created successfully in the " . $strand . " strand. Assign teachers from Edit Subject when ready.";
                } else {
                    $_SESSION['error'] = 'Error creating subject: ' . $insCreate->error;
                }
                $insCreate->close();
                break;

            case 'update':
                $id = $_POST['subject_id'];
                $subject_name = resolveSubjectName($conn, $_POST['subject_name'] ?? '');
                $year_level = $_POST['year_level'];
                $strand = $_POST['strand'];

                // Get teacher IDs for assignment and to set primary teacher in subjects table
                $teacher_ids = $_POST['teacher_ids'] ?? [];
                if (!is_array($teacher_ids)) $teacher_ids = [];
                $teacher_ids = array_values(array_unique(array_filter(array_map('intval', $teacher_ids), fn($v) => $v > 0)));
                
                // Set primary teacher (first one) or NULL if none selected
                $primary_teacher_id = count($teacher_ids) > 0 ? $teacher_ids[0] : null;

                // Update subject with primary teacher
                $stmt = $conn->prepare("UPDATE subjects SET subject_name=?, year_level=?, strand=?, teacher_id=? WHERE subject_id=?");
                $stmt->bind_param("sssii", $subject_name, $year_level, $strand, $primary_teacher_id, $id);
                
                if ($stmt->execute()) {
                    // Replace assignments for current term with posted teacher_ids
                    $del = $conn->prepare("DELETE FROM subject_teacher_assignments WHERE subject_id = ? AND school_year = ? AND semester = ?");
                    if ($del) {
                        $del->bind_param("isi", $id, $current_ctx['school_year'], $current_ctx['semester']);
                        $del->execute();
                        $del->close();
                    }

                    if (count($teacher_ids) > 0) {
                        $ins = $conn->prepare("INSERT IGNORE INTO subject_teacher_assignments (subject_id, teacher_id, school_year, semester, role) VALUES (?, ?, ?, ?, ?)");
                        if ($ins) {
                            foreach ($teacher_ids as $i => $tid) {
                                $role = ($i === 0) ? 'primary' : 'assistant';
                                $ins->bind_param("iisis", $id, $tid, $current_ctx['school_year'], $current_ctx['semester'], $role);
                                $ins->execute();
                            }
                            $ins->close();
                        }
                    }
                    $_SESSION['success'] = "Subject updated successfully!";
                } else {
                    $_SESSION['error'] = "Error updating subject: " . $conn->error;
                }
                break;
                
            case 'archive':
                $id = (int)($_POST['subject_id'] ?? 0);
                if ($id <= 0) {
                    $_SESSION['error'] = "Invalid subject selected for archive.";
                    break;
                }
                if ($subject_has_archive_meta) {
                    $stmt = $conn->prepare("UPDATE subjects SET status = 'archived', archived_at = NOW(), archive_batch_id = NULL WHERE subject_id = ?");
                } else {
                    $stmt = $conn->prepare("UPDATE subjects SET status = 'archived' WHERE subject_id = ?");
                }
                if (!$stmt) {
                    $_SESSION['error'] = "Could not prepare archive action.";
                    break;
                }
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Subject archived successfully!";
                } else {
                    $_SESSION['error'] = "Error archiving subject: " . $conn->error;
                }
                $stmt->close();
                break;

            case 'restore':
                $id = (int)($_POST['subject_id'] ?? 0);
                if ($id <= 0) {
                    $_SESSION['error'] = 'Invalid subject selected for restore.';
                    break;
                }

                $fetch = $conn->prepare("SELECT subject_id, subject_name, year_level, strand, semester, school_year FROM subjects WHERE subject_id = ? AND status = 'archived' LIMIT 1");
                if (!$fetch) {
                    $_SESSION['error'] = 'Could not prepare restore validation.';
                    break;
                }
                $fetch->bind_param('i', $id);
                $fetch->execute();
                $row = $fetch->get_result()->fetch_assoc();
                $fetch->close();

                if (!$row) {
                    $_SESSION['error'] = 'Archived subject not found or already restored.';
                    break;
                }

                $subject_name = subjectManagementNormalizeSubjectName(resolveSubjectName($conn, (string)$row['subject_name']));
                $year_level = (string)($row['year_level'] ?? '');
                $strand = subjectManagementNormalizeStrandInput((string)($row['strand'] ?? '')) ?? (string)($row['strand'] ?? '');
                $semester = ((int)($row['semester'] ?? 1) === 2) ? 2 : 1;
                $school_year = normalizeSchoolYear((string)($row['school_year'] ?? ''));
                if ($school_year === '') {
                    $school_year = normalizeSchoolYear((string)$current_ctx['school_year']);
                }

                if (subjectManagementActiveSubjectExists($conn, $subject_name, $year_level, $strand, $semester, $school_year)) {
                    $_SESSION['error'] = 'This subject already exists in the selected strand.';
                    $_SESSION['subject_mgmt_tab'] = 'archived';
                    break;
                }

                if ($subject_has_archive_meta) {
                    $stmt = $conn->prepare("UPDATE subjects SET status = 'active', archived_at = NULL, archive_batch_id = NULL WHERE subject_id = ?");
                } else {
                    $stmt = $conn->prepare("UPDATE subjects SET status = 'active' WHERE subject_id = ?");
                }
                if (!$stmt) {
                    $_SESSION['error'] = 'Could not prepare restore action.';
                    break;
                }
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $_SESSION['success'] = 'Subject restored successfully!';
                } else {
                    $_SESSION['error'] = 'Error restoring subject: ' . $conn->error;
                }
                $stmt->close();
                $_SESSION['subject_mgmt_tab'] = 'archived';
                break;
                
            case 'create_curriculum':
                // Fetch current context fresh to ensure consistency
                $fresh_ctx = subjectManagementFetchCurrentContext($conn);
                $curriculum_sy = normalizeSchoolYear($fresh_ctx['school_year']);
                $curriculum_sem = $fresh_ctx['semester'];
                
                if ($curriculum_sy === '') {
                    $_SESSION['error'] = 'No active school year / semester is set. Configure them in Evaluation Management before creating curricula.';
                    break;
                }
                
                // Extract form data
                $curriculum_all_strands = isset($_POST['curriculum_all_strands']) && ((int)$_POST['curriculum_all_strands'] === 1);
                $curriculum_label = trim((string)($_POST['curriculum_label'] ?? ''));
                
                // Manual subjects - now includes strand and year_level information
                $manual_subjects = [];
                if (isset($_POST['manual_subjects']) && is_array($_POST['manual_subjects'])) {
                    foreach ($_POST['manual_subjects'] as $idx => $manual_subject) {
                        $name = trim((string)($manual_subject['name'] ?? ''));
                        $strand = trim((string)($manual_subject['strand'] ?? ''));
                        $year_level = trim((string)($manual_subject['year_level'] ?? ''));
                        if ($name !== '' && $strand !== '' && $year_level !== '') {
                            $manual_subjects[] = [
                                'name' => $name,
                                'strand' => $strand,
                                'year_level' => $year_level
                            ];
                        }
                    }
                }
                
                // Reuse subjects
                $reuse_subject_ids = [];
                if (isset($_POST['reuse_subject_ids']) && is_array($_POST['reuse_subject_ids'])) {
                    $reuse_subject_ids = array_filter(array_map('intval', $_POST['reuse_subject_ids']), fn($v) => $v > 0);
                }
                
                // Validate and build curriculum - now pass null for year_level since it's per-strand now
                $validation = subjectManagementValidateAndBuildCurriculum(
                    $conn,
                    $curriculum_sy,
                    $curriculum_sem,
                    null, // year_level is now per-strand in manual_subjects
                    $manual_subjects,
                    $reuse_subject_ids,
                    true // curriculum_all_strands is always true in new design
                );
                
                if (!$validation['success']) {
                    $_SESSION['error'] = 'Curriculum validation failed: ' . implode(' ', $validation['errors']);
                    break;
                }
                
                // Save curriculum
                $saveResult = subjectManagementSaveNewCurriculum(
                    $conn,
                    $curriculum_sy,
                    $curriculum_sem,
                    $validation['subjects'],
                    $curriculum_label ?: null
                );
                
                if ($saveResult['success']) {
                    $_SESSION['success'] = $saveResult['message'];
                    $_SESSION['subject_mgmt_tab'] = 'active';
                } else {
                    $_SESSION['error'] = $saveResult['message'];
                }
                break;
        }
        
        $redirect_suffix = '';
        if (!empty($_SESSION['subject_mgmt_tab'])) {
            $redirect_suffix = '?tab=' . rawurlencode((string)$_SESSION['subject_mgmt_tab']);
            unset($_SESSION['subject_mgmt_tab']);
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . $redirect_suffix);
        exit();
    }
}

$initial_subject_mgmt_tab = 'active';
if (isset($_GET['tab'])) {
    $tab_raw = strtolower(trim((string)$_GET['tab']));
    if ($tab_raw === 'curriculum') {
        $initial_subject_mgmt_tab = 'curriculum';
    } elseif ($tab_raw === 'archived') {
        $initial_subject_mgmt_tab = 'archived';
    }
}

$archive_context_sy = (isset($_GET['archive_sy']) && trim((string)$_GET['archive_sy']) !== '')
    ? normalizeSchoolYear((string)$_GET['archive_sy'])
    : normalizeSchoolYear($current_ctx['school_year']);

$archive_context_sem = $current_ctx['semester'];
if (isset($_GET['archive_sem']) && $_GET['archive_sem'] !== '' && $_GET['archive_sem'] !== null) {
    $archive_context_sem = ((int)$_GET['archive_sem'] === 2) ? 2 : 1;
}

// Fetch all distinct school years from archived subjects (data-driven from database)
$archive_school_year_options = [];
$asyRes = $conn->query(
    "SELECT DISTINCT REPLACE(REPLACE(s.school_year,'–','-'),'—','-') AS sy
     FROM subjects s
     WHERE s.status = 'archived'
       AND s.school_year IS NOT NULL
       AND TRIM(s.school_year) <> ''
     ORDER BY sy DESC"
);

if ($asyRes) {
    while ($asyRow = $asyRes->fetch_assoc()) {
        if (!empty($asyRow['sy'])) {
            $archive_school_year_options[] = $asyRow['sy'];
        }
    }
}

// Ensure options are unique and properly ordered
$archive_school_year_options = array_values(array_unique($archive_school_year_options));

// Store current school year for fallback if no archived years exist
$maybeCurrentSy = normalizeSchoolYear($current_ctx['school_year']);

// Modified query to fetch all assigned teachers with role info and semesters
$active_sql = "SELECT s.*,
                      CASE
                          WHEN s.subject_name REGEXP '^[0-9]+$' THEN COALESCE(s_ref.subject_name, s.subject_name)
                          ELSE s.subject_name
                      END AS display_subject_name,
                      GROUP_CONCAT(DISTINCT sta.teacher_id ORDER BY sta.role, sta.teacher_id SEPARATOR ',') AS assigned_teacher_ids_csv,
                      s.semester AS semesters_csv
               FROM subjects s
               LEFT JOIN subjects s_ref
                  ON s.subject_name REGEXP '^[0-9]+$'
                 AND CAST(s.subject_name AS UNSIGNED) = s_ref.subject_id
               LEFT JOIN subject_teacher_assignments sta
                  ON sta.subject_id = s.subject_id
                 AND sta.school_year = ?
               WHERE s.status = 'active'
               GROUP BY s.subject_id
               ORDER BY s.subject_id ASC";
$active_stmt = $conn->prepare($active_sql);
$active_subjects = [];
if ($active_stmt) {
    $active_stmt->bind_param("s", $current_ctx['school_year']);
    $active_stmt->execute();
    $active_subjects = $active_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Deduplicate by subject_id (keeps first occurrence)
    $active_subjects = array_reduce($active_subjects, function($result, $subject) {
        $id = $subject['subject_id'];
        if (!isset($result[$id])) {
            $result[$id] = $subject;
        }
        return $result;
    }, []);
    $active_subjects = array_values($active_subjects); // Re-index array
    
    // Enrich each subject with full teacher details for current term
    foreach ($active_subjects as &$subject) {
        if (!isset($subject['display_subject_name']) || trim((string)$subject['display_subject_name']) === '') {
            $subject['display_subject_name'] = (string)($subject['subject_name'] ?? '');
        }
        if (ctype_digit(trim((string)$subject['display_subject_name']))) {
            $subject['display_subject_name'] = resolveSubjectName($conn, $subject['display_subject_name']);
        }

        $teachers_detail_sql = "SELECT sta.teacher_id, sta.role, t.user_id, u.first_name, u.last_name
                               FROM subject_teacher_assignments sta
                               INNER JOIN teachers t ON sta.teacher_id = t.teacher_id
                               INNER JOIN users u ON t.user_id = u.user_id
                               WHERE sta.subject_id = ?
                                 AND REPLACE(REPLACE(sta.school_year,'–','-'),'—','-') = ?
                                 AND sta.semester = ?
                               ORDER BY CASE WHEN sta.role = 'primary' THEN 0 ELSE 1 END, u.first_name ASC";
        $detail_stmt = $conn->prepare($teachers_detail_sql);
        if ($detail_stmt) {
            $sy_norm = normalizeSchoolYear($current_ctx['school_year']);
            $detail_stmt->bind_param("isi", $subject['subject_id'], $sy_norm, $current_ctx['semester']);
            $detail_stmt->execute();
            $subject['assigned_teachers'] = $detail_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $detail_stmt->close();
        } else {
            $subject['assigned_teachers'] = [];
        }
        
        // Fallback: If no assignments found for this term, use primary teacher from subjects table
        if (empty($subject['assigned_teachers']) && !empty($subject['teacher_id'])) {
            $fallback_sql = "SELECT t.teacher_id, 'primary' AS role, t.user_id, u.first_name, u.last_name
                            FROM teachers t
                            INNER JOIN users u ON t.user_id = u.user_id
                            WHERE t.teacher_id = ?";
            $fallback_stmt = $conn->prepare($fallback_sql);
            if ($fallback_stmt) {
                $fallback_stmt->bind_param("i", $subject['teacher_id']);
                $fallback_stmt->execute();
                $fallback_result = $fallback_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                if (!empty($fallback_result)) {
                    $subject['assigned_teachers'] = $fallback_result;
                }
                $fallback_stmt->close();
            }
        }
    }
    unset($subject); // Clean up reference to prevent dangling reference
    $active_stmt->close();
}

$active_strand_name_index = [];
foreach ($active_subjects as $activeSubject) {
    $subjectName = resolveSubjectName($conn, (string)($activeSubject['subject_name'] ?? ''));
    $strandCanon = subjectManagementNormalizeStrandInput((string)($activeSubject['strand'] ?? '')) ?? (string)($activeSubject['strand'] ?? '');
    $strandKey = strtolower(trim($strandCanon));
    $nameKey = subjectManagementCanonNameKey(subjectManagementNormalizeSubjectName($subjectName));
    if (!isset($active_strand_name_index[$strandKey])) {
        $active_strand_name_index[$strandKey] = [];
    }
    $active_strand_name_index[$strandKey][$nameKey] = true;
}

// Modified query to fetch all assigned teachers with role info and semesters
// IMPORTANT: Filter by both subject.school_year AND status='archived' to show only subjects from selected year
$archived_sql = "SELECT s.*,
                        CASE
                            WHEN s.subject_name REGEXP '^[0-9]+$' THEN COALESCE(s_ref.subject_name, s.subject_name)
                            ELSE s.subject_name
                        END AS display_subject_name,
                        GROUP_CONCAT(DISTINCT sta.teacher_id ORDER BY sta.role, sta.teacher_id SEPARATOR ',') AS assigned_teacher_ids_csv,
                        s.semester AS semesters_csv
                 FROM subjects s
                 LEFT JOIN subjects s_ref
                    ON s.subject_name REGEXP '^[0-9]+$'
                   AND CAST(s.subject_name AS UNSIGNED) = s_ref.subject_id
                 LEFT JOIN subject_teacher_assignments sta
                    ON sta.subject_id = s.subject_id
                   AND REPLACE(REPLACE(sta.school_year,'–','-'),'—','-') = ?
                 WHERE s.status = 'archived'
                   AND REPLACE(REPLACE(s.school_year,'–','-'),'—','-') = ?
                 GROUP BY s.subject_id
                 ORDER BY s.subject_id ASC";
$archived_stmt = $conn->prepare($archived_sql);
$archived_subjects = [];
if ($archived_stmt) {
    // Bind twice: once for sta.school_year join, once for s.school_year in WHERE clause
    $archived_stmt->bind_param("ss", $archive_context_sy, $archive_context_sy);
    $archived_stmt->execute();
    $archived_subjects = $archived_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Deduplicate by subject_id (keeps first occurrence)
    $archived_subjects = array_reduce($archived_subjects, function($result, $subject) {
        $id = $subject['subject_id'];
        if (!isset($result[$id])) {
            $result[$id] = $subject;
        }
        return $result;
    }, []);
    $archived_subjects = array_values($archived_subjects); // Re-index array
    
    // Enrich each subject with full teacher details for current term
    foreach ($archived_subjects as &$subject) {
        if (!isset($subject['display_subject_name']) || trim((string)$subject['display_subject_name']) === '') {
            $subject['display_subject_name'] = (string)($subject['subject_name'] ?? '');
        }
        if (ctype_digit(trim((string)$subject['display_subject_name']))) {
            $subject['display_subject_name'] = resolveSubjectName($conn, $subject['display_subject_name']);
        }

        $teachers_detail_sql = "SELECT sta.teacher_id, sta.role, t.user_id, u.first_name, u.last_name
                               FROM subject_teacher_assignments sta
                               INNER JOIN teachers t ON sta.teacher_id = t.teacher_id
                               INNER JOIN users u ON t.user_id = u.user_id
                               WHERE sta.subject_id = ?
                                 AND REPLACE(REPLACE(sta.school_year,'–','-'),'—','-') = ?
                                 AND sta.semester = ?
                               ORDER BY CASE WHEN sta.role = 'primary' THEN 0 ELSE 1 END, u.first_name ASC";
        $detail_stmt = $conn->prepare($teachers_detail_sql);
        if ($detail_stmt) {
            $detail_stmt->bind_param("isi", $subject['subject_id'], $archive_context_sy, $archive_context_sem);
            $detail_stmt->execute();
            $subject['assigned_teachers'] = $detail_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $detail_stmt->close();
        } else {
            $subject['assigned_teachers'] = [];
        }
        
        // Fallback: If no assignments found for this term, use primary teacher from subjects table
        if (empty($subject['assigned_teachers']) && !empty($subject['teacher_id'])) {
            $fallback_sql = "SELECT t.teacher_id, 'primary' AS role, t.user_id, u.first_name, u.last_name
                            FROM teachers t
                            INNER JOIN users u ON t.user_id = u.user_id
                            WHERE t.teacher_id = ?";
            $fallback_stmt = $conn->prepare($fallback_sql);
            if ($fallback_stmt) {
                $fallback_stmt->bind_param("i", $subject['teacher_id']);
                $fallback_stmt->execute();
                $fallback_result = $fallback_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                if (!empty($fallback_result)) {
                    $subject['assigned_teachers'] = $fallback_result;
                }
                $fallback_stmt->close();
            }
        }

        $subjectName = resolveSubjectName($conn, (string)($subject['subject_name'] ?? ''));
        $strandCanon = subjectManagementNormalizeStrandInput((string)($subject['strand'] ?? '')) ?? (string)($subject['strand'] ?? '');
        $strandKey = strtolower(trim($strandCanon));
        $nameKey = subjectManagementCanonNameKey(subjectManagementNormalizeSubjectName($subjectName));
        
        // Check if archived subject is from current active school year
        $archivedSy = normalizeSchoolYear((string)($subject['school_year'] ?? ''));
        $isCurrentYear = ($archivedSy === normalizeSchoolYear($current_ctx['school_year']));
        
        // Restore is allowed only if:
        // 1. Subject is from current active school year (not a past year)
        // 2. Subject name doesn't already exist in active records for the same strand
        $subject['can_restore'] = $isCurrentYear && !isset($active_strand_name_index[$strandKey][$nameKey]);
        
        if (!$isCurrentYear) {
            $subject['restore_block_reason'] = 'Cannot restore: this subject belongs to a past school year (' . htmlspecialchars($archivedSy) . '). Past subjects are locked for historical integrity.';
        } elseif (isset($active_strand_name_index[$strandKey][$nameKey])) {
            $subject['restore_block_reason'] = 'Cannot restore: this subject already exists in active records for ' . htmlspecialchars($strandCanon) . ' strand.';
        } else {
            $subject['restore_block_reason'] = '';
        }
    }
    unset($subject); // Clean up reference to prevent dangling reference
    $archived_stmt->close();
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_subjects,
    SUM(CASE WHEN year_level = '11' AND status = 'active' THEN 1 ELSE 0 END) as grade_11_count,
    SUM(CASE WHEN year_level = '12' AND status = 'active' THEN 1 ELSE 0 END) as grade_12_count,
    SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived_count
    FROM subjects";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

$curriculum_import_log = [];
$log_result = $conn->query(
    "SELECT log_id, school_year, semester, subjects_imported, subjects_archived, archive_batch_id, file_name, imported_by_username, created_at
     FROM curriculum_import_log
     ORDER BY created_at DESC
     LIMIT 30"
);
if ($log_result) {
    while ($log_row = $log_result->fetch_assoc()) {
        $curriculum_import_log[] = $log_row;
    }
}

// Lightweight subject catalog grouped by strand for Add Subject autocomplete.
$subject_catalog_by_strand = [];
$subject_catalog_sql = "SELECT DISTINCT
                            s.strand,
                            TRIM(
                                CASE
                                    WHEN s.subject_name REGEXP '^[0-9]+$' THEN COALESCE(s_ref.subject_name, s.subject_name)
                                    ELSE s.subject_name
                                END
                            ) AS display_subject_name
                        FROM subjects s
                        LEFT JOIN subjects s_ref
                            ON s.subject_name REGEXP '^[0-9]+$'
                           AND CAST(s.subject_name AS UNSIGNED) = s_ref.subject_id
                        WHERE TRIM(CASE
                            WHEN s.subject_name REGEXP '^[0-9]+$' THEN COALESCE(s_ref.subject_name, s.subject_name)
                            ELSE s.subject_name
                        END) <> ''
                          AND TRIM(s.strand) <> ''
                        ORDER BY s.strand ASC, display_subject_name ASC";
$subject_catalog_result = $conn->query($subject_catalog_sql);
if ($subject_catalog_result) {
    while ($row = $subject_catalog_result->fetch_assoc()) {
        $strand = subjectManagementNormalizeStrandInput((string)($row['strand'] ?? ''));
        $name = subjectManagementNormalizeSubjectName((string)($row['display_subject_name'] ?? ''));
        if ($strand === null || $name === '') {
            continue;
        }
        if (!isset($subject_catalog_by_strand[$strand])) {
            $subject_catalog_by_strand[$strand] = [];
        }
        $subject_catalog_by_strand[$strand][] = $name;
    }
}
foreach ($subject_catalog_by_strand as $strandKey => $names) {
    $subject_catalog_by_strand[$strandKey] = array_values(array_unique($names));
    sort($subject_catalog_by_strand[$strandKey], SORT_NATURAL | SORT_FLAG_CASE);
}

// $teachers is already fetched above for current SY/Sem
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Subject Management</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #800000;
            --primary-hover: #a00000;
            --primary-light: rgba(128, 0, 0, 0.1);
            --text-dark: #2c3e50;
            --border-color: #dee2e6;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .main-content {
            margin-left: 250px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }
        
        .header-section {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 40px;
            box-shadow: 0 15px 35px rgba(128, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .header-section h1 {
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 2.8rem;
            position: relative;
            z-index: 2;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 25px;
            position: relative;
            z-index: 2;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
            flex-wrap: wrap;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header i {
            font-size: 1.5rem;
            color: var(--primary-color);
            background: rgba(128, 0, 0, 0.1);
            padding: 10px;
            border-radius: 10px;
        }
        
        .tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .tab-button {
            padding: 12px 24px;
            background: transparent;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            color: #6c757d;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            position: relative;
            top: 2px;
        }
        
        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-button:hover {
            color: var(--primary-color);
        }
        
        /* Hide main subject management tabs by default */
        #active.tab-content, 
        #archived.tab-content, 
        #curriculum.tab-content {
            display: none;
        }
        
        #active.tab-content.active, 
        #archived.tab-content.active, 
        #curriculum.tab-content.active {
            display: block;
        }
        
        /* Strand modal tabs should always be visible (uses Bootstrap native tab system) */
        #strandGradeLevelTabContent {
            display: block;
        }

        .curriculum-context-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 20px;
            padding: 14px 20px;
            margin-bottom: 24px;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            position: sticky;
            top: 72px;
            z-index: 50;
        }

        .curriculum-context-bar .ctx-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #6c757d;
            font-weight: 700;
            margin: 0;
        }

        .curriculum-context-bar .ctx-value {
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }

        .curriculum-tools-card {
            background: #fafbfc;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .curriculum-log-table {
            font-size: 0.9rem;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            color: white !important;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(128, 0, 0, 0.3);
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(128, 0, 0, 0.4);
            background: linear-gradient(135deg, var(--primary-hover), var(--primary-color));
            color: white !important;
        }
        
        .table-custom {
            margin-bottom: 0;
        }
        
        .table-custom thead th {
            background: #f8f9fa;
            border: none;
            font-weight: 600;
            color: var(--text-dark);
            padding: 20px 15px;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-custom tbody td {
            padding: 20px 15px;
            border-top: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        .table-custom tbody tr {
            transition: all 0.3s ease;
        }
        
        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        
        .btn-action {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .btn-edit {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .btn-edit:hover {
            background: #1976d2;
            color: white;
            transform: scale(1.1);
        }
        
        .btn-archive {
            background: #fff3cd;
            color: #856404;
        }
        
        .btn-archive:hover {
            background: #ffc107;
            color: white;
            transform: scale(1.1);
        }
        
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 25px 30px;
            border-bottom: none;
        }
        
        .modal-title {
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .btn-close {
            filter: invert(1);
        }
        
        .form-control-custom {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
            accent-color: var(--primary-color);
        }

        /* Stronger focus styles to override browser default blue outlines
           Apply to inputs, selects, and textareas using the custom class */
        .form-control-custom:focus,
        input.form-control-custom:focus,
        select.form-control-custom:focus,
        textarea.form-control-custom:focus {
            outline: none !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(128, 0, 0, 0.25) !important;
        }

        .form-control-custom:disabled {
            background-color: #f5f5f5;
            color: #495057;
            cursor: not-allowed;
            opacity: 0.7;
            border-color: #dee2e6;
        }

        .form-control-custom:disabled:hover {
            border-color: #dee2e6;
        }
        
        .alert-custom {
            border-radius: 15px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .year-badge {
            background: var(--primary-light);
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            color: var(--primary-color);
            display: inline-block;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .teacher-name {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .teacher-tbd {
            color: #dc3545;
            font-style: italic;
            font-weight: 600;
        }

        /* Multi-teacher badge styles */
        .teachers-container {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .teacher-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            background: var(--primary-light);
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
            white-space: nowrap;
        }

        .teacher-badge.primary {
            background: linear-gradient(135deg, rgba(128, 0, 0, 0.15), rgba(128, 0, 0, 0.08));
            border: 1.5px solid var(--primary-color);
            font-weight: 600;
        }

        .teacher-badge.assistant {
            background: #e8f4f8;
            color: #0c5460;
            border-color: #0c5460;
        }

        .teacher-badge-icon {
            font-size: 0.75rem;
        }

        .teacher-empty {
            color: #6c757d;
            font-style: italic;
            font-size: 0.9rem;
        }

        /* Teacher selection modal improvements */
        .teacher-select-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .teacher-select-list {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            max-height: 300px;
            overflow-y: auto;
        }

        .teacher-select-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
        }

        .teacher-select-item:hover {
            background: #f8f9fa;
        }

        .teacher-select-item:last-child {
            border-bottom: none;
        }

        .teacher-select-item input[type="checkbox"] {
            margin-right: 12px;
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }

        .teacher-select-item label {
            margin: 0;
            flex: 1;
            cursor: pointer;
            user-select: none;
        }

        .teacher-select-role-badge {
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 10px;
            background: var(--primary-light);
            color: var(--primary-color);
            margin-left: auto;
            font-weight: 600;
            display: none;
        }

        .teacher-select-item input[type="checkbox"]:checked ~ label + .teacher-select-role-badge.primary {
            display: inline-block;
        }
        
        .teacher-select-info {
            font-size: 0.85rem;
            color: #6c757d;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 3px solid var(--primary-color);
        }
        
        /* Disabled button styles */
        .btn-action:disabled,
        .btn-action[disabled] {
            opacity: 0.55;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .btn-action:disabled:hover,
        .btn-action[disabled]:hover,
        .btn-action:disabled:active,
        .btn-action[disabled]:active {
            opacity: 0.55;
            transform: none;
            background: inherit;
            color: inherit;
            pointer-events: none;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .header-section h1 {
                font-size: 2.2rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .card-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .tab-button {
                padding: 10px 16px;
                font-size: 0.9rem;
            }

            .teachers-container {
                gap: 6px;
            }

            .teacher-badge {
                font-size: 0.8rem;
                padding: 5px 10px;
            }
        }


    </style>
</head>
<body>
    <?php include '../includes/side_bar.php'; ?>
    <?php include '../includes/navbar.php'; ?>
    <?php renderModernAlertSystem(); ?>

    <div class="main-content">
        <!-- Header Section -->
        <div class="header-section">
            <h1><i class="fas fa-book me-3"></i>Subject Management</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($active_subjects); ?></div>
                    <div class="stat-label">Active Subjects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['grade_11_count'] ?? 0; ?></div>
                    <div class="stat-label">Grade 11 Subjects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['grade_12_count'] ?? 0; ?></div>
                    <div class="stat-label">Grade 12 Subjects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['archived_count'] ?? 0; ?></div>
                    <div class="stat-label">Archived Subjects</div>
                </div>
            </div>
        </div>

        <div class="curriculum-context-bar" aria-label="Active curriculum context">
            <div>
                <p class="ctx-label mb-1">Current school year</p>
                <p class="ctx-value mb-0">
                    <?php echo htmlspecialchars($current_ctx['school_year'] !== '' ? $current_ctx['school_year'] : '—'); ?>
                </p>
            </div>
            <div>
                <p class="ctx-label mb-1">Current semester</p>
                <p class="ctx-value mb-0"><?php echo htmlspecialchars($current_ctx['semester_text']); ?></p>
            </div>
        </div>

        <!-- Main Content Card -->
        <div class="dashboard-card">
            <div class="card-header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="fas fa-list"></i>
                    <h3>Subjects &amp; curriculum</h3>
                </div>
            </div>

            <div class="tab-navigation">
                <button type="button" class="tab-button<?php echo $initial_subject_mgmt_tab === 'active' ? ' active' : ''; ?>" onclick="switchTab('active', this)">
                    <i class="fas fa-check-circle me-2"></i>Active subjects
                </button>
                <button type="button" class="tab-button<?php echo $initial_subject_mgmt_tab === 'archived' ? ' active' : ''; ?>" onclick="switchTab('archived', this)">
                    <i class="fas fa-archive me-2"></i>Archived subjects
                </button>
                <button type="button" class="tab-button<?php echo $initial_subject_mgmt_tab === 'curriculum' ? ' active' : ''; ?>" onclick="switchTab('curriculum', this)">
                    <i class="fas fa-layer-group me-2"></i>Curriculum &amp; import
                </button>
            </div>

            <div id="subjectToolbarRow" style="display: <?php echo ($initial_subject_mgmt_tab === 'active' || $initial_subject_mgmt_tab === 'archived') ? 'flex' : 'none'; ?>; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
                <div id="addSubjectBtnWrap" style="display: <?php echo $initial_subject_mgmt_tab === 'active' ? 'inline-flex' : 'none'; ?>; gap: 10px;">
                    <button type="button" class="btn btn-primary-custom d-inline-flex align-items-center"
                        <?php if ($is_evaluation_ongoing): ?>
                        disabled title="Cannot add subjects while an evaluation is ongoing"
                        onclick="showModernAlert('warning', 'Action Blocked', 'Cannot add subjects during an ongoing evaluation period.'); return false;"
                        <?php else: ?>
                        data-bs-toggle="modal" data-bs-target="#addSubjectModal"
                        <?php endif; ?>
                        style="height: 40px;">
                        <i class="fas fa-plus me-2"></i>Add Subject
                    </button>
                    
                    <button type="button" class="btn btn-info d-inline-flex align-items-center"
                        <?php if ($is_evaluation_ongoing): ?>
                        disabled title="Cannot create curriculum while an evaluation is ongoing"
                        onclick="showModernAlert('warning', 'Action Blocked', 'Cannot create curriculum during an ongoing evaluation period.'); return false;"
                        <?php else: ?>
                        data-bs-toggle="modal" data-bs-target="#createCurriculumStep1Modal"
                        <?php endif; ?>
                        style="height: 40px; background-color: #17a2b8 !important; border-color: #17a2b8 !important;">
                        <i class="fas fa-book me-2"></i>Create Curriculum
                    </button>
                </div>
                <!-- Updated search bar UI to match user_management.php with search icon inside input -->
                <div style="flex: 1; min-width: 250px;">
                    <div style="position: relative;">
                        <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999;"></i>
                        <input 
                            type="text" 
                            id="searchSubject" 
                            class="form-control form-control-custom" 
                            placeholder="Search by subject name..." 
                            style="height: 40px; padding-left: 38px;"
                            oninput="filterSubjects()">
                    </div>
                </div>
                <select 
                    id="filterSemester" 
                    class="form-control form-control-custom" 
                    style="max-width: 180px; height: 40px; padding: 10px 15px;"
                    onchange="filterSubjects()">
                    <option value="" selected>All Semesters</option>
                    <option value="1">1st Semester</option>
                    <option value="2">2nd Semester</option>
                </select>
                <select 
                    id="filterYearLevel" 
                    class="form-control form-control-custom" 
                    style="max-width: 180px; height: 40px; padding: 10px 15px;"
                    onchange="filterSubjects()">
                    <option value="">All Year Levels</option>
                    <option value="11">Year 11</option>
                    <option value="12">Year 12</option>
                </select>
                <select 
                    id="filterStrand" 
                    class="form-control form-control-custom" 
                    style="max-width: 200px; height: 40px; padding: 10px 15px;"
                    onchange="filterSubjects()">
                    <option value="">All Strands</option>
                    <option value="Accountancy, Business, and Management">ABM</option>
                    <option value="Humanities and Social Sciences">HUMSS</option>
                    <option value="Science, Technology, Engineering, Mathematics">STEM</option>
                    <option value="Information and Communication Technology">ICT</option>
                </select>
            </div>
            
            <!-- Active Subjects Tab -->
            <div id="active" class="tab-content<?php echo $initial_subject_mgmt_tab === 'active' ? ' active' : ''; ?>">
                <?php if (count($active_subjects) > 0): ?>
                    <table class="table table-custom" id="activeSubjectsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject Name</th>
                                <th>Assigned Teachers</th>
                                <th>Year Level</th>
                                <th>Strand</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_subjects as $subject): ?>
                                <tr class="subject-row" data-year-level="<?php echo htmlspecialchars($subject['year_level']); ?>" data-strand="<?php echo htmlspecialchars($subject['strand']); ?>" data-subject-name="<?php echo htmlspecialchars(strtolower($subject['display_subject_name'])); ?>" data-semester="<?php echo htmlspecialchars($subject['semesters_csv'] ?? ''); ?>">
                                    <td>
                                        <strong>#<?php echo str_pad($subject['subject_id'], 3, '0', STR_PAD_LEFT); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($subject['display_subject_name']); ?></strong>
                                    </td>
                                    <!-- Display all assigned teachers as badges -->
                                    <td>
                                        <?php if (!empty($subject['assigned_teachers'])): ?>
                                            <div class="teachers-container">
                                                <?php foreach ($subject['assigned_teachers'] as $teacher): ?>
                                                    <span class="teacher-badge <?php echo $teacher['role']; ?>" title="<?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>">
                                                        <i class="fas <?php echo $teacher['role'] === 'primary' ? 'fa-star' : 'fa-user'; ?> teacher-badge-icon"></i>
                                                        <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                                        <?php if ($teacher['role'] === 'primary'): ?>
                                                            <span style="font-size: 0.7rem; font-weight: 700;">(Primary)</span>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="teacher-empty"><i class="fas fa-exclamation-circle me-1"></i>No teachers assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="year-badge">Grade <?php echo htmlspecialchars($subject['year_level']); ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars(substr($subject['strand'], 0, 25)) . (strlen($subject['strand']) > 25 ? '...' : ''); ?></small>
                                    </td>
                                    <td class="text-center">
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-action btn-edit" 
                                                    onclick="editSubject(<?php echo htmlspecialchars(json_encode($subject)); ?>); return false;"
                                                    <?php if (!$is_evaluation_ongoing): ?>
                                                    data-bs-toggle="modal" data-bs-target="#editSubjectModal"
                                                    <?php endif; ?>
                                                    title="<?php echo $is_evaluation_ongoing ? 'Cannot edit subjects during ongoing evaluation' : 'Edit'; ?>"
                                                    <?php echo $is_evaluation_ongoing ? 'disabled' : ''; ?>>
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-action btn-archive archive-subject-btn"
                                                    data-subject-id="<?php echo (int)$subject['subject_id']; ?>"
                                                    data-subject-name="<?php echo htmlspecialchars((string)$subject['display_subject_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    title="Archive">
                                                <i class="fas fa-archive"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>No active subjects found</h4>
                        <p>Add subjects manually or import a curriculum file, then assign teachers inside the system.</p>
                    </div>
                <?php endif; ?>
                <div id="activeNoResults" class="empty-state" style="display: none;">
                    <i class="fas fa-search"></i>
                    <h4>No subjects match your search</h4>
                    <p>Try adjusting your search or filter criteria.</p>
                </div>
            </div>
            
            <!-- Archived Subjects Tab -->
            <div id="archived" class="tab-content<?php echo $initial_subject_mgmt_tab === 'archived' ? ' active' : ''; ?>">
                <div id="archiveContextBar" class="d-flex flex-wrap align-items-center gap-3 mb-3" style="display: <?php echo $initial_subject_mgmt_tab === 'archived' ? 'flex' : 'none'; ?>;">
                    <label class="mb-0 small text-muted fw-semibold" for="filterArchiveSchoolYear">Archive school year</label>
                    <select id="filterArchiveSchoolYear" class="form-control form-control-custom" style="max-width: 200px; height: 40px;"
                            onchange="subjectManagementReloadArchiveContext()">
                        <?php foreach ($archive_school_year_options as $optSy): ?>
                            <option value="<?php echo htmlspecialchars($optSy); ?>"
                                <?php echo ($optSy === $archive_context_sy) ? 'selected' : ''; ?>><?php echo htmlspecialchars($optSy); ?></option>
                        <?php endforeach; ?>
                        <?php if ($archive_school_year_options === [] && $maybeCurrentSy !== ''): ?>
                            <option value="<?php echo htmlspecialchars($maybeCurrentSy); ?>" selected><?php echo htmlspecialchars($maybeCurrentSy); ?></option>
                        <?php endif; ?>
                    </select>
                    <button type="button" class="btn btn-primary-custom d-flex align-items-center" style="height: 40px;"
                            data-bs-toggle="modal" data-bs-target="#archiveExportModal"
                            title="Export archived subjects matching current filters">
                        <i class="fas fa-download me-2"></i>Export to Excel
                    </button>
                </div>

                <?php if (count($archived_subjects) > 0): ?>
                    <table class="table table-custom" id="archivedSubjectsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject Name</th>
                                <th>Assigned Teachers</th>
                                <th>Year Level</th>
                                <th>Strand</th>
                                <?php if ($subject_has_archive_meta): ?><th>Archived</th><?php endif; ?>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archived_subjects as $subject): ?>
                                <tr class="subject-row" data-year-level="<?php echo htmlspecialchars($subject['year_level']); ?>" data-strand="<?php echo htmlspecialchars($subject['strand']); ?>" data-subject-name="<?php echo htmlspecialchars(strtolower($subject['display_subject_name'])); ?>" data-semester="<?php echo htmlspecialchars($subject['semesters_csv'] ?? ''); ?>">
                                    <td>
                                        <strong>#<?php echo str_pad($subject['subject_id'], 3, '0', STR_PAD_LEFT); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($subject['display_subject_name']); ?></strong>
                                    </td>
                                    <!-- Display all assigned teachers as badges -->
                                    <td>
                                        <?php if (!empty($subject['assigned_teachers'])): ?>
                                            <div class="teachers-container">
                                                <?php foreach ($subject['assigned_teachers'] as $teacher): ?>
                                                    <span class="teacher-badge <?php echo $teacher['role']; ?>" title="<?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>">
                                                        <i class="fas <?php echo $teacher['role'] === 'primary' ? 'fa-star' : 'fa-user'; ?> teacher-badge-icon"></i>
                                                        <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                                        <?php if ($teacher['role'] === 'primary'): ?>
                                                            <span style="font-size: 0.7rem; font-weight: 700;">(Primary)</span>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="teacher-empty"><i class="fas fa-exclamation-circle me-1"></i>No teachers assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="year-badge">Grade <?php echo htmlspecialchars($subject['year_level']); ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars(substr($subject['strand'], 0, 25)) . (strlen($subject['strand']) > 25 ? '...' : ''); ?></small>
                                    </td>
                                    <?php if ($subject_has_archive_meta): ?>
                                    <td>
                                        <small class="text-muted"><?php echo !empty($subject['archived_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime($subject['archived_at']))) : '—'; ?></small>
                                    </td>
                                    <?php endif; ?>
                                    <td class="text-center">
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-action btn-edit restore-subject-btn"
                                                    data-subject-id="<?php echo (int)$subject['subject_id']; ?>"
                                                    data-subject-name="<?php echo htmlspecialchars((string)$subject['display_subject_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    title="<?php echo !empty($subject['can_restore']) ? 'Restore' : htmlspecialchars((string)($subject['restore_block_reason'] ?? 'Cannot restore'), ENT_QUOTES, 'UTF-8'); ?>"
                                                    <?php echo empty($subject['can_restore']) ? 'disabled' : ''; ?>>
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-archive"></i>
                        <h4>No archived subjects found</h4>
                        <?php if ($archive_school_year_options && count($archive_school_year_options) > 0): ?>
                            <p>No archived subjects exist for school year <strong><?php echo htmlspecialchars($archive_context_sy); ?></strong>.</p>
                            <p class="text-muted small">Try selecting a different school year from the dropdown above.</p>
                        <?php else: ?>
                            <p>No archived subjects exist yet.</p>
                            <p class="text-muted small">Archived subjects will appear here after subjects are archived.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div id="curriculum" class="tab-content<?php echo $initial_subject_mgmt_tab === 'curriculum' ? ' active' : ''; ?>">
                <div class="curriculum-tools-card">
                    <h4 class="h6 fw-bold text-dark mb-2"><i class="fas fa-file-import me-2" style="color: var(--primary-color);"></i>Replace active curriculum</h4>
                    <p class="text-muted small mb-3 mb-md-0">
                        Upload a validated Excel workbook to archive all current active subjects and load a new set for
                        <strong><?php echo htmlspecialchars($current_ctx['school_year'] !== '' ? $current_ctx['school_year'] : '—'); ?></strong>
                        · <strong><?php echo htmlspecialchars($current_ctx['semester_text']); ?></strong>.
                    </p>
                    <div class="mt-3">
                        <button type="button" class="btn btn-primary-custom d-inline-flex align-items-center"
                            <?php if ($is_evaluation_ongoing): ?>
                            disabled title="Cannot import while an evaluation is ongoing"
                            onclick="showModernAlert('warning', 'Action Blocked', 'Cannot import a subject set during an ongoing evaluation period.'); return false;"
                            <?php else: ?>
                            data-bs-toggle="modal" data-bs-target="#importSubjectSetModal"
                            <?php endif; ?>
                            style="height: 40px;">
                            <i class="fas fa-file-excel me-2"></i>Import subject set
                        </button>
                    </div>
                </div>



                <h4 class="h6 fw-bold text-dark mb-3"><i class="fas fa-history me-2" style="color: var(--primary-color);"></i>Recent curriculum imports</h4>
                <?php if (count($curriculum_import_log) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-custom curriculum-log-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>School year</th>
                                    <th>Sem</th>
                                    <th>Imported</th>
                                    <th>Archived prior</th>
                                    <th>File</th>
                                    <th>By</th>
                                    <th>Batch ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($curriculum_import_log as $log): ?>
                                <tr>
                                    <td><small><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))); ?></small></td>
                                    <td><?php echo htmlspecialchars($log['school_year']); ?></td>
                                    <td><?php echo (int)$log['semester'] === 2 ? '2' : '1'; ?></td>
                                    <td><?php echo (int)$log['subjects_imported']; ?></td>
                                    <td><?php echo (int)$log['subjects_archived']; ?></td>
                                    <td><small><?php echo htmlspecialchars($log['file_name'] !== '' && $log['file_name'] !== null ? $log['file_name'] : '—'); ?></small></td>
                                    <td><small><?php echo htmlspecialchars($log['imported_by_username'] !== '' && $log['imported_by_username'] !== null ? $log['imported_by_username'] : '—'); ?></small></td>
                                    <td><code class="small"><?php echo htmlspecialchars($log['archive_batch_id']); ?></code></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-clipboard-list"></i>
                        <p class="mb-0 text-muted">No import history yet. Successful curriculum imports are logged here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div class="modal fade" id="addSubjectModal" tabindex="-1" aria-hidden="true" inert>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>Add Subject
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addSubjectForm">
                        <input type="hidden" name="action" value="create">

                        <div class="alert alert-info mb-3" role="alert">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Strand-Specific Workflow:</strong> Subjects are created for a specific strand only. Select the strand first before adding the subject.
                        </div>

                        <div class="mb-3">
                            <label for="add_strand" class="form-label">Strand *</label>
                            <select class="form-control form-control-custom" id="add_strand" name="strand" required>
                                <option value="">Select a Strand</option>
                                <option value="Accountancy, Business, and Management">Accountancy, Business, and Management (ABM)</option>
                                <option value="Humanities and Social Sciences">Humanities and Social Sciences (HUMSS)</option>
                                <option value="Science, Technology, Engineering, Mathematics">Science, Technology, Engineering, Mathematics (STEM)</option>
                                <option value="Information and Communication Technology">Information and Communication Technology (ICT)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="add_subject_name" class="form-label">Subject Name *</label>
                            <input type="text" class="form-control form-control-custom" id="add_subject_name" name="subject_name" maxlength="120" list="subjectCatalogList" required>
                            <datalist id="subjectCatalogList"></datalist>
                            <div class="form-text">
                                Type to search existing subjects, or enter a new subject name for this strand.
                                <span id="addSubjectNameHint" class="ms-1 text-muted"></span>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="add_year_level" class="form-label">Year Level *</label>
                                <select class="form-control form-control-custom" id="add_year_level" name="year_level" required>
                                    <option value="">Select Year Level</option>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="add_semester" class="form-label">Semester *</label>
                                <select class="form-control form-control-custom" id="add_semester" name="semester" required>
                                    <option value="1" <?php echo (int)$current_ctx['semester'] === 1 ? 'selected' : ''; ?>>1st Semester</option>
                                    <option value="2" <?php echo (int)$current_ctx['semester'] === 2 ? 'selected' : ''; ?>>2nd Semester</option>
                                </select>
                            </div>
                        </div>

                        <div class="alert alert-info small mb-0" role="alert">
                            Teacher assignment is managed separately after saving. Use <strong>Edit Subject</strong> to assign primary and assistant teachers.
                        </div>

                        <div class="text-end mt-4">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Create Subject
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Subject Set Modal -->
    <div class="modal fade" id="importSubjectSetModal" tabindex="-1" aria-hidden="true" inert>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-import me-2"></i>Import Subject Set
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="importSubjectSetForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import_subject_set">
                        <!-- System's current school year - captured at form render time for audit trail and validation -->
                        <input type="hidden" name="import_system_school_year" value="<?php echo htmlspecialchars(normalizeSchoolYear($current_ctx['school_year'])); ?>">
                        <input type="hidden" name="import_system_semester" value="<?php echo htmlspecialchars((string)$current_ctx['semester']); ?>">

                        <div class="alert alert-warning mb-3" role="alert">
                            <strong><i class="fas fa-exclamation-triangle me-2"></i>Curriculum rollover:</strong>
                            Saving this import first archives <em>every</em> active subject, then inserts the workbook as the new active set. Import errors roll back entirely.
                        </div>

                        <div class="alert alert-secondary small mb-3" role="alert">
                            <strong><i class="fas fa-calendar-check me-2"></i>Active period (automatic):</strong>
                            <?php if (normalizeSchoolYear((string)$current_ctx['school_year']) !== ''): ?>
                                School Year <strong><?php echo htmlspecialchars($current_ctx['school_year']); ?></strong>
                                · <strong><?php echo htmlspecialchars($current_ctx['semester_text']); ?></strong>.
                            <?php else: ?>
                                <span class="text-danger">School year / semester are not configured. Set them in Evaluation Management (<em>School Year &amp; Semester</em>) before importing.</span>
                            <?php endif; ?>
                            <br><em class="text-muted">Teacher lookups, roster matching, and subject assignments all use this active period automatically. No manual SY/semester selection needed or allowed. If the active period changes, the import will use the latest system value.</em>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="import_subject_file">Excel file * (.xlsx or .xls)</label>
                            <input type="file" name="import_subject_file" id="import_subject_file" class="form-control form-control-custom" accept=".xlsx,.xls" required>
                        </div>

                        <div class="alert alert-info small mb-0" role="alert">
                            <strong>Sheet layout:</strong> Row 1 = headers. Required columns:
                            <strong>Subject Name</strong>, <strong>Year Level</strong> (11 or 12),
                            <strong>Strand</strong> (ABM / HUMSS / STEM / ICT or full name).
                            Optional: <strong>Semester</strong> (1 or 2). If omitted, the current active semester is used.
                            <br><strong style="color: #28a745;"><i class="fas fa-check me-1"></i>School year is automatically detected from the current system setting</strong> (<?php echo htmlspecialchars($current_ctx['school_year']); ?>) — do not include in the file or form. All imported subjects will be assigned to this school year automatically, ensuring consistency.
                            Duplicate curriculum rows (same subject, level, strand, semester) keep the first only.
                            Imported subjects start with <strong>no assigned teachers</strong>. Assign teachers manually after import using Edit Subject.
                        </div>

                        <div class="text-end mt-4">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-check me-2"></i>Import and replace active set
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div class="modal fade" id="editSubjectModal" tabindex="-1" aria-hidden="true" inert>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Subject
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editSubjectForm">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="subject_id" id="edit_subject_id">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="edit_subject_name_display" class="form-label">Subject Name *</label>
                                <input type="text" class="form-control form-control-custom" id="edit_subject_name_display" 
                                       readonly style="background-color: white; color: #495057; border-color: #dee2e6;">
                                <input type="hidden" id="edit_subject_name" name="subject_name">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_year_level" class="form-label">Year Level *</label>
                                <select class="form-control form-control-custom" id="edit_year_level" 
                                        name="year_level" required>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_strand" class="form-label">Strand *</label>
                                <select class="form-control form-control-custom" id="edit_strand" 
                                        name="strand" required>
                                    <option value="Accountancy, Business, and Management">Accountancy, Business, and Management</option>
                                    <option value="Humanities and Social Sciences">Humanities and Social Sciences</option>
                                    <option value="Science, Technology, Engineering, Mathematics">Science, Technology, Engineering, Mathematics</option>
                                    <option value="Information and Communication Technology">Information and Communication Technology</option>
                                </select>
                            </div>
                        </div>
                        <!-- Multi-teacher assignment (current SY/Sem) -->
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-users me-2" style="color: var(--primary-color);"></i>Assigned Teachers
                                <span style="font-size: 0.85rem; color: #6c757d;">(<?php echo htmlspecialchars($current_ctx['school_year']); ?>, <?php echo htmlspecialchars($current_ctx['semester_text']); ?>)</span>
                            </label>
                            <div class="teacher-select-info">
                                <i class="fas fa-info-circle me-2"></i>Select one or more teachers. The first selected will be the primary teacher.
                            </div>
                            <div class="teacher-select-list" id="editTeacherList" style="margin-top: 12px;">
                                <?php if (count($teachers) > 0): ?>
                                    <?php foreach ($teachers as $index => $teacher): ?>
                                        <div class="teacher-select-item" data-strand="<?php echo htmlspecialchars($teacher['strand']); ?>" data-teacher-id="<?php echo (int)$teacher['teacher_id']; ?>">
                                            <input type="checkbox" id="edit_teacher_<?php echo $teacher['teacher_id']; ?>" 
                                                   name="teacher_ids[]" value="<?php echo (int)$teacher['teacher_id']; ?>">
                                            <label for="edit_teacher_<?php echo $teacher['teacher_id']; ?>">
                                                <strong><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></strong>
                                                <br>
                                                <small style="color: #6c757d;"><?php echo htmlspecialchars($teacher['strand']); ?></small>
                                            </label>
                                            <span class="teacher-select-role-badge primary" data-role="primary" style="display: none;">Primary Teacher</span>
                                            <span class="teacher-select-role-badge assistant" data-role="assistant" style="display: none; background: #e8f4f8; color: #0c5460; border-color: #0c5460;">Assistant Teacher</span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="padding: 20px; text-align: center; color: #6c757d;">
                                        <i class="fas fa-info-circle me-2"></i>No teachers available for this term
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Update Subject
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Archived Subjects Export Modal -->
    <div class="modal fade" id="archiveExportModal" tabindex="-1" aria-hidden="true" inert>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-download me-2"></i>Export Archived Subjects
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        <i class="fas fa-info-circle me-2"></i>Export will include all archived subjects matching your current filters (year level, strand, semester, search term).
                    </p>
                    <form method="POST" id="archiveExportForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                        <input type="hidden" name="action" value="export_archived_subjects">
                        
                        <div class="mb-3">
                            <label for="archiveExportSchoolYear" class="form-label">School Year *</label>
                            <select class="form-control form-control-custom" id="archiveExportSchoolYear" 
                                    name="export_school_year" required>
                                <?php foreach ($archive_school_year_options as $optSy): ?>
                                    <option value="<?php echo htmlspecialchars($optSy); ?>"
                                        <?php echo ($optSy === $archive_context_sy) ? 'selected' : ''; ?>><?php echo htmlspecialchars($optSy); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="archiveExportSemester" class="form-label">Semester</label>
                            <select class="form-control form-control-custom" id="archiveExportSemester" 
                                    name="export_semester">
                                <option value="">All Semesters</option>
                                <option value="1">1st Semester</option>
                                <option value="2">2nd Semester</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="archiveExportYearLevel" class="form-label">Year Level</label>
                            <select class="form-control form-control-custom" id="archiveExportYearLevel" 
                                    name="export_year_level">
                                <option value="">All Year Levels</option>
                                <option value="11">Grade 11</option>
                                <option value="12">Grade 12</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="archiveExportStrand" class="form-label">Strand</label>
                            <select class="form-control form-control-custom" id="archiveExportStrand" 
                                    name="export_strand">
                                <option value="">All Strands</option>
                                <option value="Accountancy, Business, and Management">ABM</option>
                                <option value="Humanities and Social Sciences">HUMSS</option>
                                <option value="Science, Technology, Engineering, Mathematics">STEM</option>
                                <option value="Information and Communication Technology">ICT</option>
                            </select>
                        </div>

                        <div class="text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-download me-2"></i>Export to Excel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Curriculum Wizard - Step 1: Basic Information -->
    <div class="modal fade" id="createCurriculumStep1Modal" tabindex="-1" aria-hidden="true" inert>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-book me-2"></i>Create Curriculum - Step 1 of 6: Basic Information
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small mb-3" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Curriculum Rollover:</strong> Creating a new curriculum will archive all currently active subjects and set the new ones as active. You'll configure subjects for each strand separately.
                    </div>

                    <div id="curriculumStep1Form">
                        <div class="mb-4">
                            <h6 class="fw-6 mb-3"><i class="fas fa-cog me-2"></i>Curriculum Configuration</h6>
                            
                            <div class="mb-3">
                                <label for="curriculumLabel" class="form-label">Curriculum Label (Optional)</label>
                                <input type="text" class="form-control form-control-custom" id="curriculumLabel" 
                                       name="curriculum_label" maxlength="100" 
                                       placeholder="e.g., 2025-2026 Curriculum">
                                <div class="form-text">A descriptive name to help identify this curriculum in logs.</div>
                            </div>

                            <div class="alert alert-secondary small mb-0" role="alert">
                                <i class="fas fa-calendar-check me-2"></i>
                                <strong>Active Period:</strong> School Year <strong><?php echo htmlspecialchars($current_ctx['school_year']); ?></strong> · <strong><?php echo htmlspecialchars($current_ctx['semester_text']); ?></strong> (Auto-populated, not editable)
                            </div>
                        </div>
                    </div>

                    <div class="text-end gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary-custom" onclick="curriculumWizardNextStep(1)">
                            <i class="fas fa-arrow-right me-2"></i>Next: Configure Strands
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Curriculum Wizard - Step 2: ABM Strand -->
    <div class="modal fade" id="createCurriculumStrandModal" tabindex="-1" aria-hidden="true" inert data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-layer-group me-2"></i><span id="strandModalTitle">Create Curriculum - Step 2 of 6: ABM Strand</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Grade Level Tabs -->
                    <ul class="nav nav-tabs mb-4" id="strandGradeLevelTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="grade11-tab" data-bs-toggle="tab" data-bs-target="#grade11-content" type="button" role="tab" aria-controls="grade11-content" aria-selected="true">
                                <i class="fas fa-graduation-cap me-2"></i>Grade 11
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="grade12-tab" data-bs-toggle="tab" data-bs-target="#grade12-content" type="button" role="tab" aria-controls="grade12-content" aria-selected="false">
                                <i class="fas fa-graduation-cap me-2"></i>Grade 12
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="strandGradeLevelTabContent">
                        <!-- Grade 11 Content -->
                        <div class="tab-pane fade show active" id="grade11-content" role="tabpanel" aria-labelledby="grade11-tab">
                            <div class="row">
                                <!-- Manual Subject Entry -->
                                <div class="col-lg-6">
                                    <div class="curriculum-tools-card">
                                        <h6 class="fw-6 mb-3"><i class="fas fa-plus-circle me-2 text-primary"></i>Add New Subjects Manually</h6>
                                        <div id="manualSubjectsList_G11" style="min-height: 300px; max-height: 400px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 8px; padding: 10px; margin-bottom: 15px;">
                                            <!-- Manual subjects will be inserted here -->
                                        </div>
                                        <button type="button" class="btn btn-outline-primary btn-sm w-100" 
                                                onclick="curriculumAddManualSubjectRow('G11')">
                                            <i class="fas fa-plus me-2"></i>Add Subject Field
                                        </button>
                                    </div>
                                </div>

                                <!-- Reuse Existing Subjects -->
                                <div class="col-lg-6">
                                    <div class="curriculum-tools-card">
                                        <h6 class="fw-6 mb-3" id="reuseLabel_G11"><i class="fas fa-recycle me-2 text-success"></i>Reuse from Past Curricula – Grade 11</h6>
                                        <div id="reuseSubjectsList_G11" style="min-height: 300px; max-height: 400px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 8px; padding: 10px; margin-bottom: 15px;">
                                            <div class="text-muted small text-center" style="padding: 20px;">
                                                <i class="fas fa-spinner fa-spin me-2"></i>Loading archived subjects...
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Reuse Filter Options (Hidden by default) -->
                            <div id="reuseFilterOptions_G11" style="display: none; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
                                <p class="text-muted small"><i class="fas fa-info-circle me-2"></i>Showing past subjects for the current strand only.</p>
                            </div>
                        </div>

                        <!-- Grade 12 Content -->
                        <div class="tab-pane fade" id="grade12-content" role="tabpanel" aria-labelledby="grade12-tab">
                            <div class="row">
                                <!-- Manual Subject Entry -->
                                <div class="col-lg-6">
                                    <div class="curriculum-tools-card">
                                        <h6 class="fw-6 mb-3"><i class="fas fa-plus-circle me-2 text-primary"></i>Add New Subjects Manually</h6>
                                        <div id="manualSubjectsList_G12" style="min-height: 300px; max-height: 400px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 8px; padding: 10px; margin-bottom: 15px;">
                                            <!-- Manual subjects will be inserted here -->
                                        </div>
                                        <button type="button" class="btn btn-outline-primary btn-sm w-100" 
                                                onclick="curriculumAddManualSubjectRow('G12')">
                                            <i class="fas fa-plus me-2"></i>Add Subject Field
                                        </button>
                                    </div>
                                </div>

                                <!-- Reuse Existing Subjects -->
                                <div class="col-lg-6">
                                    <div class="curriculum-tools-card">
                                        <h6 class="fw-6 mb-3" id="reuseLabel_G12"><i class="fas fa-recycle me-2 text-success"></i>Reuse from Past Curricula – Grade 12</h6>
                                        <div id="reuseSubjectsList_G12" style="min-height: 300px; max-height: 400px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 8px; padding: 10px; margin-bottom: 15px;">
                                            <div class="text-muted small text-center" style="padding: 20px;">
                                                <i class="fas fa-spinner fa-spin me-2"></i>Loading archived subjects...
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Reuse Filter Options (Hidden by default) -->
                            <div id="reuseFilterOptions_G12" style="display: none; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
                                <p class="text-muted small"><i class="fas fa-info-circle me-2"></i>Showing past subjects for the current strand only.</p>
                            </div>
                        </div>
                    </div>

                    <div class="text-end gap-2 mt-4">
                        <button type="button" class="btn btn-secondary" onclick="curriculumWizardPreviousStrand()">
                            <i class="fas fa-arrow-left me-2"></i>Back
                        </button>
                        <button type="button" class="btn btn-primary-custom" onclick="curriculumWizardNextStrand()">
                            <i class="fas fa-arrow-right me-2"></i>Next
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Curriculum Wizard - Step 6: Review & Save -->
    <div class="modal fade" id="createCurriculumStep3Modal" tabindex="-1" aria-hidden="true" inert data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>Create Curriculum - Step 6 of 6: Review & Save
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small mb-3" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important:</strong> This will archive all active subjects and activate the new curriculum below.
                    </div>

                    <div id="curriculumReviewSummary" style="max-height: 500px; overflow-y: auto; margin-bottom: 20px;">
                        <!-- Review summary will be populated here -->
                    </div>

                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="createCurriculumFinalForm">
                        <input type="hidden" name="action" value="create_curriculum">
                        <input type="hidden" name="curriculum_all_strands" id="finalAllStrands" value="1">
                        <input type="hidden" name="curriculum_label" id="finalLabel">
                        
                        <!-- Subjects by strand and grade level will be added dynamically -->
                        <div id="finalSubjectsFields"></div>

                        <div class="text-end gap-2">
                            <button type="button" class="btn btn-secondary" onclick="curriculumWizardPreviousStrand()">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check me-2"></i>Create Curriculum
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>



    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="archiveSubjectForm" style="display: none;">
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="subject_id" id="archive_subject_id">
    </form>
    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="restoreSubjectForm" style="display: none;">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="subject_id" id="restore_subject_id">
    </form>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Dynamic Dropdowns JS -->
    <script src="/capstone/js/dynamic-dropdowns.js"></script>
    
    <script>
        // Debug logging for form submissions
        document.addEventListener('DOMContentLoaded', function() {
            const archiveForm = document.getElementById('archiveSubjectForm');
            if (archiveForm) {
                archiveForm.addEventListener('submit', function(e) {
                    console.log('📤 archiveSubjectForm being submitted');
                    console.log('Form action:', this.action);
                    console.log('Form method:', this.method);
                    console.log('Subject ID:', this.querySelector('input[name="subject_id"]').value);
                    console.log('Action:', this.querySelector('input[name="action"]').value);
                });
            }

            // ============ INERT ATTRIBUTE MANAGEMENT FOR MODALS ============
            // Remove inert when modal is shown, restore when hidden (fixes aria-hidden focus issue)
            const modalIds = [
                'addSubjectModal',
                'editSubjectModal',
                'importSubjectSetModal',
                'archiveExportModal',
                'createCurriculumStep1Modal',
                'createCurriculumStrandModal',
                'createCurriculumStep3Modal'
            ];

            modalIds.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.addEventListener('show.bs.modal', function() {
                        this.removeAttribute('inert');
                    });
                    modal.addEventListener('hide.bs.modal', function() {
                        this.setAttribute('inert', '');
                    });
                }
            });
        });
        
        // Current active semester from system context
        const CURRENT_ACTIVE_SEMESTER = <?php echo (int)$current_ctx['semester']; ?>;
        const SUBJECT_CATALOG_BY_STRAND = <?php echo json_encode($subject_catalog_by_strand); ?>;
        
        // ============ TEACHER BADGE UPDATES (GLOBAL) ============
        const updatePrimaryTeacherBadges = (modalSelector) => {
            const checkboxes = document.querySelectorAll(modalSelector + ' input[name="teacher_ids[]"]');
            let firstCheckedFound = false;
            
            checkboxes.forEach((checkbox, index) => {
                const item = checkbox.closest('.teacher-select-item');
                if (!item) return;
                const primaryBadge = item.querySelector('.teacher-select-role-badge[data-role="primary"]');
                const assistantBadge = item.querySelector('.teacher-select-role-badge[data-role="assistant"]');
                
                if (checkbox.checked) {
                    // Check if this teacher has an assigned role from the database
                    const assignedRole = checkbox.getAttribute('data-assigned-role');
                    
                    if (assignedRole) {
                        // Show role based on database information
                        if (assignedRole === 'primary') {
                            if (primaryBadge) primaryBadge.style.display = 'inline-block';
                            if (assistantBadge) assistantBadge.style.display = 'none';
                        } else {
                            if (primaryBadge) primaryBadge.style.display = 'none';
                            if (assistantBadge) assistantBadge.style.display = 'inline-block';
                        }
                    } else {
                        // New selection - first checked becomes primary
                        if (!firstCheckedFound) {
                            if (primaryBadge) primaryBadge.style.display = 'inline-block';
                            if (assistantBadge) assistantBadge.style.display = 'none';
                            firstCheckedFound = true;
                        } else {
                            if (primaryBadge) primaryBadge.style.display = 'none';
                            if (assistantBadge) assistantBadge.style.display = 'inline-block';
                        }
                    }
                } else {
                    // Hide badges when unchecked
                    if (primaryBadge) primaryBadge.style.display = 'none';
                    if (assistantBadge) assistantBadge.style.display = 'none';
                }
            });
        };
        
        // ============ STRAND-BASED TEACHER FILTERING ============
        function filterTeachersByStrand(strandSelectId, teacherListId) {
            const strandSelect = document.getElementById(strandSelectId);
            const teacherList = document.getElementById(teacherListId);
            
            if (!strandSelect || !teacherList) return;
            
            const selectedStrand = strandSelect.value;
            const teacherItems = teacherList.querySelectorAll('.teacher-select-item');
            let visibleCount = 0;
            
            teacherItems.forEach(item => {
                const itemStrand = item.getAttribute('data-strand');
                const checkbox = item.querySelector('input[type="checkbox"]');
                
                if (selectedStrand === '' || itemStrand === selectedStrand) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                    // Uncheck if hidden
                    if (checkbox) checkbox.checked = false;
                }
            });
            
            updatePrimaryTeacherBadges('#editSubjectModal');
        }
        
        <?php if (isset($_SESSION['success'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showModernAlert('success', 'Success', <?php echo json_encode($_SESSION['success']); ?>, { autoCloseMs: 3000 });
            });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showModernAlert('error', 'Error', <?php echo json_encode($_SESSION['error']); ?>);
            });
        <?php unset($_SESSION['error']); endif; ?>

        <?php if ($is_evaluation_ongoing): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showModernAlert(
                    'warning',
                    'Evaluation Period Active',
                    <?php echo json_encode("Subject management actions (Add Subject, Import subject set, Edit) are disabled while the evaluation is ongoing. The evaluation period ends on " . date('M d, Y g:i A', strtotime($evaluation_end_date)) . "."); ?>
                );
            });
        <?php endif; ?>

        function filterSubjects() {
            const activeTab = document.querySelector('.tab-content.active');
            if (!activeTab || activeTab.id === 'curriculum') {
                return;
            }

            const searchTerm = document.getElementById('searchSubject').value.toLowerCase();
            const semesterFilter = document.getElementById('filterSemester').value;
            const yearLevelFilter = document.getElementById('filterYearLevel').value;
            const strandFilter = document.getElementById('filterStrand').value;

            const rows = activeTab.querySelectorAll('.subject-row');
            
            let visibleCount = 0;
            
            rows.forEach(row => {
                const subjectName = row.getAttribute('data-subject-name');
                const semesterData = row.getAttribute('data-semester') || '';
                const yearLevel = row.getAttribute('data-year-level');
                const strand = row.getAttribute('data-strand');
                
                // Check if row matches search term
                const matchesSearch = subjectName.includes(searchTerm);
                
                // Check if row matches semester filter (direct comparison since we get single value from subjects table)
                const matchesSemester = !semesterFilter || semesterData === semesterFilter;
                
                // Check if row matches year level filter
                const matchesYearLevel = !yearLevelFilter || yearLevel === yearLevelFilter;
                
                // Check if row matches strand filter
                const matchesStrand = !strandFilter || strand === strandFilter;
                
                // Show row if all conditions match
                if (matchesSearch && matchesSemester && matchesYearLevel && matchesStrand) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show/hide no results message (only for active tab, not archived)
            const activeTabId = activeTab.id;
            if (activeTabId === 'active') {
                const noResultsElement = document.getElementById(activeTabId + 'NoResults');
                const tableElement = document.getElementById(activeTabId + 'SubjectsTable');
                
                if (visibleCount === 0) {
                    if (noResultsElement) noResultsElement.style.display = 'block';
                    if (tableElement) tableElement.style.display = 'none';
                } else {
                    if (noResultsElement) noResultsElement.style.display = 'none';
                    if (tableElement) tableElement.style.display = '';
                }
            }
        }
        
        function subjectManagementReloadArchiveContext() {
            const sySel = document.getElementById('filterArchiveSchoolYear');
            if (!sySel) {
                console.error('Archive school year dropdown not found');
                return;
            }
            
            const selectedYear = sySel.value;
            if (!selectedYear || selectedYear.trim() === '') {
                showModernAlert('warning', 'Selection Required', 'Please select an archived school year to view subjects.');
                return;
            }
            
            // Update URL with selected school year and reload
            const url = new URL(window.location.href);
            url.searchParams.set('archive_sy', selectedYear);
            
            // Add visual feedback while loading
            sySel.disabled = true;
            const originalText = sySel.parentElement.querySelector('.export-btn') ? sySel.parentElement.querySelector('.export-btn').textContent : '';
            
            // Reload the page to apply the filter
            window.location.href = url.toString();
        }

        function exportArchivedSubjectsExcel() {
            // Populate the export form with current filter values
            const schoolYearSel = document.getElementById('filterArchiveSchoolYear');
            const semesterSel = document.getElementById('filterSemester');
            const yearLevelSel = document.getElementById('filterYearLevel');
            const strandSel = document.getElementById('filterStrand');
            const searchInput = document.getElementById('searchSubject');

            // Set form values
            if (schoolYearSel) {
                document.getElementById('archiveExportSchoolYear').value = schoolYearSel.value;
            }
            if (semesterSel) {
                document.getElementById('archiveExportSemester').value = semesterSel.value;
            }
            if (yearLevelSel) {
                document.getElementById('archiveExportYearLevel').value = yearLevelSel.value;
            }
            if (strandSel) {
                document.getElementById('archiveExportStrand').value = strandSel.value;
            }
            if (searchInput) {
                document.getElementById('archiveExportSearch').value = searchInput.value;
            }
        }

        function switchTab(tabName, clickedButton) {
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.classList.remove('active');
            });
            
            document.getElementById(tabName).classList.add('active');
            if (clickedButton) {
                clickedButton.classList.add('active');
            }

            const toolbar = document.getElementById('subjectToolbarRow');
            if (toolbar) {
                toolbar.style.display = (tabName === 'active' || tabName === 'archived') ? 'flex' : 'none';
            }
            const addSubjectBtnWrap = document.getElementById('addSubjectBtnWrap');
            if (addSubjectBtnWrap) {
                addSubjectBtnWrap.style.display = tabName === 'active' ? 'inline-flex' : 'none';
            }
            const archiveBar = document.getElementById('archiveContextBar');
            if (archiveBar) {
                archiveBar.style.display = tabName === 'archived' ? 'flex' : 'none';
            }

            // Apply tab-specific semester filter defaults
            const filterSemesterSelect = document.getElementById('filterSemester');
            if (filterSemesterSelect) {
                if (tabName === 'active') {
                    // For Active Subjects, default to current active semester
                    filterSemesterSelect.value = CURRENT_ACTIVE_SEMESTER;
                } else if (tabName === 'archived') {
                    // For Archived Subjects, default to All Semesters
                    filterSemesterSelect.value = '';
                }
            }

            filterSubjects();
        }
        
        function editSubject(subject) {
            document.getElementById('edit_subject_id').value = subject.subject_id;
            
            // Wait for dropdown to be fully initialized with options
            const yearLevelSelect = document.getElementById('edit_year_level');
            const strandSelect = document.getElementById('edit_strand');
            const subjectNameDisplay = document.getElementById('edit_subject_name_display');
            const subjectNameHidden = document.getElementById('edit_subject_name');
            
            // Get subject name from the table row's data attribute or subject object
            let subjectName = subject.display_subject_name || subject.subject_name || subject.subject_id;
            
            // Alternative: try to get it from the table cell if not available
            if (!subject.subject_name || subject.subject_name === subject.subject_id) {
                // Find the row that was clicked and extract subject name from the cell
                const activeTab = document.querySelector('.tab-content.active');
                const rows = activeTab.querySelectorAll('.subject-row');
                rows.forEach(row => {
                    // Check if this row matches the subject we're editing
                    const cells = row.querySelectorAll('td');
                    if (cells.length > 0 && cells[0].textContent.trim() === String(subject.subject_id)) {
                        // Get the subject name from the second column (index 1)
                        if (cells[1]) {
                            subjectName = cells[1].textContent.trim();
                        }
                    }
                });
            }
            
            // Set values
            yearLevelSelect.value = subject.year_level;
            strandSelect.value = subject.strand;
            subjectNameDisplay.value = subjectName;
            subjectNameHidden.value = subjectName;  // Store the actual subject name, not the ID
            
            // Disable the fields - do this after setting values
            setTimeout(() => {
                yearLevelSelect.disabled = true;
                strandSelect.disabled = true;
            }, 50);

            // Preselect assigned teachers and store their roles
            const teacherCheckboxes = document.querySelectorAll('#editSubjectModal input[name="teacher_ids[]"]');
            const assignedTeachersMap = {};
            if (subject.assigned_teachers) {
                subject.assigned_teachers.forEach(t => {
                    assignedTeachersMap[String(t.teacher_id)] = t.role || 'assistant';
                });
            }
            
            teacherCheckboxes.forEach(checkbox => {
                const teacherId = checkbox.value;
                const isAssigned = teacherId in assignedTeachersMap;
                checkbox.checked = isAssigned;
                
                if (isAssigned) {
                    // Store the role in a data attribute on the checkbox for updatePrimaryTeacherBadges to access
                    checkbox.setAttribute('data-assigned-role', assignedTeachersMap[teacherId]);
                }
            });
            
            // Filter teachers by the selected strand
            filterTeachersByStrand('edit_strand', 'editTeacherList');
            
            // Update badges to show actual roles from database
            updatePrimaryTeacherBadges('#editSubjectModal');
        }
        
        function archiveSubject(subjectId, subjectName) {
            console.log('🔹 Archive button clicked:', { subjectId, subjectName });
            
            const subjectIdField = document.getElementById('archive_subject_id');
            if (!subjectIdField) {
                console.error('❌ archive_subject_id field not found!');
                showModernAlert('error', 'Error', 'Archive form element not found');
                return;
            }
            
            subjectIdField.value = subjectId;
            console.log('✓ Subject ID set in hidden field:', subjectIdField.value);
            
            showModernConfirm(
                'Archive Subject',
                `Archive "${subjectName}"? You can restore it later from archived subjects.`,
                function() {
                    console.log('🔹 User confirmed archive action');
                    const form = document.getElementById('archiveSubjectForm');
                    if (!form) {
                        console.error('❌ archiveSubjectForm not found!');
                        showModernAlert('error', 'Error', 'Archive form not found');
                        return;
                    }
                    console.log('✓ Form found, submitting...');
                    console.log('Form data:', new FormData(form));
                    form.submit();
                },
                { confirmText: 'Archive' }
            );
        }

        function restoreSubject(subjectId, subjectName) {
            console.log('🔹 Restore button clicked:', { subjectId, subjectName });
            
            const subjectIdField = document.getElementById('restore_subject_id');
            if (!subjectIdField) {
                console.error('❌ restore_subject_id field not found!');
                showModernAlert('error', 'Error', 'Restore form element not found');
                return;
            }
            
            subjectIdField.value = subjectId;
            console.log('✓ Subject ID set in hidden field:', subjectIdField.value);
            
            showModernConfirm(
                'Restore Subject',
                `Restore "${subjectName}" to active subjects?`,
                function() {
                    console.log('🔹 User confirmed restore action');
                    const form = document.getElementById('restoreSubjectForm');
                    if (!form) {
                        console.error('❌ restoreSubjectForm not found!');
                        showModernAlert('error', 'Error', 'Restore form not found');
                        return;
                    }
                    console.log('✓ Form found, submitting...');
                    console.log('Form data:', new FormData(form));
                    form.submit();
                },
                { confirmText: 'Restore' }
            );
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('.table-custom tbody tr');
            tableRows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    row.style.transition = 'all 0.6s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 50);
            });

            // Handle teacher selection for modals
            const handleTeacherSelection = (modalSelector) => {
                const checkboxes = document.querySelectorAll(modalSelector + ' input[name="teacher_ids[]"]');
                checkboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        // Clear any assigned-role data attribute when user manually changes selection
                        // so new selections follow the "first checked = primary" rule
                        if (!this.checked) {
                            this.removeAttribute('data-assigned-role');
                        }
                        updatePrimaryTeacherBadges(modalSelector);
                    });
                });
            };

            handleTeacherSelection('#editSubjectModal');

            const editStrandSelect = document.getElementById('edit_strand');
            if (editStrandSelect) {
                editStrandSelect.addEventListener('change', function() {
                    filterTeachersByStrand('edit_strand', 'editTeacherList');
                });
            }

            // Delegated click handler supports current and dynamically injected rows
            document.addEventListener('click', function(event) {
                const archiveBtn = event.target.closest('.archive-subject-btn');
                if (archiveBtn) {
                    event.preventDefault();
                    const subjectId = parseInt(archiveBtn.getAttribute('data-subject-id'), 10);
                    const subjectName = archiveBtn.getAttribute('data-subject-name') || 'Selected subject';

                    console.log('🔹 Archive delegated click captured:', {
                        subjectId: subjectId,
                        subjectName: subjectName
                    });

                    if (!subjectId || Number.isNaN(subjectId)) {
                        console.error('❌ Invalid subject id on archive button');
                        showModernAlert('error', 'Error', 'Invalid subject selected for archive.');
                        return;
                    }

                    archiveSubject(subjectId, subjectName);
                    return;
                }

                // Delegated click handler for restore buttons
                const restoreBtn = event.target.closest('.restore-subject-btn');
                if (restoreBtn) {
                    event.preventDefault();
                    
                    // Check if button is disabled (can't restore from non-current school year)
                    if (restoreBtn.hasAttribute('disabled') || restoreBtn.disabled) {
                        console.warn('⚠️ Restore button is disabled - cannot restore this subject');
                        showModernAlert('warning', 'Cannot Restore', restoreBtn.title || 'This subject cannot be restored.');
                        return;
                    }
                    
                    const subjectId = parseInt(restoreBtn.getAttribute('data-subject-id'), 10);
                    const subjectName = restoreBtn.getAttribute('data-subject-name') || 'Selected subject';

                    console.log('🔹 Restore delegated click captured:', {
                        subjectId: subjectId,
                        subjectName: subjectName
                    });

                    if (!subjectId || Number.isNaN(subjectId)) {
                        console.error('❌ Invalid subject id on restore button');
                        showModernAlert('error', 'Error', 'Invalid subject selected for restore.');
                        return;
                    }

                    restoreSubject(subjectId, subjectName);
                    return;
                }
            });
        });

        // Populate export form when modal is shown
        const archiveExportModal = document.getElementById('archiveExportModal');
        if (archiveExportModal) {
            archiveExportModal.addEventListener('show.bs.modal', function() {
                exportArchivedSubjectsExcel();
            });
        }

        // Validate export form submission
        const archiveExportForm = document.getElementById('archiveExportForm');
        if (archiveExportForm) {
            archiveExportForm.addEventListener('submit', function(e) {
                const schoolYearField = document.getElementById('archiveExportSchoolYear');
                if (!schoolYearField || !schoolYearField.value.trim()) {
                    e.preventDefault();
                    showModernAlert('warning', 'Validation Required', 'Please select a School Year for export.');
                    return false;
                }
            });
        }

        // Initialize dynamic dropdown system for Edit Modal
        let editSubjectDropdown;
        const editSubjectModal = document.getElementById('editSubjectModal');
        if (editSubjectModal) {
            editSubjectModal.addEventListener('show.bs.modal', function() {
                setTimeout(() => {
                    if (!editSubjectDropdown || !editSubjectDropdown.isInitialized) {
                        editSubjectDropdown = new DynamicDropdownSystem({
                            apiUrl: '/capstone/api/get_subjects_data.php',
                            yearLevelSelectId: 'edit_year_level',
                            strandSelectId: 'edit_strand',
                            subjectSelectId: 'edit_subject_name'
                        });
                    }
                }, 100);
            });
        }

        // Re-enable edit modal fields when modal is closed
        if (editSubjectModal) {
            editSubjectModal.addEventListener('hidden.bs.modal', function() {
                const editYearLevel = document.getElementById('edit_year_level');
                const editStrand = document.getElementById('edit_strand');
                if (editYearLevel) editYearLevel.disabled = false;
                if (editStrand) editStrand.disabled = false;
            });
        }

        document.getElementById('importSubjectSetForm').addEventListener('submit', function(e) {
            const fileInput = document.getElementById('import_subject_file');

            if (!fileInput.files || fileInput.files.length === 0) {
                e.preventDefault();
                showModernAlert('warning', 'Validation Required', 'Please choose an Excel file to upload.');
                return false;
            }

            if (!confirm('Archive every active subject and replace them with rows from this file? Cancel to go back.')) {
                e.preventDefault();
                return false;
            }
        });

        document.getElementById('addSubjectForm').addEventListener('submit', function(e) {
            const subjectNameInput = document.getElementById('add_subject_name');
            const strandInput = document.getElementById('add_strand');
            const yearLevelInput = document.getElementById('add_year_level');
            const semesterInput = document.getElementById('add_semester');

            subjectNameInput.value = subjectNameInput.value.trim().replace(/\s+/g, ' ');
            if (subjectNameInput.value.length < 3 || subjectNameInput.value.length > 120) {
                e.preventDefault();
                showModernAlert('warning', 'Validation Required', 'Subject Name must be between 3 and 120 characters.');
                return false;
            }
            if (!strandInput.value || !yearLevelInput.value || !semesterInput.value) {
                e.preventDefault();
                showModernAlert('warning', 'Validation Required', 'Strand, Subject Name, Year Level, and Semester are required.');
                return false;
            }
            const normalizedInput = subjectNameInput.value.trim().replace(/\s+/g, ' ').toLowerCase();
            const selectedStrand = strandInput.value;
            // Check against subjects in the SELECTED STRAND only
            const strandSubjects = SUBJECT_CATALOG_BY_STRAND[selectedStrand] || [];
            const hasDuplicate = strandSubjects.some((v) => String(v).trim().replace(/\s+/g, ' ').toLowerCase() === normalizedInput);
            if (hasDuplicate) {
                e.preventDefault();
                showModernAlert('warning', 'Duplicate Subject', 'Subject already exists in the ' + selectedStrand + ' strand.');
                return false;
            }
        });

        const addSubjectNameInput = document.getElementById('add_subject_name');
        const addSubjectCatalogList = document.getElementById('subjectCatalogList');
        const addSubjectNameHint = document.getElementById('addSubjectNameHint');
        const addStrandSelect = document.getElementById('add_strand');
        if (addSubjectNameInput && addSubjectNameHint && addSubjectCatalogList && addStrandSelect) {
            const normalizeText = (value) => String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();

            // Build catalog from SELECTED strand only
            const buildStrandSubjectsCatalog = () => {
                const selectedStrand = addStrandSelect.value;
                const strandSubjects = selectedStrand ? (SUBJECT_CATALOG_BY_STRAND[selectedStrand] || []) : [];
                
                addSubjectCatalogList.innerHTML = '';
                strandSubjects.forEach((name) => {
                    const optionEl = document.createElement('option');
                    optionEl.value = name;
                    addSubjectCatalogList.appendChild(optionEl);
                });

                return new Set(strandSubjects.map(normalizeText));
            };

            let normalizedCatalog = buildStrandSubjectsCatalog();

            const updateAddSubjectHint = () => {
                const normalized = normalizeText(addSubjectNameInput.value);
                if (!normalized) {
                    addSubjectNameHint.textContent = '';
                    addSubjectNameHint.className = 'ms-1 text-muted';
                    return;
                }
                if (normalizedCatalog.has(normalized)) {
                    const selectedStrand = addStrandSelect.value;
                    addSubjectNameHint.textContent = 'Existing subject in ' + selectedStrand;
                    addSubjectNameHint.className = 'ms-1 text-success';
                } else {
                    addSubjectNameHint.textContent = 'New subject name';
                    addSubjectNameHint.className = 'ms-1 text-primary';
                }
            };

            // Update catalog when strand changes
            addStrandSelect.addEventListener('change', function() {
                normalizedCatalog = buildStrandSubjectsCatalog();
                updateAddSubjectHint();
            });

            addSubjectNameInput.addEventListener('input', updateAddSubjectHint);
            document.getElementById('addSubjectModal').addEventListener('shown.bs.modal', function() {
                normalizedCatalog = buildStrandSubjectsCatalog();
                updateAddSubjectHint();
            });
            document.getElementById('addSubjectModal').addEventListener('hidden.bs.modal', function() {
                normalizedCatalog = buildStrandSubjectsCatalog();
                addSubjectNameHint.textContent = '';
                addSubjectNameHint.className = 'ms-1 text-muted';
            });
        }

        // Handle edit form submission - re-enable fields before submit so they're included in POST data
        document.getElementById('editSubjectForm').addEventListener('submit', function(e) {
            const subjectNameField = document.getElementById('edit_subject_name');
            const subjectNameDisplay = document.getElementById('edit_subject_name_display');
            const yearLevel = document.getElementById('edit_year_level').value;
            const strand = document.getElementById('edit_strand').value;
            
            // Ensure the hidden field has the display value (actual subject name) not the ID
            if (subjectNameField.value === '' || !isNaN(subjectNameField.value)) {
                // If empty or only contains a number, use the display value
                subjectNameField.value = subjectNameDisplay.value;
            }
            
            if (!subjectNameField.value || !yearLevel || !strand) {
                e.preventDefault();
                showModernAlert('warning', 'Validation Required', 'Subject Name, Year Level, and Strand are required.');
                return false;
            }

            // Temporarily enable fields for form submission
            document.getElementById('edit_year_level').disabled = false;
            document.getElementById('edit_strand').disabled = false;
        });

        // Handle create curriculum form submission - validate before allowing submit
        document.getElementById('createCurriculumFinalForm').addEventListener('submit', function(e) {
            // Use the centralized validation function
            if (!validateCurriculumRequirements()) {
                e.preventDefault();
                return false;
            }
        });

        // ============ CREATE CURRICULUM WIZARD - STRAND-BASED FLOW ============
        
        // Strands in order
        const CURRICULUM_STRANDS = [
            { id: 'ABM', name: 'Accountancy, Business, and Management', stepNum: 2 },
            { id: 'HUMSS', name: 'Humanities and Social Sciences', stepNum: 3 },
            { id: 'STEM', name: 'Science, Technology, Engineering, Mathematics', stepNum: 4 },
            { id: 'ICT', name: 'Information and Communication Technology', stepNum: 5 }
        ];
        
        // Global state for the curriculum wizard
        const curriculumWizardState = {
            label: '',
            allStrands: true,
            currentStrandIndex: 0, // Which strand we're on (0-3)
            // Store data for each strand: { G11: { manual: [], reuse: [] }, G12: { manual: [], reuse: [] } }
            strandData: {
                'Accountancy, Business, and Management': { G11: { manual: [], reuse: [] }, G12: { manual: [], reuse: [] } },
                'Humanities and Social Sciences': { G11: { manual: [], reuse: [] }, G12: { manual: [], reuse: [] } },
                'Science, Technology, Engineering, Mathematics': { G11: { manual: [], reuse: [] }, G12: { manual: [], reuse: [] } },
                'Information and Communication Technology': { G11: { manual: [], reuse: [] }, G12: { manual: [], reuse: [] } }
            },
            pastSubjectsByGroup: {}
        };

        /**
         * Move to the next step of the wizard (Step 1 -> Step 2/Strand 1)
         */
        function curriculumWizardNextStep(currentStep) {
            if (currentStep === 1) {
                // Validate Step 1 inputs
                curriculumWizardState.label = document.getElementById('curriculumLabel')?.value || '';
                
                // Move to strand step 2 (first strand)
                const modal1 = bootstrap.Modal.getInstance(document.getElementById('createCurriculumStep1Modal'));
                if (modal1) modal1.hide();
                
                curriculumWizardState.currentStrandIndex = 0;
                curriculumShowStrandStep();
            }
        }

        /**
         * Show the current strand step
         */
        function curriculumShowStrandStep() {
            const strand = CURRICULUM_STRANDS[curriculumWizardState.currentStrandIndex];
            const strandName = strand.name;
            
            // Update modal title
            document.getElementById('strandModalTitle').textContent = `Create Curriculum - Step ${strand.stepNum} of 6: ${strand.id} Strand`;
            
            // CRITICAL: Reset the UI for this strand by clearing and rebuilding manual subject inputs
            curriculumResetStrandUI();
            
            // Restore saved data for this strand if it exists
            curriculumRestoreStrandUI(strandName);
            
            // Update dynamic UI labels
            curriculumUpdateReuseLabels();
            
            // Load reuse subjects for both grades
            curriculumLoadReuseSubjects('G11');
            curriculumLoadReuseSubjects('G12');
            
            // Default to Grade 11 when advancing to next strand
            const grade11Tab = document.getElementById('grade11-tab');
            if (grade11Tab) {
                const tab = new bootstrap.Tab(grade11Tab);
                tab.show();
            }
            
            // Show the strand modal
            const strandModal = new bootstrap.Modal(document.getElementById('createCurriculumStrandModal'));
            strandModal.show();
        }
        
        /**
         * Update dynamic UI labels for the reuse section based on current strand and grade
         */
        function curriculumUpdateReuseLabels() {
            const currentStrandIndex = curriculumWizardState.currentStrandIndex;
            if (currentStrandIndex < 0 || currentStrandIndex >= CURRICULUM_STRANDS.length) return;
            
            const currentStrand = CURRICULUM_STRANDS[currentStrandIndex].abbreviation || CURRICULUM_STRANDS[currentStrandIndex].name;
            
            // Update Grade 11 label
            const label11 = document.getElementById('reuseLabel_G11');
            if (label11) {
                label11.innerHTML = '<i class="fas fa-recycle me-2 text-success"></i>Reuse Past ' + escapeHtml(currentStrand) + ' – Grade 11 Curricula';
            }
            
            // Update Grade 12 label
            const label12 = document.getElementById('reuseLabel_G12');
            if (label12) {
                label12.innerHTML = '<i class="fas fa-recycle me-2 text-success"></i>Reuse Past ' + escapeHtml(currentStrand) + ' – Grade 12 Curricula';
            }
        }
        
        /**
         * Reset all UI elements for the current strand (clear manual subject rows)
         */
        function curriculumResetStrandUI() {
            // Clear Grade 11 manual subjects
            const g11Container = document.getElementById('manualSubjectsList_G11');
            if (g11Container) {
                g11Container.innerHTML = '';
            }
            
            // Clear Grade 12 manual subjects
            const g12Container = document.getElementById('manualSubjectsList_G12');
            if (g12Container) {
                g12Container.innerHTML = '';
            }
            
            // Clear Grade 11 reuse subjects
            const g11ReuseContainer = document.getElementById('reuseSubjectsList_G11');
            if (g11ReuseContainer) {
                g11ReuseContainer.innerHTML = '';
            }
            
            // Clear Grade 12 reuse subjects
            const g12ReuseContainer = document.getElementById('reuseSubjectsList_G12');
            if (g12ReuseContainer) {
                g12ReuseContainer.innerHTML = '';
            }
        }
        
        /**
         * Restore saved data for the strand (if returning to a previously edited strand)
         */
        function curriculumRestoreStrandUI(strandName) {
            const strandData = curriculumWizardState.strandData[strandName];
            if (!strandData) return;
            
            // Restore Grade 11 manual subjects
            strandData.G11.manual.forEach((subj) => {
                curriculumAddManualSubjectRow('G11');
                const inputs = document.querySelectorAll('#manualSubjectsList_G11 .manual-subject-input-G11');
                if (inputs.length > 0) {
                    inputs[inputs.length - 1].value = subj.name;
                }
            });
            
            // Restore Grade 12 manual subjects
            strandData.G12.manual.forEach((subj) => {
                curriculumAddManualSubjectRow('G12');
                const inputs = document.querySelectorAll('#manualSubjectsList_G12 .manual-subject-input-G12');
                if (inputs.length > 0) {
                    inputs[inputs.length - 1].value = subj.name;
                }
            });
        }

        /**
         * Validate curriculum requirements - global check before final submission
         * Returns true if all validations pass, false otherwise
         */
        function validateCurriculumRequirements() {
            // Count total manual and reused subjects
            let totalManual = 0;
            let totalReused = 0;
            
            for (const strandName in curriculumWizardState.strandData) {
                const strandData = curriculumWizardState.strandData[strandName];
                totalManual += strandData.G11.manual.length + strandData.G12.manual.length;
                totalReused += strandData.G11.reuse.length + strandData.G12.reuse.length;
            }
            
            // ===== VALIDATION: Cannot submit with only reused subjects =====
            if (totalManual === 0 && totalReused > 0) {
                showModernAlert('error', 'Invalid Curriculum', 'You must add new subjects manually. Reusing subjects alone is not allowed.');
                return false;
            }
            
            return true; // All global validations passed
        }

        /**
         * Validate the current strand's requirements before moving to next strand.
         * Both Grade 11 and Grade 12 must have at least 3 manually added subjects each.
         * Returns true if validation passes, false otherwise
         */
        function validateCurrentStrand() {
            const currentStrand = CURRICULUM_STRANDS[curriculumWizardState.currentStrandIndex];
            const strandName = currentStrand.name;
            const strandData = curriculumWizardState.strandData[strandName];
            
            const g11ManualCount = strandData.G11.manual.length;
            const g12ManualCount = strandData.G12.manual.length;
            
            // ===== Check: Grade 11 must have at least 3 manual subjects =====
            if (g11ManualCount < 3) {
                showModernAlert('error', 'Insufficient Subjects - Grade 11', `${currentStrand.id} Grade 11: Each strand and grade level must have at least 3 manually added subjects (currently ${g11ManualCount}).`);
                return false;
            }
            
            // ===== Check: Grade 12 must have at least 3 manual subjects =====
            if (g12ManualCount < 3) {
                showModernAlert('error', 'Insufficient Subjects - Grade 12', `${currentStrand.id} Grade 12: Each strand and grade level must have at least 3 manually added subjects (currently ${g12ManualCount}).`);
                return false;
            }
            
            return true; // Current strand validation passed
        }

        /**
         * Move to the next strand
         */
        function curriculumWizardNextStrand() {
            // Validate current strand before proceeding
            if (!validateCurrentStrand()) {
                return; // Validation failed, error already shown via showModernAlert
            }
            
            // Move to next strand or final step
            if (curriculumWizardState.currentStrandIndex < CURRICULUM_STRANDS.length - 1) {
                const strandModal = bootstrap.Modal.getInstance(document.getElementById('createCurriculumStrandModal'));
                if (strandModal) strandModal.hide();
                
                curriculumWizardState.currentStrandIndex++;
                curriculumShowStrandStep();
            } else {
                // All strands done, validate overall requirements before proceeding to review
                if (!validateCurriculumRequirements()) {
                    return; // Validation failed, error already shown via showModernAlert
                }
                
                // Validation passed, go to final review
                const strandModal = bootstrap.Modal.getInstance(document.getElementById('createCurriculumStrandModal'));
                if (strandModal) strandModal.hide();
                
                // Wait for strand modal to fully close before showing step 3
                document.getElementById('createCurriculumStrandModal').addEventListener('hidden.bs.modal', function() {
                    curriculumPopulateReviewAndSave();
                    const modal3 = new bootstrap.Modal(document.getElementById('createCurriculumStep3Modal'));
                    modal3.show();
                }, { once: true });
            }
        }

        /**
         * Move to the previous strand
         */
        function curriculumWizardPreviousStrand() {
            const strandModal = bootstrap.Modal.getInstance(document.getElementById('createCurriculumStrandModal'));
            const reviewModal = bootstrap.Modal.getInstance(document.getElementById('createCurriculumStep3Modal'));
            
            if (reviewModal && reviewModal._isShown) {
                // Currently in final review (Step 6), go back to last strand
                reviewModal.hide();
                
                // Wait for modal to fully close before showing strand modal
                document.getElementById('createCurriculumStep3Modal').addEventListener('hidden.bs.modal', function() {
                    curriculumWizardState.currentStrandIndex = CURRICULUM_STRANDS.length - 1;
                    curriculumShowStrandStep();
                }, { once: true });
                
            } else if (strandModal && strandModal._isShown) {
                // Currently in a strand, go to previous
                if (curriculumWizardState.currentStrandIndex > 0) {
                    strandModal.hide();
                    
                    // Wait for modal to fully close before showing previous strand
                    document.getElementById('createCurriculumStrandModal').addEventListener('hidden.bs.modal', function() {
                        curriculumWizardState.currentStrandIndex--;
                        curriculumShowStrandStep();
                    }, { once: true });
                } else {
                    // First strand, go back to step 1
                    strandModal.hide();
                    
                    // Wait for modal to fully close before showing step 1
                    document.getElementById('createCurriculumStrandModal').addEventListener('hidden.bs.modal', function() {
                        const modal1 = new bootstrap.Modal(document.getElementById('createCurriculumStep1Modal'));
                        modal1.show();
                    }, { once: true });
                }
            } else {
                // Fallback: Try to determine where we are
                if (curriculumWizardState.currentStrandIndex >= CURRICULUM_STRANDS.length - 1) {
                    // Probably in review, go back to last strand
                    if (reviewModal) reviewModal.hide();
                    curriculumWizardState.currentStrandIndex = CURRICULUM_STRANDS.length - 1;
                    setTimeout(() => curriculumShowStrandStep(), 300);
                } else if (curriculumWizardState.currentStrandIndex > 0) {
                    // In middle strand
                    if (strandModal) strandModal.hide();
                    curriculumWizardState.currentStrandIndex--;
                    setTimeout(() => curriculumShowStrandStep(), 300);
                } else {
                    // First strand
                    if (strandModal) strandModal.hide();
                    setTimeout(() => {
                        const modal1 = new bootstrap.Modal(document.getElementById('createCurriculumStep1Modal'));
                        modal1.show();
                    }, 300);
                }
            }
        }

        /**
         * Add a new manual subject input row for a specific grade
         */
        function curriculumAddManualSubjectRow(grade) {
            const container = document.getElementById('manualSubjectsList_' + grade);
            const rowId = 'manual_subject_' + grade + '_' + Date.now();
            
            const row = document.createElement('div');
            row.id = rowId;
            row.className = 'curriculum-subject-row mb-2 p-2';
            row.style.cssText = 'background: white; border: 1px solid #e9ecef; border-radius: 6px; display: flex; gap: 8px; align-items: center;';
            row.innerHTML = `
                <input type="text" class="form-control form-control-custom manual-subject-input-${grade}" 
                       placeholder="Subject name" 
                       style="flex: 1; margin-bottom: 0; height: 36px;"
                       onchange="curriculumUpdateManualSubjects('${grade}')">
                <button type="button" class="btn btn-sm btn-outline-danger" 
                        onclick="document.getElementById('${rowId}').remove(); curriculumUpdateManualSubjects('${grade}');">
                    <i class="fas fa-trash-alt"></i>
                </button>
            `;
            
            container.appendChild(row);
            row.querySelector('.manual-subject-input-' + grade).focus();
        }

        /**
         * Update the manual subjects list from input fields for a specific grade
         */
        function curriculumUpdateManualSubjects(grade) {
            const inputs = document.querySelectorAll('#manualSubjectsList_' + grade + ' .manual-subject-input-' + grade);
            const strandName = CURRICULUM_STRANDS[curriculumWizardState.currentStrandIndex].name;
            curriculumWizardState.strandData[strandName][grade].manual = Array.from(inputs)
                .map((input) => ({
                    name: input.value.trim()
                }))
                .filter(s => s.name !== '');
        }

        /**
         * Load reuse subjects from past curricula for a specific grade
         */
        function curriculumLoadReuseSubjects(grade) {
            const container = document.getElementById('reuseSubjectsList_' + grade);
            
            // Get current strand from wizard state
            const currentStrandIndex = curriculumWizardState.currentStrandIndex;
            if (currentStrandIndex < 0 || currentStrandIndex >= CURRICULUM_STRANDS.length) {
                container.innerHTML = '<div class="text-muted text-center" style="padding: 20px;"><i class="fas fa-alert-circle me-2"></i>Invalid strand selection.</div>';
                return;
            }
            
            const currentStrand = CURRICULUM_STRANDS[currentStrandIndex].name;
            
            // Get past subjects from PHP data (must be injected by server)
            const pastSubjectsData = <?php echo json_encode(subjectManagementFetchPastSubjectsForReuse($conn)); ?>;
            
            container.innerHTML = '';
            
            if (Object.keys(pastSubjectsData).length === 0) {
                container.innerHTML = '<div class="text-muted text-center" style="padding: 20px;"><i class="fas fa-info-circle me-2"></i>No archived subjects available for reuse.</div>';
                return;
            }
            
            // Filter and display subjects for this grade and current strand only
            let itemCount = 0;
            for (const groupKey in pastSubjectsData) {
                const group = pastSubjectsData[groupKey];
                const groupStrand = group.strand || '';
                const groupYear = group.year_level || '';
                
                // Only show subjects for the current grade level
                const targetGrade = grade === 'G11' ? '11' : '12';
                if (groupYear !== targetGrade) continue;
                
                // Only show subjects from the CURRENT strand (automatic filter)
                if (groupStrand !== currentStrand) continue;
                
                group.subjects.forEach(subject => {
                    const checkboxId = 'reuse_subject_' + grade + '_' + subject.subject_id;
                    const item = document.createElement('div');
                    item.className = 'curriculum-subject-reuse-row p-2';
                    item.style.cssText = 'background: white; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; gap: 8px; cursor: pointer;';
                    item.innerHTML = `
                        <input type="checkbox" id="${checkboxId}" value="${subject.subject_id}" 
                               class="reuse-subject-checkbox-${grade}" 
                               onchange="curriculumUpdateReuseSubjects('${grade}')">
                        <label for="${checkboxId}" style="flex: 1; margin-bottom: 0; cursor: pointer;">
                            <strong>${escapeHtml(subject.subject_name)}</strong>
                            <br>
                            <small class="text-muted">
                                Grade ${escapeHtml(groupYear)} · ${escapeHtml(groupStrand.substring(0, 20))}
                                <span style="color: #999;"> (SY: ${escapeHtml(subject.school_year)})</span>
                            </small>
                        </label>
                    `;
                    container.appendChild(item);
                    itemCount++;
                });
            }
            
            if (itemCount === 0) {
                container.innerHTML = '<div class="text-muted text-center" style="padding: 20px;"><i class="fas fa-filter me-2"></i>No past subjects available for ' + escapeHtml(currentStrand) + ' – Grade ' + (grade === 'G11' ? '11' : '12') + '.</div>';
            }
        }

        /**
         * Toggle visibility of the reuse filter options for a specific grade
         */
        function curriculumToggleReuseFilter(grade) {
            const filterOptions = document.getElementById('reuseFilterOptions_' + grade);
            if (filterOptions) {
                filterOptions.style.display = filterOptions.style.display === 'none' ? 'block' : 'none';
            }
        }

        /**
         * Update the reuse subjects list from checkboxes for a specific grade
         */
        function curriculumUpdateReuseSubjects(grade) {
            const checkboxes = document.querySelectorAll('#reuseSubjectsList_' + grade + ' .reuse-subject-checkbox-' + grade + ':checked');
            const strandName = CURRICULUM_STRANDS[curriculumWizardState.currentStrandIndex].name;
            curriculumWizardState.strandData[strandName][grade].reuse = Array.from(checkboxes).map(cb => parseInt(cb.value, 10));
        }

        /**
         * Populate the review and save modal with summary data (validation already passed)
         */
        function curriculumPopulateReviewAndSave() {
            // At this point, validation has already passed via validateCurriculumRequirements()
            // So we can safely populate the review with confirmed data
            
            // Calculate totals for display
            let totalManual = 0;
            let totalReused = 0;
            
            for (const strandName in curriculumWizardState.strandData) {
                const strandData = curriculumWizardState.strandData[strandName];
                totalManual += strandData.G11.manual.length + strandData.G12.manual.length;
                totalReused += strandData.G11.reuse.length + strandData.G12.reuse.length;
            }
            
            // ===== Populate the hidden form fields and display summary =====
            
            // Update the hidden form fields
            document.getElementById('finalLabel').value = curriculumWizardState.label;
            
            const finalSubjectsFieldsContainer = document.getElementById('finalSubjectsFields');
            finalSubjectsFieldsContainer.innerHTML = '';
            
            // Create hidden input fields for all subjects organized by strand and grade
            let fieldIndex = 0;
            for (const strandName in curriculumWizardState.strandData) {
                const strandData = curriculumWizardState.strandData[strandName];
                
                // Grade 11
                strandData.G11.manual.forEach((subj) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `manual_subjects[${fieldIndex}][name]`;
                    input.value = subj.name;
                    finalSubjectsFieldsContainer.appendChild(input);
                    
                    const inputStrand = document.createElement('input');
                    inputStrand.type = 'hidden';
                    inputStrand.name = `manual_subjects[${fieldIndex}][strand]`;
                    inputStrand.value = strandName;
                    finalSubjectsFieldsContainer.appendChild(inputStrand);
                    
                    const inputGrade = document.createElement('input');
                    inputGrade.type = 'hidden';
                    inputGrade.name = `manual_subjects[${fieldIndex}][year_level]`;
                    inputGrade.value = '11';
                    finalSubjectsFieldsContainer.appendChild(inputGrade);
                    
                    fieldIndex++;
                });
                
                strandData.G11.reuse.forEach((id) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `reuse_subject_ids[${fieldIndex}]`;
                    input.value = id;
                    finalSubjectsFieldsContainer.appendChild(input);
                    fieldIndex++;
                });
                
                // Grade 12
                strandData.G12.manual.forEach((subj) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `manual_subjects[${fieldIndex}][name]`;
                    input.value = subj.name;
                    finalSubjectsFieldsContainer.appendChild(input);
                    
                    const inputStrand = document.createElement('input');
                    inputStrand.type = 'hidden';
                    inputStrand.name = `manual_subjects[${fieldIndex}][strand]`;
                    inputStrand.value = strandName;
                    finalSubjectsFieldsContainer.appendChild(inputStrand);
                    
                    const inputGrade = document.createElement('input');
                    inputGrade.type = 'hidden';
                    inputGrade.name = `manual_subjects[${fieldIndex}][year_level]`;
                    inputGrade.value = '12';
                    finalSubjectsFieldsContainer.appendChild(inputGrade);
                    
                    fieldIndex++;
                });
                
                strandData.G12.reuse.forEach((id) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `reuse_subject_ids[${fieldIndex}]`;
                    input.value = id;
                    finalSubjectsFieldsContainer.appendChild(input);
                    fieldIndex++;
                });
            }
            
            // Populate review summary with BOTH manual and reused subjects
            const summary = document.getElementById('curriculumReviewSummary');
            let summaryHtml = `
                <div class="alert alert-success mb-3">
                    <strong><i class="fas fa-check-circle me-2"></i>Curriculum Summary</strong>
                    <small class="d-block mt-1">All validations passed. Ready to create.</small>
                </div>
                
                <div style="background: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <p><strong>Total Subjects:</strong> ${totalManual + totalReused} (${totalManual} manual + ${totalReused} reused)</p>
                        </div>
                    </div>
                    ${curriculumWizardState.label ? `<p><strong>Label:</strong> ${escapeHtml(curriculumWizardState.label)}</p>` : ''}
                </div>
                
                <div class="mb-3">
                    <h6 class="fw-6 mb-3"><i class="fas fa-layer-group me-2 text-primary"></i>Curriculum by Strand & Grade Level</h6>
            `;
            
            for (const strandName in curriculumWizardState.strandData) {
                const strandData = curriculumWizardState.strandData[strandName];
                const strand = CURRICULUM_STRANDS.find(s => s.name === strandName);
                
                summaryHtml += `
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <strong>${escapeHtml(strand.id)}</strong>
                        </div>
                        <div class="card-body">
                `;
                
                // ===== GRADE 11 =====
                const g11Manual = strandData.G11.manual;
                const g11Reuse = strandData.G11.reuse;
                const g11Total = g11Manual.length + g11Reuse.length;
                
                summaryHtml += `
                    <div class="mb-3">
                        <h6><i class="fas fa-graduation-cap me-2"></i>Grade 11 (${g11Total} total: ${g11Manual.length} manual + ${g11Reuse.length} reused)</h6>
                `;
                
                if (g11Total > 0) {
                    summaryHtml += '<div class="mb-2">';
                    
                    // Manual subjects
                    if (g11Manual.length > 0) {
                        summaryHtml += `
                            <div class="mb-2">
                                <small class="text-muted"><strong>Manually Added:</strong></small>
                                <ul class="list-group list-group-sm">
                        `;
                        g11Manual.forEach(subj => {
                            summaryHtml += `<li class="list-group-item"><small><i class="fas fa-plus-circle me-2 text-success"></i>${escapeHtml(subj.name)}</small></li>`;
                        });
                        summaryHtml += '</ul></div>';
                    }
                    
                    // Reused subjects
                    if (g11Reuse.length > 0) {
                        // Get reused subject names for display
                        const reuseNames = g11Reuse.map(id => {
                            for (const groupKey in curriculumWizardState.pastSubjectsByGroup) {
                                const group = curriculumWizardState.pastSubjectsByGroup[groupKey];
                                const found = group.subjects.find(s => s.subject_id === id);
                                if (found) return found.subject_name;
                            }
                            return `Subject #${id}`;
                        });
                        
                        summaryHtml += `
                            <div>
                                <small class="text-muted"><strong>Reused from Past:</strong></small>
                                <ul class="list-group list-group-sm">
                        `;
                        reuseNames.forEach(name => {
                            summaryHtml += `<li class="list-group-item"><small><i class="fas fa-recycle me-2 text-info"></i>${escapeHtml(name)}</small></li>`;
                        });
                        summaryHtml += '</ul></div>';
                    }
                    
                    summaryHtml += '</div>';
                } else {
                    summaryHtml += '<p class="text-muted"><small><i class="fas fa-info-circle me-2"></i>No subjects</small></p>';
                }
                summaryHtml += '</div>';
                
                // ===== GRADE 12 =====
                const g12Manual = strandData.G12.manual;
                const g12Reuse = strandData.G12.reuse;
                const g12Total = g12Manual.length + g12Reuse.length;
                
                summaryHtml += `
                    <div>
                        <h6><i class="fas fa-graduation-cap me-2"></i>Grade 12 (${g12Total} total: ${g12Manual.length} manual + ${g12Reuse.length} reused)</h6>
                `;
                
                if (g12Total > 0) {
                    summaryHtml += '<div class="mb-2">';
                    
                    // Manual subjects
                    if (g12Manual.length > 0) {
                        summaryHtml += `
                            <div class="mb-2">
                                <small class="text-muted"><strong>Manually Added:</strong></small>
                                <ul class="list-group list-group-sm">
                        `;
                        g12Manual.forEach(subj => {
                            summaryHtml += `<li class="list-group-item"><small><i class="fas fa-plus-circle me-2 text-success"></i>${escapeHtml(subj.name)}</small></li>`;
                        });
                        summaryHtml += '</ul></div>';
                    }
                    
                    // Reused subjects
                    if (g12Reuse.length > 0) {
                        // Get reused subject names for display
                        const reuseNames = g12Reuse.map(id => {
                            for (const groupKey in curriculumWizardState.pastSubjectsByGroup) {
                                const group = curriculumWizardState.pastSubjectsByGroup[groupKey];
                                const found = group.subjects.find(s => s.subject_id === id);
                                if (found) return found.subject_name;
                            }
                            return `Subject #${id}`;
                        });
                        
                        summaryHtml += `
                            <div>
                                <small class="text-muted"><strong>Reused from Past:</strong></small>
                                <ul class="list-group list-group-sm">
                        `;
                        reuseNames.forEach(name => {
                            summaryHtml += `<li class="list-group-item"><small><i class="fas fa-recycle me-2 text-info"></i>${escapeHtml(name)}</small></li>`;
                        });
                        summaryHtml += '</ul></div>';
                    }
                    
                    summaryHtml += '</div>';
                } else {
                    summaryHtml += '<p class="text-muted"><small><i class="fas fa-info-circle me-2"></i>No subjects</small></p>';
                }
                summaryHtml += '</div>';
                
                summaryHtml += `
                        </div>
                    </div>
                `;
            }
            
            summaryHtml += '</div>';
            summary.innerHTML = summaryHtml;
        }

        /**
         * Escape HTML special characters
         */
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initialize wizard: load past subjects on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Pre-load past subjects data for later use
            const pastSubjectsData = <?php echo json_encode(subjectManagementFetchPastSubjectsForReuse($conn)); ?>;
            curriculumWizardState.pastSubjectsByGroup = pastSubjectsData;
        });


    </script>
</body>
</html>