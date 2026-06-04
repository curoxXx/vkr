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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/services/');
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
$message = trim((string)($_POST['message'] ?? ''));

function normalizeChatUploadFiles(?array $files): array
{
    if (
        !$files ||
        !isset($files['name'], $files['tmp_name'], $files['error'], $files['size']) ||
        !is_array($files['name'])
    ) {
        return [];
    }

    $normalized = [];
    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $normalized[] = [
            'name' => (string)($files['name'][$i] ?? ''),
            'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
            'error' => $error,
            'size' => (int)($files['size'][$i] ?? 0),
        ];
    }
    return $normalized;
}

function validateChatImages(array $images): array
{
    $validated = [];
    $allowedMimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    foreach ($images as $image) {
        if (($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Не удалось загрузить одно из изображений сообщения.');
        }

        if (($image['size'] ?? 0) <= 0) {
            throw new RuntimeException('Одно из изображений сообщения повреждено или пустое.');
        }

        if (($image['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new RuntimeException('Размер одного изображения не должен превышать 5 МБ.');
        }

        $imageInfo = @getimagesize($image['tmp_name']);
        $mime = $imageInfo['mime'] ?? '';

        if (!$imageInfo || !isset($allowedMimeMap[$mime])) {
            throw new RuntimeException('Разрешены только изображения JPG, PNG и WEBP.');
        }

        $validated[] = [
            'tmp_name' => $image['tmp_name'],
            'extension' => $allowedMimeMap[$mime],
        ];
    }

    return $validated;
}

if ($bookingId <= 0) {
    $_SESSION['chat_error'] = 'Некорректное бронирование.';
    redirect('/services/');
}

try {
    $normalizedImages = normalizeChatUploadFiles($_FILES['chat_images'] ?? null);
    $validatedImages = validateChatImages($normalizedImages);

    if (count($validatedImages) > 5) {
        throw new RuntimeException('Можно отправить не более 5 изображений за одно сообщение.');
    }
} catch (Throwable $e) {
    $_SESSION['chat_error'] = $e->getMessage();
    redirect('/chat/view.php?booking_id=' . $bookingId . '#chat-bottom');
}

if ($message === '' && empty($validatedImages)) {
    $_SESSION['chat_error'] = 'Добавьте текст сообщения или хотя бы одно изображение.';
    redirect('/chat/view.php?booking_id=' . $bookingId . '#chat-bottom');
}

if (mb_strlen($message) > 2000) {
    $_SESSION['chat_error'] = 'Сообщение не должно превышать 2000 символов.';
    redirect('/chat/view.php?booking_id=' . $bookingId . '#chat-bottom');
}

try {
    $stmt = $pdo->prepare("
        SELECT
            b.*,
            s.user_id AS provider_id,
            ch.id AS chat_id,
            sl.start_time
        FROM bookings b
        INNER JOIN services s ON s.id = b.service_id
        LEFT JOIN chats ch ON ch.booking_id = b.id
        LEFT JOIN slots sl ON sl.id = b.slot_id
        WHERE b.id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        throw new RuntimeException('Бронирование не найдено.');
    }

    $isProvider = (int)$booking['provider_id'] === (int)$user['id'];
    $isCustomer = (int)$booking['customer_id'] === (int)$user['id'];

    if (!$isProvider && !$isCustomer) {
        throw new RuntimeException('У вас нет доступа к этому чату.');
    }

    $projectRoot = dirname(__DIR__);
    $uploadsDir = $projectRoot . '/uploads/chat';
    $storedFiles = [];

    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
        throw new RuntimeException('Не удалось подготовить папку для изображений чата.');
    }

    $pdo->beginTransaction();

    try {
        $chatId = (int)($booking['chat_id'] ?? 0);

        if ($chatId <= 0) {
            $insertChatStmt = $pdo->prepare("
                INSERT INTO chats (booking_id)
                VALUES (?)
            ");
            $insertChatStmt->execute([$bookingId]);
            $chatId = (int)$pdo->lastInsertId();

            $insertSystemStmt = $pdo->prepare("
                INSERT INTO messages (
                    chat_id,
                    sender_id,
                    message,
                    is_system,
                    is_read
                ) VALUES (?, ?, ?, ?, ?)
            ");

            if (!empty($booking['start_time'])) {
                $chatBookingDate = new DateTimeImmutable($booking['start_time']);
                $systemChatMessage = 'Создан чат по бронированию на ' . $chatBookingDate->format('d.m.Y') . ' в ' . $chatBookingDate->format('H:i') . '.';
            } else {
                $systemChatMessage = 'Создан чат по бронированию на ' . $booking['booking_date'] . '.';
            }

            $insertSystemStmt->execute([
                $chatId,
                (int)$booking['provider_id'],
                $systemChatMessage,
                1,
                1,
            ]);

            if (!empty($booking['customer_message'])) {
                $insertSystemStmt->execute([
                    $chatId,
                    (int)$booking['customer_id'],
                    $booking['customer_message'],
                    0,
                    0,
                ]);
            }
        }

        $insertMessageStmt = $pdo->prepare("
            INSERT INTO messages (
                chat_id,
                sender_id,
                message,
                is_system,
                is_read
            ) VALUES (?, ?, ?, 0, 0)
        ");
        $insertMessageStmt->execute([
            $chatId,
            $user['id'],
            $message !== '' ? $message : null,
        ]);

        $newMessageId = (int)$pdo->lastInsertId();

        if (!empty($validatedImages)) {
            $insertImageStmt = $pdo->prepare("
                INSERT INTO message_images (
                    message_id,
                    image_path
                ) VALUES (?, ?)
            ");

            foreach ($validatedImages as $imageData) {
                $fileName = 'chat_' . $chatId . '_' . bin2hex(random_bytes(8)) . '.' . $imageData['extension'];
                $absolutePath = $uploadsDir . '/' . $fileName;
                $relativePath = '/uploads/chat/' . $fileName;

                if (!move_uploaded_file($imageData['tmp_name'], $absolutePath)) {
                    throw new RuntimeException('Не удалось сохранить одно из изображений сообщения.');
                }

                $storedFiles[] = $absolutePath;

                $insertImageStmt->execute([
                    $newMessageId,
                    $relativePath,
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedFiles as $storedFile) {
            if (is_file($storedFile)) {
                @unlink($storedFile);
            }
        }
        throw $e;
    }

    redirect('/chat/view.php?booking_id=' . $bookingId . '#chat-bottom');
} catch (Throwable $e) {
    $_SESSION['chat_error'] = $e->getMessage();
    redirect('/chat/view.php?booking_id=' . $bookingId . '#chat-bottom');
}