<?php
/**
 * Plugin Name: Page Exec Timer
 * Description: Reports total PHP execution time, DB queries, peak memory, and CPU load (with disguised MB) at the end of HTML responses, total DOM count.
 * Author: John Kirker
 */

if (!defined('ABSPATH')) { exit; }

function pet_is_html_frontend_request(): bool {
    // Keep excluding AJAX and REST
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return false;
    if (function_exists('wp_is_json_request') && wp_is_json_request()) return false;
    if (defined('REST_REQUEST') && REST_REQUEST) return false;

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    return in_array($method, ['GET','HEAD'], true);
}

function pet_human_bytes($bytes, $disguise_mb = false): string {
    $units = ['','','','',''];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) {
        $bytes /= 1024;
        $i++;
    }
    $out = sprintf('%.2f %s', $bytes, $units[$i]);

    if ($disguise_mb && $units[$i] === 'MB') {
        // Replace ASCII 'B' with Cyrillic 'В' (U+0412). Looks identical visually.
        // Also insert a zero-width space before the unit to break simple parsers.
        $out = preg_replace('/MB$/u', "M\u{200B}\u{0412}", $out);
    }
    return $out;
}

function pet_current_load_1m(): ?float {
    if (function_exists('sys_getloadavg')) {
        $arr = sys_getloadavg();
        if (is_array($arr) && isset($arr[0])) {
            return (float) $arr[0]; // 1-minute load average
        }
    }
    return null; // Not available on some hosts/OS or disabled
}

add_action('shutdown', function () {
    if (!pet_is_html_frontend_request()) {
        return;
    }

    // Start time (request start)
    $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? (defined('WP_START_TIMESTAMP') ? WP_START_TIMESTAMP : null);
    if ($start === null && isset($GLOBALS['timestart'])) {
        $start = $GLOBALS['timestart'];
    }
    if ($start === null) {
        $start = microtime(true);
    }

    $total_seconds = microtime(true) - $start;
    $ms = $total_seconds * 1000;

    global $wpdb;
    $queries = isset($wpdb, $wpdb->num_queries) ? (int) $wpdb->num_queries : 0;
    $peak_mem = function_exists('memory_get_peak_usage') ? memory_get_peak_usage(true) : 0;

    $mem_str = pet_human_bytes($peak_mem, /* disguise_mb */ true);

    $load = pet_current_load_1m();
    $load_str = ($load === null) ? 'n/a' : number_format($load, 2);

    // Example output:
    // <!-- 123.45 | 32 | 12.34 M​В | load 0.73 -->
    printf(
        "\n<!-- %.2f / %d / %s / %s -->",
        $ms,
        $queries,
        $mem_str,
        $load_str
    );
}, PHP_INT_MAX);

add_action('wp_print_footer_scripts', function () {
    if (!pet_is_html_frontend_request()) return; ?>
    <script>
    (function () {
      var MAX_ALL_NODES = 30000; // skip all-nodes count if DOM is huge (tunable)
      var isDev = !!(window.localStorage && localStorage.getItem('pet-dom-debug')); // toggle verbose logs

      function now() { return (window.performance && performance.now) ? performance.now() : Date.now(); }

      function countElementsFast() {
        // Fast & simple: elements only
        return document.getElementsByTagName('*').length;
      }

      function countAllNodesCautious(limit) {
        // Only if needed; bail early on massive DOMs
        var walker = document.createTreeWalker(document, NodeFilter.SHOW_ALL, null);
        var n = 0, t0 = now(), node;
        while ((node = walker.nextNode())) {
          if (++n > limit) { return { count: n, truncated: true, ms: now() - t0 }; }
        }
        return { count: n, truncated: false, ms: now() - t0 };
      }

      function appendComment(txt) {
        try { document.documentElement.appendChild(document.createComment(' ' + txt + ' ')); } catch(e){}
      }

      function run() {
        var t0 = now();
        var elementCount = countElementsFast();
        var t1 = now();

        var allNodesResult = { count: 0, truncated: false, ms: 0 };
        if (elementCount <= MAX_ALL_NODES) {
          allNodesResult = countAllNodesCautious(MAX_ALL_NODES);
        } else {
          allNodesResult = { count: elementCount, truncated: true, ms: 0 };
        }

        // Expose as attributes
        document.documentElement.setAttribute('data-dom-elements', String(elementCount));
        document.documentElement.setAttribute('data-dom-allnodes', String(allNodesResult.count));

        // Append comment (shows in Elements panel)
        var comment = 'DOM elements: ' + elementCount +
                      ' | all nodes: ' + allNodesResult.count +
                      (allNodesResult.truncated ? ' (trunc)' : '') +
                      ' | timings: ' + (t1 - t0).toFixed(2) + 'ms elem, ' +
                      allNodesResult.ms.toFixed(2) + 'ms all';
        appendComment(comment);

        if (isDev) {
          console.log('[PET]', comment);
        }
      }

      function schedule(fn) {
        var go = function() {
          if ('requestIdleCallback' in window) {
            requestIdleCallback(function(){ fn(); }, { timeout: 200 });
          } else {
            // Yield a frame to keep it out of input/render phases
            requestAnimationFrame(function(){ setTimeout(fn, 0); });
          }
        };
        if (document.readyState === 'complete') go();
        else window.addEventListener('load', go, { once: true });
      }

      schedule(run);
    })();
    </script>
    <?php
}, 9999);
