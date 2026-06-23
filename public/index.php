<?php

$uri = strtok($_SERVER['REQUEST_URI'], '?');

header('Content-Type: application/json');

if ($uri === '/health') {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not implemented']);