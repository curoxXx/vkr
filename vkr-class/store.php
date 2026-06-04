<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$user = getCurrentUser($pdo);

if (!$user) {
    unset($_SESSION['user']);
    redirect('/auth/login.php');
}

if (!in_array(($user['role'] ?? ''), ['provider', 'customer'], true)) {
    redirect('/profile/');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(($user['role'] ?? '') === 'provider' ? '/provider/bookings.php' : '/booking/my.php');
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$comment = trim((string)($_POST['comment'] ?? ''));

if ($bookingId <= 0) {
    $_SESSION['booking_error'] = 'Некорректное бронирование.';
    redirect(($user['role'] ?? '') === 'provider' ? '/provider/bookings.php' : '/booking/my.php');
}

$redirectBack = '/reviews/create.php?booking_id=' . $bookingId;
$errors = [];

if ($rating < 1 || $rating > 5) {
    $errors[] = 'Оценка должна быть от 1 до 5.';
}

if (mb_strlen($comment) > 1000) {
    $errors[] = 'Комментарий не должен превышать 1000 символов.';
}

try {
    $stmt = $pdo->prepare("
        SELECT
            b.*,
            s.user_id AS provider_id
        FROM bookings b
        INNER JOIN services s ON s.id = b.service_id
        WHERE b.id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        throw new RuntimeException('Бронирование не найдено.');
    }

    $isProviderAuthor = (($user['role'] ?? '') === 'provider');
    $isCustomerAuthor = (($user['role'] ?? '') === 'customer');

    if ($isProviderAuthor) {
        if ((int)$booking['provider_id'] !== (int)$user['id']) {
            throw new RuntimeException('Нельзя оставить отзыв по чужому бронированию.');
        }

        if (!in_array($booking['status'], ['completed', 'missed'], true)) {
            throw new RuntimeException('Отзыв о заказчике можно оставить только после завершённой услуги или неявки.');
        }

        $targetId = (int)$booking['customer_id'];
        $targetType = 'customer';
        $successMessage = 'Отзыв о заказчике успешно сохранён.';
        $redirectSuccess = '/provider/bookings.php';

        if (($booking['status'] === 'missed' || $rating <= 2) && $comment === '') {
            $errors[] = 'Для неявки или низкой оценки нужно указать комментарий.';
        }
    } elseif ($isCustomerAuthor) {
        if ((int)$booking['customer_id'] !== (int)$user['id']) {
            throw new RuntimeException('Нельзя оставить отзыв по чужому бронированию.');
        }

        if ($booking['status'] !== 'completed') {
            throw new RuntimeException('Отзыв об исполнителе можно оставить только после завершённой услуги.');
        }

        $targetId = (int)$booking['provider_id'];
        $targetType = 'provider';
        $successMessage = 'Отзыв об исполнителе успешно сохранён.';
        $redirectSuccess = '/booking/my.php';

        if ($rating <= 2 && $comment === '') {
            $errors[] = 'Для низкой оценки нужно указать комментарий.';
        }
    } else {
        throw new RuntimeException('Недостаточно прав для выполнения действия.');
    }

    if (!empty($errors)) {
        $_SESSION['review_form_errors'] = $errors;
        $_SESSION['review_form_old'] = [
            'rating' => $rating,
            'comment' => $comment,
        ];
        redirect($redirectBack);
    }

    $reviewCheckStmt = $pdo->prepare("
        SELECT id
        FROM reviews
        WHERE booking_id = ?
          AND author_id = ?
          AND target_id = ?
          AND target_type = ?
        LIMIT 1
    ");
    $reviewCheckStmt->execute([
        $bookingId,
        $user['id'],
        $targetId,
        $targetType,
    ]);
    $existingReviewId = $reviewCheckStmt->fetchColumn();

    if ($existingReviewId) {
        throw new RuntimeException('Отзыв по этому бронированию уже оставлен.');
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO reviews (
            booking_id,
            author_id,
            target_id,
            target_type,
            rating,
            comment
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([
        $bookingId,
        $user['id'],
        $targetId,
        $targetType,
        $rating,
        $comment !== '' ? $comment : null,
    ]);

    $_SESSION['booking_success'] = $successMessage;
    redirect($redirectSuccess);
} catch (Throwable $e) {
    $_SESSION['review_form_errors'] = [$e->getMessage()];
    $_SESSION['review_form_old'] = [
        'rating' => $rating,
        'comment' => $comment,
    ];
    redirect($redirectBack);
}