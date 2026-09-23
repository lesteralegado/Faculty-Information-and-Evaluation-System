<?php
/** Save the evaluation and its answers as a single transaction. Schema is deployed separately. */
function app_save_evaluation(mysqli $conn, int $studentId, int $teacherId, int $subjectId,
    string $schoolYear, int $semester, array $responses, string $comments): int
{
    $comments = trim($comments);
    $length = preg_match_all('/./us', $comments);
    if ($length === false || $length > 200) {
        throw new InvalidArgumentException('Comments must contain valid text and be no more than 200 characters.');
    }
    if (!$responses) {
        throw new InvalidArgumentException('Please answer the evaluation questions before submitting.');
    }

    $conn->begin_transaction();
    try {
        $evaluation = $conn->prepare('INSERT INTO evaluations (student_id, teacher_id, subject_id, school_year, semester) VALUES (?, ?, ?, ?, ?)');
        $evaluation->bind_param('iiisi', $studentId, $teacherId, $subjectId, $schoolYear, $semester);
        $evaluation->execute();
        $evaluationId = (int)$conn->insert_id;
        $evaluation->close();

        $answer = $conn->prepare('INSERT INTO evaluation_responses (evaluation_id, question_id, response) VALUES (?, ?, ?)');
        foreach ($responses as $questionId => $rating) {
            $questionId = (int)$questionId;
            $rating = (int)$rating;
            if ($questionId <= 0 || $rating < 1 || $rating > 5) {
                throw new InvalidArgumentException('One or more evaluation answers are invalid.');
            }
            $answer->bind_param('iii', $evaluationId, $questionId, $rating);
            $answer->execute();
        }
        $answer->close();

        if ($comments !== '') {
            $comment = $conn->prepare('INSERT INTO evaluation_comments (student_id, teacher_id, subject_id, comment, school_year, semester) VALUES (?, ?, ?, ?, ?, ?)');
            $comment->bind_param('iiissi', $studentId, $teacherId, $subjectId, $comments, $schoolYear, $semester);
            $comment->execute();
            $comment->close();
        }
        $conn->commit();
        return $evaluationId;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
