<?php
/**
 * Runtime settings reader with DB-first fallback to environment variables.
 */
class RuntimeSettings {
    public static function get(string $key, $default = null) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT value_json FROM app_settings WHERE `key` = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if ($row && isset($row['value_json'])) {
                $decoded = json_decode($row['value_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
                return $row['value_json'];
            }
        } catch (Exception $e) {
            // Fall back to env/default if app_settings table does not exist yet.
        }

        $env = getenv($key);
        if ($env === false || $env === null || $env === '') {
            return $default;
        }
        return $env;
    }
}
