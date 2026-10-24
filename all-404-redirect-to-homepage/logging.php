<?php
/**
 * 404 log limits: logging switch, URL length cap, row cap, retention pruning
 * and trusted-proxy aware visitor IP detection.
 *
 * @since 5.8
 */
if (! defined('ABSPATH')) exit;

define('P404_LOG_DEFAULT_RETENTION_DAYS', 90);
define('P404_LOG_DEFAULT_MAX_ROWS', 50000);
define('P404_LOG_DEFAULT_MAX_URL_LENGTH', 1024);
define('P404_LOG_STATS_TRANSIENT', 'p404_log_stats');
define('P404_LOG_STATS_TTL', 5 * MINUTE_IN_SECONDS);
define('P404_PRUNE_HOOK', 'p404_prune_log');
define('P404_PRUNE_BATCH', 5000);
// Hits that are no longer in the table (pruned, or counted by <= 5.7 before upgrade),
// so the "All time" total keeps its meaning. Written once per prune, never per 404.
define('P404_HITS_OFFSET_OPTION', 'p404_hits_offset');

/**
 * Read a plugin option from an already-loaded options array without writing
 * defaults back to the database (P404REDIRECT_read_option_value() writes).
 */
function p404_opt($options, $key, $default)
{
	return (is_array($options) && isset($options[$key]) && $options[$key] !== '') ? $options[$key] : $default;
}

/** Logging is on unless the admin explicitly set it to "2" (disabled). */
function p404_logging_enabled($options = null)
{
	if ($options === null) {
		$options = P404REDIRECT_get_my_options();
	}
	return (string) p404_opt($options, 'p404_logging_status', '1') !== '2';
}

function p404_log_retention_days($options = null)
{
	if ($options === null) {
		$options = P404REDIRECT_get_my_options();
	}
	return absint(p404_opt($options, 'p404_log_retention_days', P404_LOG_DEFAULT_RETENTION_DAYS));
}

function p404_log_max_rows($options = null)
{
	if ($options === null) {
		$options = P404REDIRECT_get_my_options();
	}
	return absint(p404_opt($options, 'p404_log_max_rows', P404_LOG_DEFAULT_MAX_ROWS));
}

/**
 * A Unix timestamp formatted in site-local time, matching current_time('mysql')
 * which is how last_redirected is stored. Works on WP < 5.3 (no wp_date()).
 */
function p404_local_mysql_time($timestamp)
{
	$offset = (float) get_option('gmt_offset') * HOUR_IN_SECONDS;
	if (function_exists('wp_timezone')) {
		$offset = wp_timezone()->getOffset(new DateTime('@' . (int) $timestamp));
	}
	return gmdate('Y-m-d H:i:s', (int) $timestamp + (int) $offset);
}

/** URLs longer than this are redirected as usual but not logged. */
function p404_log_max_url_length()
{
	return max(64, (int) apply_filters('p404_log_max_url_length', P404_LOG_DEFAULT_MAX_URL_LENGTH));
}

/**
 * Row count and total hits of the log table, cached for a few minutes so the
 * front end never runs COUNT/SUM per request.
 *
 * @return array{rows:int,hits:int}
 */
