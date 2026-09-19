<?php
// Request-local diagnostics: never log SQL text, parameters, cookies or credentials.
$GLOBALS['pos_metrics'] = [
    'request_id' => bin2hex(random_bytes(8)),
    'started' => (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
    'session_ms' => 0.0, 'connect_ms' => 0.0, 'sql_ms' => 0.0,
    'queries' => 0, 'slow_queries' => [], 'errors' => [],
];
if (!headers_sent()) header('X-Request-ID: ' . $GLOBALS['pos_metrics']['request_id']);

function pos_record_error(Throwable $error): void {
    $entry = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
    if ($error instanceof PDOException) {
        $entry['sqlstate'] = (string)($error->errorInfo[0] ?? $error->getCode());
        $entry['driver_code'] = (int)($error->errorInfo[1] ?? 0);
    }
    if (count($GLOBALS['pos_metrics']['errors']) < 5) $GLOBALS['pos_metrics']['errors'][] = $entry;
}

function pos_record_query(string $sql, float $started): void {
    $ms = (microtime(true) - $started) * 1000;
    $metrics =& $GLOBALS['pos_metrics'];
    $metrics['queries']++;
    $metrics['sql_ms'] += $ms;
    if ($ms >= (float)cfg('slow_query_ms', 300) && count($metrics['slow_queries']) < 5) {
        // A stable fingerprint maps back to source without exposing query values.
        $metrics['slow_queries'][] = ['hash' => substr(hash('sha256', $sql), 0, 16), 'ms' => round($ms, 1)];
    }
}

class PosStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        $started = microtime(true);
        try {
            return parent::execute($params);
        } catch (PDOException $error) {
            pos_record_error($error);
            throw $error;
        } finally {
            pos_record_query($this->queryString, $started);
        }
    }
}

class PosPDO extends PDO {
    // PHP 7.4 treats this attribute as a comment; PHP 8 uses it for PDO's
    // tentative union return type, which cannot be declared on PHP 7.4.
    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$fetchModeArgs) {
        $started = microtime(true);
        try {
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $error) {
            pos_record_error($error);
            throw $error;
        } finally {
            pos_record_query($query, $started);
        }
    }
    #[\ReturnTypeWillChange]
    public function exec($statement) {
        $started = microtime(true);
        try {
            return parent::exec($statement);
        } catch (PDOException $error) {
            pos_record_error($error);
            throw $error;
        } finally {
            pos_record_query($statement, $started);
        }
    }
}

function pos_wants_json(): bool {
    return strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || preg_match('#/(add-item-fast|send_order_print|close_order_print|cancel-table-order|order_numbers|ensure-tables|menu-data)(\.php)?$#',
            (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) === 1;
}

function pos_render_error(Throwable $error): void {
    pos_record_error($error);
    if (!headers_sent()) http_response_code(503);
    $message = 'სერვერთან ოპერაცია ვერ დადასტურდა. ხელახალ მოქმედებამდე გადაამოწმე მაგიდა ან ისტორია.';
    $id = $GLOBALS['pos_metrics']['request_id'];
    if (pos_wants_json()) {
        if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => $message, 'request_id' => $id], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (empty($GLOBALS['pos_header_rendered'])) render_header('შეცდომა');
    echo '<section class="card narrow error"><h1>ოპერაცია ვერ დადასტურდა</h1><p>' . h($message) . '</p><small>კოდი: ' . h($id) . '</small></section>';
    render_footer();
}

set_exception_handler('pos_render_error');
register_shutdown_function(static function (): void {
    $metrics = $GLOBALS['pos_metrics'];
    $elapsed = (microtime(true) - $metrics['started']) * 1000;
    $lastError = error_get_last();
    $fatal = $lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    if (!$fatal && !$metrics['errors'] && !$metrics['slow_queries'] && $elapsed < (float)cfg('slow_request_ms', 1000)) return;
    unset($metrics['started']);
    $metrics['event'] = 'pos_request';
    $metrics['method'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    // Do not include the query string or a path that could contain user input.
    $metrics['script'] = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $metrics['duration_ms'] = round($elapsed, 1);
    $metrics['session_ms'] = round($metrics['session_ms'], 1);
    $metrics['connect_ms'] = round($metrics['connect_ms'], 1);
    $metrics['sql_ms'] = round($metrics['sql_ms'], 1);
    $metrics['peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
    $metrics['status'] = http_response_code() ?: 200;
    if ($fatal) $metrics['fatal'] = ['file' => basename($lastError['file']), 'line' => $lastError['line'], 'type' => $lastError['type']];
    error_log('GARBALIA ' . json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
});
