<?php
/**
 * WordPress Password Compatibility
 * Handles WordPress password hashing for migrated users
 */

class WordPressPassword {
    /**
     * Check if password hash is WordPress format
     */
    public static function isWordPressHash($hash) {
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        // Treat only explicit WordPress hash families as "legacy WP".
        // Plain PHP bcrypt hashes ($2y$ / $2a$) are considered modern hashes
        // and should not force another reset after a successful password change.
        return strpos($hash, '$P$') === 0 ||
               strpos($hash, '$H$') === 0 ||
               strpos($hash, '$wp$2a$') === 0 ||
               strpos($hash, '$wp$2y$') === 0;
    }
    
    /**
     * Verify WordPress password
     * WordPress uses phpass library which uses bcrypt with portable hashes
     */
    public static function checkPassword($password, $hash) {
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        // Legacy imports may still contain unsalted MD5 hashes.
        if (preg_match('/^[a-f0-9]{32}$/i', $hash)) {
            return hash_equals(strtolower($hash), md5($password));
        }

        // Newer WordPress hashes may be prefixed with "$wp$".
        if (strpos($hash, '$wp$') === 0) {
            $hash = substr($hash, 3);
        }

        // If it's not a WordPress hash, use standard PHP password_verify
        if (!self::isWordPressHash($hash)) {
            return password_verify($password, $hash);
        }
        
        // WordPress portable hash format: $P$B...
        if (strpos($hash, '$P$') === 0 || strpos($hash, '$H$') === 0) {
            return self::checkPortableHash($password, $hash);
        }
        
        // WordPress bcrypt format: $2a$ or $2y$
        if (strpos($hash, '$2a$') === 0 || strpos($hash, '$2y$') === 0) {
            return password_verify($password, $hash);
        }
        
        return false;
    }
    
    /**
     * Check WordPress portable hash
     * This is a simplified version - WordPress uses phpass library
     * For production, you might want to use a library or implement full phpass
     */
    private static function checkPortableHash($password, $hash) {
        // Extract iteration count and salt from hash
        if (strlen($hash) < 12) {
            return false;
        }
        
        $count_log2 = strpos('./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $hash[3]);
        if ($count_log2 < 7 || $count_log2 > 30) {
            return false;
        }
        
        $count = 1 << $count_log2;
        $salt = substr($hash, 4, 8);
        
        if (strlen($salt) !== 8) {
            return false;
        }
        
        // Hash the password
        $hashed = md5($salt . $password);
        for ($i = 0; $i < $count; $i++) {
            $hashed = md5($hashed . $password);
        }
        
        // Format the hash
        $output = substr($hash, 0, 12);
        $output .= self::encode64($hashed, 16);
        
        return hash_equals($hash, $output);
    }
    
    /**
     * Encode 64 characters (WordPress phpass format)
     */
    private static function encode64($input, $count) {
        $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= $itoa64[$value & 0x3f];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= $itoa64[($value >> 6) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $output .= $itoa64[($value >> 12) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            $output .= $itoa64[($value >> 18) & 0x3f];
        } while ($i < $count);
        
        return $output;
    }
}