function p404_get_log_stats($force = false)
{
	if (!$force) {
		$cached = get_transient(P404_LOG_STATS_TRANSIENT);
		if (is_array($cached) && isset($cached['rows'], $cached['hits'])) {
			return $cached;
		}
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'redirects_404';
	$row = $wpdb->get_row("SELECT COUNT(*) AS rows_count, COALESCE(SUM(count), 0) AS hits FROM {$table_name}", ARRAY_A);

	$table_hits = $row ? (int) $row['hits'] : 0;
	$stats = array(
		'rows' => $row ? (int) $row['rows_count'] : 0,
		'hits' => $table_hits + p404_get_hits_offset($table_hits),
	);
	set_transient(P404_LOG_STATS_TRANSIENT, $stats, P404_LOG_STATS_TTL);

	// Over the cap: don't wait for the daily run, trim soon in the background.
	$max_rows = p404_log_max_rows();
	if ($max_rows > 0 && $stats['rows'] > $max_rows && !wp_next_scheduled(P404_PRUNE_HOOK . '_now')) {
		wp_schedule_single_event(time() + 60, P404_PRUNE_HOOK . '_now');
	}

	return $stats;
}

/**
 * All-time hits not present in the table. On the first run after upgrading
 * from <= 5.7 it is seeded from the old 'links' counter so the total doesn't jump.
 */
function p404_get_hits_offset($table_hits = 0)
{
	$offset = get_option(P404_HITS_OFFSET_OPTION, false);
	if ($offset === false) {
		$options = P404REDIRECT_get_my_options();
		$legacy  = isset($options['links']) ? (int) $options['links'] : 0;
		$offset  = max(0, $legacy - (int) $table_hits);
		add_option(P404_HITS_OFFSET_OPTION, $offset, '', 'no');
	}
	return max(0, (int) $offset);
}

function p404_add_hits_offset($hits)
{
	if ($hits > 0) {
		update_option(P404_HITS_OFFSET_OPTION, p404_get_hits_offset() + (int) $hits, false);
	}
}

/**
 * Total logged 404 hits (replaces the old per-request 'links' counter).
 * Pass $fresh = true on admin screens to bypass the short cache.
 */
function p404_get_links_count($fresh = false)
{
	$stats = p404_get_log_stats($fresh);
	return $stats['hits'];
}

/** True when the table has reached the row cap: no new rows until pruned. */
function p404_log_is_full()
{
	$max_rows = p404_log_max_rows();
	if ($max_rows === 0) {
		return false;
	}
	$stats = p404_get_log_stats();
	return $stats['rows'] >= $max_rows;
}

/**
 * Delete rows older than the retention period, then trim the table down to the
 * row cap (least recently hit rows first). Runs in bounded batches.
 */
function p404_prune_log()
{
	global $wpdb;
	$table_name = $wpdb->prefix . 'redirects_404';
	$deadline   = time() + 20;
	$deleted    = 0;
	$sum_sql    = "SELECT COALESCE(SUM(count), 0) FROM {$table_name}";
	$hits_before = (int) $wpdb->get_var($sum_sql);

	$days = p404_log_retention_days();
	if ($days > 0) {
		// last_redirected is stored in site-local time (current_time('mysql')).
		$cutoff = p404_local_mysql_time(time() - $days * DAY_IN_SECONDS);
		do {
			$n = $wpdb->query($wpdb->prepare(
				"DELETE FROM {$table_name} WHERE last_redirected < %s LIMIT %d",
				$cutoff,
				P404_PRUNE_BATCH
			));
			$deleted += (int) $n;
		} while ($n === P404_PRUNE_BATCH && time() < $deadline);
	}

	$max_rows = p404_log_max_rows();
	if ($max_rows > 0 && time() < $deadline) {
		// Trim to 90% of the cap so new 404s can be logged again right away.
		$keep = max(1, (int) floor($max_rows * 0.9));
		// Oldest row we keep; ties on last_redirected (bursts share a second) are broken by id.
		$threshold = $wpdb->get_row($wpdb->prepare(
			"SELECT last_redirected, id FROM {$table_name} ORDER BY last_redirected DESC, id DESC LIMIT 1 OFFSET %d",
			$keep - 1
		));
		if ($threshold) {
			do {
				$n = $wpdb->query($wpdb->prepare(
					"DELETE FROM {$table_name}
					 WHERE last_redirected < %s OR (last_redirected = %s AND id < %d)
					 ORDER BY last_redirected ASC, id ASC LIMIT %d",
					$threshold->last_redirected,
					$threshold->last_redirected,
					$threshold->id,
					P404_PRUNE_BATCH
				));
				$deleted += (int) $n;
			} while ($n === P404_PRUNE_BATCH && time() < $deadline);
		}
	}

	if ($deleted > 0) {
		p404_add_hits_offset($hits_before - (int) $wpdb->get_var($sum_sql));
	}
	delete_transient(P404_LOG_STATS_TRANSIENT);
	return $deleted;
}
add_action(P404_PRUNE_HOOK, 'p404_prune_log');
add_action(P404_PRUNE_HOOK . '_now', 'p404_prune_log');

/** Make sure the daily prune is scheduled (covers updates, not just activation). */
function p404_schedule_prune()
{
	if (!wp_next_scheduled(P404_PRUNE_HOOK)) {
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', P404_PRUNE_HOOK);
	}
}
add_action('init', 'p404_schedule_prune');

function p404_unschedule_prune()
{
	wp_clear_scheduled_hook(P404_PRUNE_HOOK);
	wp_clear_scheduled_hook(P404_PRUNE_HOOK . '_now');
}

// ---------------------------------------------------------------------------
// Visitor IP
// ---------------------------------------------------------------------------

/** Does $ip match one of the IPs / CIDR ranges in $ranges (IPv4 or IPv6)? */
function p404_ip_in_ranges($ip, array $ranges)
{
	$ip_bin = @inet_pton($ip);
	if ($ip_bin === false) {
		return false;
	}
	foreach ($ranges as $range) {
		$range = trim((string) $range);
		if ($range === '') {
			continue;
		}
		$bits = null;
		if (strpos($range, '/') !== false) {
			list($range, $bits) = explode('/', $range, 2);
			$bits = (int) $bits;
		}
		$net_bin = @inet_pton($range);
		if ($net_bin === false || strlen($net_bin) !== strlen($ip_bin)) {
			continue;
		}
		$max_bits = strlen($ip_bin) * 8;
		if ($bits === null || $bits >= $max_bits) {
			if ($ip_bin === $net_bin) {
				return true;
			}
			continue;
		}
		if ($bits <= 0) {
			return true;
		}
		$bytes = (int) floor($bits / 8);
		$rem   = $bits % 8;
		if (substr($ip_bin, 0, $bytes) !== substr($net_bin, 0, $bytes)) {
			continue;
		}
		if ($rem === 0) {
			return true;
		}
		$mask = chr((0xFF << (8 - $rem)) & 0xFF);
		if ((($ip_bin[$bytes] ^ $net_bin[$bytes]) & $mask) === "\0") {
			return true;
		}
	}
	return false;
}

/**
 * Visitor IP for the log. REMOTE_ADDR is used unless the request came from a
 * configured trusted proxy, in which case the client IP is read from the
 * proxy's header (rightmost address that is not itself a trusted proxy).
 *
 * Configure with the P404_TRUSTED_PROXIES constant (array of IPs/CIDRs) or the
 * 'p404_trusted_proxies' filter; pick the header with 'p404_client_ip_header'
 * (default HTTP_X_FORWARDED_FOR; use HTTP_CF_CONNECTING_IP behind Cloudflare).
 */
function p404_get_visitor_ip()
{
	$remote = isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';
	if (!filter_var($remote, FILTER_VALIDATE_IP)) {
		$remote = 'unknown';
	}

	$trusted = defined('P404_TRUSTED_PROXIES') ? (array) P404_TRUSTED_PROXIES : array();
	$trusted = (array) apply_filters('p404_trusted_proxies', $trusted);

	$ip = $remote;
	if ($remote !== 'unknown' && !empty($trusted) && p404_ip_in_ranges($remote, $trusted)) {
		$header = (string) apply_filters('p404_client_ip_header', 'HTTP_X_FORWARDED_FOR');
		if (!empty($_SERVER[$header])) {
			$candidates = array_reverse(array_map('trim', explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])))));
			foreach ($candidates as $candidate) {
				if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
					break; // malformed entry: stop trusting the chain here
				}
				$ip = $candidate;
				if (!p404_ip_in_ranges($candidate, $trusted)) {
					break;
				}
			}
		}
	}

	return (string) apply_filters('p404_visitor_ip', $ip, $remote);
}
