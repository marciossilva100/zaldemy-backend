<?php

require_once '../model/Auth.php';

$headers = getallheaders();

$authHeader =
    $headers['Authorization']
    ?? $headers['authorization']
    ?? $_SERVER['HTTP_AUTHORIZATION']
    ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["error" => "Token não enviado"]);
    exit;
}

$token = str_replace("Bearer ", "", $authHeader);

$auth = new Auth($pdo);
$user = $auth->getUserByToken($token);

if (!$user) {
    http_response_code(401);
    echo json_encode(["error" => "Token inválido"]);
    exit;
}

$user_id = $user['id'];