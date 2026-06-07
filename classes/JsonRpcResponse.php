<?php

declare(strict_types=1);

namespace AdmidioMcp;

final class JsonRpcResponse
{
    public static function result(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    public static function error(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }

    public static function sendJson(array $payload): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function sendHttpError(int $statusCode, string $message): never
    {
        http_response_code($statusCode);
        self::sendJson([
            'error' => $message,
        ]);
    }
}
