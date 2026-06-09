<?php
/**
 * Minimal WordPress function/class stubs so the plugin's *pure-logic* classes
 * (Validator, Element_Factory) can be unit-tested with bare PHP — no full WP,
 * no PHPUnit, no composer. Only the functions actually reached by the code under
 * test are stubbed; anything WP-runtime-specific stays out of these tests.
 */

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct($code = '', $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }
}

/**
 * Test-controllable uploads basedir. Tests set $GLOBALS['__test_upload_basedir'].
 */
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array {
        $base = $GLOBALS['__test_upload_basedir'] ?? sys_get_temp_dir();
        return [
            'basedir' => $base,
            'path'    => $base,
            'url'     => 'http://example.test/uploads',
        ];
    }
}

if (!function_exists('wp_check_filetype')) {
    function wp_check_filetype(string $filename): array {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',  'gif'  => 'image/gif',
            'webp' => 'image/webp', 'svg'  => 'image/svg+xml',
            'avif' => 'image/avif', 'php'  => false, 'txt' => false,
        ];
        $type = $map[$ext] ?? false;
        return ['ext' => $type ? $ext : false, 'type' => $type ?: null];
    }
}
