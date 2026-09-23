<?php
/** Advance the active term, graduating Grade 12 before promoting Grade 11. */
function app_close_semester(mysqli $conn): void
{
    $conn->begin_transaction();
    try {
        $current = $conn->query('SELECT id, school_year, semester FROM currentschoolyearandsemester ORDER BY id LIMIT 1 FOR UPDATE')->fetch_assoc();
        if (!$current || !preg_match('/^(\d{4})-(\d{4})$/', str_replace(['–', '—'], '-', $current['school_year']), $years)
            || !in_array((int)$current['semester'], [1, 2], true)) {
            throw new RuntimeException('Configure a valid current school year and semester before closing the term.');
        }
        $schoolYear = $current['school_year'];
        $semester = (int)$current['semester'];
        $nextYear = $semester === 1 ? $schoolYear : ((int)$years[1] + 1) . '-' . ((int)$years[2] + 1);
        $nextSemester = $semester === 1 ? 2 : 1;

        if ($semester === 2) {
            $graduate = $conn->prepare("UPDATE users u JOIN students s ON s.user_id = u.user_id
                SET u.status = 'inactive' WHERE u.role = 'student' AND s.year_level = '12'
                AND s.school_year = ? AND s.semester = 2");
            $graduate->bind_param('s', $schoolYear);
            $graduate->execute();
            $graduate->close();

            $archive = $conn->prepare("UPDATE sections SET status = 'archived'
                WHERE status = 'active' AND year_level = '12' AND school_year = ? AND semester = 2");
            $archive->bind_param('s', $schoolYear);
            $archive->execute();
            $archive->close();
        }

        $sectionSql = $semester === 1
            ? "UPDATE sections SET semester = 2 WHERE status = 'active' AND school_year = ? AND semester = 1"
            : "UPDATE sections SET year_level = '12', semester = 1, school_year = ? WHERE status = 'active' AND year_level = '11' AND school_year = ? AND semester = 2";
        $sections = $conn->prepare($sectionSql);
        if ($semester === 1) {
            $sections->bind_param('s', $schoolYear);
        } else {
            $sections->bind_param('ss', $nextYear, $schoolYear);
        }
        $sections->execute();
        $sections->close();

        $studentSql = $semester === 1
            ? "UPDATE students SET semester = 2 WHERE school_year = ? AND semester = 1"
            : "UPDATE students SET year_level = '12', semester = 1, school_year = ? WHERE year_level = '11' AND school_year = ? AND semester = 2";
        $students = $conn->prepare($studentSql);
        if ($semester === 1) {
            $students->bind_param('s', $schoolYear);
        } else {
            $students->bind_param('ss', $nextYear, $schoolYear);
        }
        $students->execute();
        $students->close();

        $settings = $conn->prepare('UPDATE currentschoolyearandsemester SET school_year = ?, semester = ? WHERE id = ?');
        $settings->bind_param('sii', $nextYear, $nextSemester, $current['id']);
        $settings->execute();
        $settings->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
