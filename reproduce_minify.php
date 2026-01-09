<?php
// Mock WordPress Constants and Functions
define('ABSPATH', '/tmp/wps-cache/');
define('WPSC_CACHE_DIR', ABSPATH . 'cache/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_DEBUG', true);

function get_option($key, $default = []) {
    if ($key === 'wpsc_settings') {
        return [
            'js_minify' => 1,
            'excluded_js_minify' => [],
        ];
    }
    return $default;
}

function site_url($path = '') {
    return 'http://example.com' . $path;
}

function is_admin() { return false; }
function add_action() {}
function wp_enqueue_scripts() {}

// Mock AbstractCacheDriver
// (Since we are running this isolated, we need to load the abstract class or mock it)
// But since MinifyJS extends it, we need the file.
// We can use the actual file if we include it.
// But we need to handle namespaces.

// Simple Autoloader mock
spl_autoload_register(function ($class) {
    if (strpos($class, 'WPSCache\\') === 0) {
        $parts = explode('\\', $class);
        $file = __DIR__ . '/WPS-Cache/src/' . implode('/', array_slice($parts, 1)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        } else {
             // Try to map Cache/Drivers
             // The namespace WPSCache\Cache\Drivers maps to src/Cache/Drivers
        }
    }
});

require_once __DIR__ . '/WPS-Cache/src/Cache/Drivers/CacheDriverInterface.php';
require_once __DIR__ . '/WPS-Cache/src/Cache/Drivers/AbstractCacheDriver.php';
require_once __DIR__ . '/WPS-Cache/src/Cache/Drivers/MinifyJS.php';

use WPSCache\Cache\Drivers\MinifyJS;

// Ensure cache dir exists
if (!is_dir(WPSC_CACHE_DIR . 'js/')) {
    mkdir(WPSC_CACHE_DIR . 'js/', 0777, true);
}

// Test JS Content
$js = <<<'JS'
function hello(name) {
    // This is a comment
    /* Multi
       Line
       Comment */
    var a = 1;
    let b = "string with / slash";
    const c = `template literal ${a}`;
    if (a > 0) {
        return a / 2; // division
    }
    var regex = /abc/g;
    return name + "!";
}
JS;

// Reflection to access private method minifyJS
$minify = new MinifyJS();
$ref = new ReflectionMethod(MinifyJS::class, 'minifyJS');
$ref->setAccessible(true);

$start = microtime(true);
$output = $ref->invoke($minify, $js);
$end = microtime(true);

echo "Original Size: " . strlen($js) . "\n";
echo "Minified Size: " . strlen($output) . "\n";
echo "Time: " . ($end - $start) . "\n";
echo "Output: " . $output . "\n";

// Complex test case with all operators
$complexJs = <<<'JS'
var x = 10;
x++;
x--;
x += 5;
x = x * 2;
x = x / 2;
x = x % 3;
x = x & 1;
x = x | 2;
x = x ^ 3;
x = ~x;
x = !x;
x = (x > 0) ? 1 : 0;
var arr = [1, 2, 3];
var obj = { a: 1, b: 2 };
function foo() { return; }
class A { constructor() {} }
var str = "foo 'bar' \"baz\"";
var tpl = `foo ${x}`;
var re = /ab+c/i;
JS;

$outputComplex = $ref->invoke($minify, $complexJs);
echo "Complex Output: " . $outputComplex . "\n";

file_put_contents('reproduce_output.txt', $output . "\n" . $outputComplex);
