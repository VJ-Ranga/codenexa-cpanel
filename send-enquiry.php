<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['message' => $message]);
    exit;
}

function field(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, 'Use the contact form to send an enquiry.');
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
    respond(413, 'The enquiry is too large.');
}

$name = field('name');
$email = field('email');
$phone = field('phone');
$service = field('service');
$package = field('package');
$details = field('details');
$token = field('cf-turnstile-response');

$services = [
    'web' => 'Web Development',
    'brand' => 'Brand & Identity',
    'social' => 'Social Media',
    'software' => 'Custom Software Development',
    'care' => 'Website Maintenance',
    'troubleshooting' => 'Website troubleshooting or security',
    'hosting' => 'Hosting or server support',
    'other' => 'Multiple services or not sure',
];

if ($name === '' || strlen($name) > 400 || strlen($email) > 254
    || preg_match('/[\r\n]/', $email) || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($phone) > 160 || !isset($services[$service]) || strlen($package) > 160
    || $details === '' || strlen($details) > 4000) {
    respond(422, 'Check your name, email, service and project details, then try again.');
}

if ($token === '' || strlen($token) > 2048) {
    respond(422, 'Complete the spam check and try again.');
}

$secretPath = dirname(__DIR__) . '/codenexa-turnstile-secret.txt';
$secret = is_readable($secretPath) ? trim((string) file_get_contents($secretPath)) : '';
if ($secret === '' || !function_exists('curl_init')) {
    error_log('CodeNexa enquiries: Turnstile secret or PHP cURL is unavailable.');
    respond(503, 'The form is temporarily unavailable. Please contact us by email or WhatsApp.');
}

$request = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
curl_setopt_array($request, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 8,
]);
$result = curl_exec($request);
$status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
curl_close($request);
$verification = is_string($result) ? json_decode($result, true) : null;

if ($status !== 200 || !is_array($verification)) {
    respond(503, 'The spam check is temporarily unavailable. Please try again.');
}

if (($verification['success'] ?? false) !== true
    || ($verification['action'] ?? '') !== 'enquiry'
    || !in_array($verification['hostname'] ?? '', ['codenexa.com.lk', 'www.codenexa.com.lk'], true)) {
    respond(422, 'The spam check expired or could not be verified. Please try again.');
}

$body = implode("\n", [
    'Service: ' . $services[$service],
    ...($package !== '' ? ['Package: ' . $package] : []),
    'Name: ' . $name,
    'Email: ' . $email,
    ...($phone !== '' ? ['Phone: ' . $phone] : []),
    '',
    $details,
]);

$mailbox = 'info@codenexa.com.lk';
$headers = [
    'From' => $mailbox,
    'Reply-To' => $email,
    'Content-Type' => 'text/plain; charset=UTF-8',
];

if (!function_exists('mail') || !mail($mailbox, 'CodeNexa website enquiry', $body, $headers)) {
    error_log('CodeNexa enquiries: PHP mail did not accept the message.');
    respond(503, 'We couldn’t send your enquiry. Please try again or contact us directly.');
}

respond(200, 'Thanks. Your enquiry was sent.');
