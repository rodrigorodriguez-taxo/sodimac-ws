<?php
# ============================================================
# ws/helpers/response.php
# Funciones auxiliares para respuestas JSON y CORS
# ============================================================

function corsHeaders()
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function jsonResponse($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function okResponse($payload = null, $msg = 'OK')
{
    jsonResponse([
        'status' => 'OK',
        'msg'    => $msg,
        'data'   => $payload,
    ]);
}

function errorResponse($msg, $code = 400)
{
    jsonResponse([
        'status' => 'ERROR',
        'msg'    => $msg,
    ], $code);
}

function getJsonInput()
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function getBearerToken()
{
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $matches)) {
        return $matches[1];
    }
    return null;
}

function getDefaultPassword($rut)
{
    # La APK usa los primeros 6 digitos del RUT como password por defecto
    $rut = preg_replace('/[^0-9]/', '', $rut);
    return substr($rut, 0, 6);
}

function debugQuery(string $sql, array $params): string
{
    foreach ($params as $key => $value) {
        $value = "'" . addslashes((string) $value) . "'";
        $sql = str_replace($key, $value, $sql);
    }

    return $sql;
}
