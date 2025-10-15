<?php

namespace Bpjs\Framework\Helpers;

use PDO;
use Bpjs\Framework\Helpers\Database;

class LogController {
    public static function addLog($url, $httpMethod, $statusCode, $executionTime, $requestData = null, $responseData = null) {
        $db = Database::connection();
        
        $requestData = $requestData ? json_encode($requestData) : null;
        $responseData = $responseData ? json_encode($responseData) : null;
        $stmt = $db->prepare("INSERT INTO logs (url, http_method, status_code, execution_time, request_data, response_data) 
                              VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$url, $httpMethod, $statusCode, $executionTime, $requestData, $responseData]);
    }

    public static function getLogs() {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT * FROM logs ORDER BY created_at DESC LIMIT 10");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
