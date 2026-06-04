<?php

function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function getUserId(): ?int
{
    return $_SESSION['user']['id'] ?? null;
}

function e(?string $string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function requireGuest(): void
{
    if (isLoggedIn()) {
        redirect('/profile/');
    }
}

function requireAuth(): void
{
    if (!isLoggedIn()) {
        redirect('/auth/login.php');
    }
}

function getCurrentUser(PDO $pdo): ?array
{
    $userId = getUserId();

    if (!$userId) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT u.*, c.name AS city_name
        FROM users u
        LEFT JOIN cities c ON c.id = u.city_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function normalizeWhitespace(string $value): string
{
    $value = trim($value);
    return preg_replace('/\s+/u', ' ', $value) ?? '';
}

function normalizePhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if ($digits === '') {
        return '';
    }

    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }

    if (strlen($digits) === 11 && $digits[0] === '7') {
        return '+'.$digits;
    }

    return $phone;
}

function validateFullName(string $fullName): ?string
{
    if ($fullName === '') {
        return 'Введите ФИО.';
    }

    if (mb_strlen($fullName) < 5) {
        return 'ФИО должно содержать минимум 5 символов.';
    }

    if (mb_strlen($fullName) > 120) {
        return 'ФИО не должно превышать 120 символов.';
    }

    if (!preg_match('/^[\p{L}\s\-]+$/u', $fullName)) {
        return 'ФИО может содержать только буквы, пробелы и дефис.';
    }

    return null;
}

function validateEmailAddress(string $email): ?string
{
    if ($email === '') {
        return 'Введите email.';
    }

    if (mb_strlen($email) > 100) {
        return 'Email не должен превышать 100 символов.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Введите корректный email.';
    }

    return null;
}

function validatePhoneNumber(string $phone, bool $required = false): ?string
{
    if ($phone === '') {
        return $required ? 'Введите телефон.' : null;
    }

    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if (strlen($digits) !== 11) {
        return 'Телефон должен содержать 11 цифр.';
    }

    if (!in_array($digits[0], ['7', '8'], true)) {
        return 'Телефон должен начинаться с +7 или 8.';
    }

    return null;
}

function validatePasswordValue(string $password): ?string
{
    if ($password === '') {
        return 'Введите пароль.';
    }

    if (mb_strlen($password) < 8) {
        return 'Пароль должен содержать минимум 8 символов.';
    }

    if (mb_strlen($password) > 255) {
        return 'Пароль слишком длинный.';
    }

    if (!preg_match('/[A-Za-zА-Яа-я]/u', $password)) {
        return 'Пароль должен содержать хотя бы одну букву.';
    }

    if (!preg_match('/\d/', $password)) {
        return 'Пароль должен содержать хотя бы одну цифру.';
    }

    return null;
}

function validateTextMaxLength(string $value, int $maxLength, string $fieldLabel): ?string
{
    if (mb_strlen($value) > $maxLength) {
        return $fieldLabel . ' не должно превышать ' . $maxLength . ' символов.';
    }
    return null;
}