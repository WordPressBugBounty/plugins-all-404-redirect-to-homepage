<?php
if (! defined('ABSPATH')) exit; // Exit if accessed directly
function setup_redirects_table_and_migrate()
{
	global $wpdb;

	// Log activation hook
	error_log("setup_redirects_table_and_migrate: Activation hook triggered.");

	// Step 1: Create the redirects table
	if (!create_redirects_table()) {
		error_log("setup_redirects_table_and_migrate: Table creation failed.");
		return;
	}

	// Step 2: Migrate data from the old `redirected_links` option
	migrate_redirected_links();

	error_log("setup_redirects_table_and_migrate: Migration process completed.");
}




function p404_customizer_admin_inline_styles() {
    // Check if we are in the Customizer page
    if (is_customize_preview()) {
        ?>
        <style>
            .accordion-section-title button.accordion-trigger {
                /* Add your desired styles for Customizer accordion button here */
               height:auto !important
			}
        </style>
        <?php
    }
}
add_action('customize_controls_print_styles', 'p404_customizer_admin_inline_styles');




function create_redirects_table()
{
	global $wpdb;
	$table_name = $wpdb->prefix . 'redirects_404';

	// Check if the table already exists
	if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
		error_log("create_redirects_table: Table `$table_name` already exists.");
		return true;
	}

	// Create the table
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        url TEXT NOT NULL,
        count INT(11) NOT NULL DEFAULT 1,
        last_redirected DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY url (url(191))
    ) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);

	// Verify table creation
	if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
		error_log("create_redirects_table: Table `$table_name` created successfully.");
		return true;
	} else {
		error_log("create_redirects_table: Failed to create table `$table_name`.");
		return false;
	}
}

function migrate_redirected_links()
{
	global $wpdb;
	$table_name = $wpdb->prefix . 'redirects_404';
	$option_name = 'options-404-redirect-group';
	$option_value = get_option($option_name);

	// Check if there are any links to migrate
	if (empty($option_value['redirected_links'])) {
		error_log("migrate_redirected_links: No `redirected_links` data found to migrate.");
		return;
	}

	foreach ($option_value['redirected_links'] as $redirect) {
		$url = $redirect['link'];
		$date = date("Y-m-d H:i:s", strtotime($redirect['date']));

		// Check if the URL already exists in the table
		$existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE url = %s", $url));

		if ($existing) {
			// Update the count and last_redirected for existing URLs
			$wpdb->update(
				$table_name,
				[
					'count' => $existing->count + 1,
					'last_redirected' => $date
				],
				['id' => $existing->id]
			);
			error_log("migrate_redirected_links: Updated URL `$url` with count " . ($existing->count + 1) . ".");
		} else {
			// Insert new URL records
			$wpdb->insert(
				$table_name,
				[
					'url' => $url,
					'count' => 1,
					'last_redirected' => $date
				]
			);
			error_log("migrate_redirected_links: Inserted new URL `$url`.");
		}
	}

	// Remove `redirected_links` from the options
	unset($option_value['redirected_links']);
	update_option($option_name, $option_value);
	error_log("migrate_redirected_links: `redirected_links` removed from `$option_name`.");
}

function p404_migrate_options()
{
	// Check if migration is already completed
	$migration_status = get_option('p404_migration_status', '0'); // Default: 0 (not migrated)

	if ($migration_status === '0') {
		// Perform migration
		$options = get_option(OPTIONS404, array());

		if (!isset($options['p404_redirect_type'])) {
			$options['p404_redirect_type'] = '301'; // Default: Permanent Redirect
		}

		// Save the updated options
		update_option(OPTIONS404, $options);
		setup_redirects_table_and_migrate();
		// Update the migration status to `1` (migrated)
		update_option('p404_migration_status', '1');

		// Log migration
		error_log("P404 Migration completed successfully.");
	} else {
		// Log that migration was skipped
		error_log("P404 Migration skipped. Already completed.");
	}
}
add_action('plugins_loaded', 'p404_migrate_options');


function P404REDIRECT_HideMsg()
{
	add_option('P404REDIRECT_upgrade_msg', 'hidemsg');
}

function P404REDIRECT_HideAlert()
{

	update_option('P404_alert_msg', 'hidemsg');
}
add_action('admin_post_clear_redirects_log', 'clear_redirects_log_handler');

function clear_redirects_log_handler()
{
	// Verify the nonce for security
	if (!isset($_POST['clear_redirects_nonce']) || !wp_verify_nonce($_POST['clear_redirects_nonce'], 'clear_redirects_log')) {
		wp_die('Security check failed.');
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'redirects_404';

	// Clear the table data
	$wpdb->query("TRUNCATE TABLE $table_name");

	// Reset the `links` count in options
	$options = P404REDIRECT_get_my_options();
	$options['links'] = 0;
	P404REDIRECT_update_my_options($options);

	// Redirect back to the 404 URLs tab with a success message
	wp_redirect(admin_url('admin.php?page=all-404-redirect-to-homepage.php&mytab=404urls'));
	exit;
}

function sample_admin_notice__error()
{
	$class = 'notice notice-error';
	$links_count = P404REDIRECT_read_option_value('links', 0);


	if (get_option('P404_alert_msg') != 'hidemsg' && $links_count > 10) {

		$message = __('<h3>All 404 Redirect to Homepage</h3><b>Warning</b>, You have many broken links that hurt your site\'s rank in search engines, <a target="_blank" href="https://www.wp-buy.com/product/seo-redirection-premium-wordpress-plugin/#fix404links">UPGRADE</a> your plugin and empower your site\'s SEO.&nbsp; <span id="Hide404Alert" style="cursor:pointer" ><a href="javascript:void(0)"><strong> Dismiss</strong></a> this message</span> or check the plugin <a href="' . admin_url('admin.php?page=all-404-redirect-to-homepage.php') . '"><b>settings</b></a>.', 'sample-text-domain');

		printf('<div id="all404upgradeMsg" class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);


?>
		<script type="text/javascript">
			jQuery(document).ready(function() {

				jQuery("#Hide404Alert").click(function() {
					jQuery.ajax({
						type: 'POST',
						url: '<?php echo admin_url(); ?>/admin-ajax.php',
						data: {
							action: 'P404REDIRECT_HideAlert'
						},
						success: function(data, textStatus, XMLHttpRequest) {

							jQuery("#all404upgradeMsg").hide();

						},
						error: function(MLHttpRequest, textStatus, errorThrown) {
							alert(errorThrown);
						}
					});
				});

			});
		</script>

	<?php
	}
}
add_action('admin_notices', 'sample_admin_notice__error');

function P404REDIRECT_after_plugin_row($plugin_file, $plugin_data, $status)
{
	if (get_option('P404REDIRECT_upgrade_msg') != 'hidemsg') {
		$class_name = isset($plugin_data['slug']) ? $plugin_data['slug'] : 'all-404-redirect-to-homepage'; // $plugin_data is an array retrived by default when you action this function after_plugin_row

		echo '<tr id="' . esc_attr($class_name) . '-plugin-update-tr" class="plugin-update-tr active">';
		echo '<td  colspan="6" class="plugin-update">';
		echo '<div id="' . esc_attr($class_name) . '-upgradeMsg" class="update-message" style="background:#FFF8E5; padding-left:10px; border-left:#FFB900 solid 4px" >';

		echo '<span style="color:red">Have many broken links?</span>.<br />keep track of 404 errors using our powerfull <a target="_blank" href="https://www.wp-buy.com/product/seo-redirection-premium-wordpress-plugin/">SEO Redirection Plugin</a> to show and fix all broken links & 404 errors that occur on your site. or ';

		echo '<span id="HideMe" style="cursor:pointer" ><a href="javascript:void(0)"><strong> Dismiss</strong></a> this message</span>';
		echo '</div>';
		echo '</td>';
		echo '</tr>';
	}
	?>
	<script type="text/javascript">
		jQuery(document).ready(function() {
			var row = jQuery('#<?php echo esc_attr($class_name); ?>-plugin-update-tr').closest('tr').prev();
			jQuery(row).addClass('update');

			jQuery("#HideMe").click(function() {
				jQuery.ajax({
					type: 'POST',
					url: '<?php echo admin_url(); ?>/admin-ajax.php',
					data: {
						action: 'P404REDIRECT_HideMsg'
					},
					success: function(data, textStatus, XMLHttpRequest) {

						jQuery("#<?php echo esc_attr($class_name); ?>-upgradeMsg").hide();

					},
					error: function(MLHttpRequest, textStatus, errorThrown) {
						alert(errorThrown);
					}
				});
			});

		});
	</script>
<?php
}

function P404REDIRECT_get_current_URL()
{
	$prt = $_SERVER['SERVER_PORT'];
	$sname = $_SERVER['SERVER_NAME'];

	if (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'] != 'off' && $_SERVER['HTTPS'] != '')
		$sname = "https://" . $sname;
	else
		$sname = "http://" . $sname;

	if ($prt != 80 && $prt != 443) {
		$sname = $sname . ":" . $prt;
	}

	$path = $sname . $_SERVER["REQUEST_URI"];

	return $path;
}

//----------------------------------------------------
function P404REDIRECT_migrate_existing_options()
{
	// Fetch the current options
	$options = get_option(OPTIONS404, array());

	// Add new options if they are missing
	if (!isset($options['p404_logging_status'])) {
		$options['p404_logging_status'] = '1'; // Default: enabled
	}
	if (!isset($options['p404_logging_expiration_date'])) {
		$options['p404_logging_expiration_date'] = '1'; // Default: 1 month
	}
	if (!isset($options['p404_redirect_type'])) {
		$options['p404_redirect_type'] = '301'; // Default: Permanent Redirect
	}

	// Save the updated options back to the database
	update_option(OPTIONS404, $options);
}

function P404REDIRECT_init_my_options()
{
	add_option(OPTIONS404);

	// Initialize default options
	$options = array();
	$options['p404_redirect_to'] = site_url();
	$options['p404_status'] = '1';
	$options['img_p404_status'] = '2';
	$options['p404_execlude_media'] = '1';
	$options['links'] = 0;
	$options['install_date'] = date("Y-m-d h:i a");
	$options['p404_redirect_type'] = '301';


	update_option(OPTIONS404, $options);
}

//---------------------------------------------------- 

function P404REDIRECT_update_my_options($options)
{
	update_option(OPTIONS404, $options);
}

//---------------------------------------------------- 

function P404REDIRECT_get_my_options()
{
	$options = get_option(OPTIONS404);
	if (!$options) {
		P404REDIRECT_init_my_options();
		$options = get_option(OPTIONS404);
	}
	return $options;
}

/* read_option_value -------------------------------------------------  */
function P404REDIRECT_read_option_value($key, $default = '')
{
	$options = P404REDIRECT_get_my_options();
	if (array_key_exists($key, $options)) {
		return $options[$key];
	} else {
		P404REDIRECT_save_option_value($key, $default);
		return $default;
	}
}

/* save_option_value -------------------------------------------------  */
function P404REDIRECT_save_option_value($key, $value)
{
	$options = P404REDIRECT_get_my_options();
	$options[$key] = $value;
	P404REDIRECT_update_my_options($options);
}

/* add link -------------------------------------------------  */
function P404REDIRECT_add_redirected_link($link)
{
	global $wpdb;

	// Increment the redirect count in options
	$links = P404REDIRECT_read_option_value('links', 0);
	P404REDIRECT_save_option_value('links', $links + 1);

	// Define the table name
	$table_name = $wpdb->prefix . 'redirects_404';

	// Check if the URL already exists in the table
	$existing_redirect = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE url = %s", $link));

	if ($existing_redirect) {
		// If the URL exists, increment the redirect count and update the last redirected time
		$wpdb->update(
			$table_name,
			array(
				'count' => $existing_redirect->count + 1, // Increment the redirect count
				'last_redirected' => current_time('mysql') // Update the last redirected time
			),
			array('id' => $existing_redirect->id),
			array('%d', '%s'), // Data formats
			array('%d') // WHERE format (ID)
		);
	} else {
		// If the URL doesn't exist, insert a new record
		$wpdb->insert(
			$table_name,
			array(
				'url' => $link,                        // The redirected URL
				'count' => 1,                  // First redirect
				'last_redirected' => current_time('mysql') // Time of first redirect
			),
			array('%s', '%d', '%s') // Data formats
		);
	}
}



//---------------------------------------------------- 
function P404REDIRECT_option_msg($msg)
{
	echo '<div id="message" class="updated"><p>' . esc_attr($msg) . '</p></div>';
}

//---------------------------------------------------- 
function P404REDIRECT_info_option_msg($msg)
{
	echo '<div id="message" class="updated"><p><div class="info_icon"></div> ' . esc_attr($msg) . '</p></div>';
}

//---------------------------------------------------- 
function P404REDIRECT_warning_option_msg($msg)
{
	echo '<div id="message" class="error"><p><div class="warning_icon"></div> ' . esc_attr($msg) . '</p></div>';
}

//---------------------------------------------------- 

function P404REDIRECT_success_option_msg($msg)
{
	echo '<div id="message" class="updated"><p><div class="success_icon"></div> ' . esc_attr($msg) . '</p></div>';
}

//---------------------------------------------------- 

function P404REDIRECT_failure_option_msg($msg)
{
	echo '<div id="message" class="error"><p><div class="failure_icon"></div> ' . esc_attr($msg) . '</p></div>';
}


//----------------------------------------------------
//** updated 2/2/2020
function P404REDIRECT_there_is_cache()
{
	$plugins = get_site_option('active_plugins');
	if (is_array($plugins)) {
		foreach ($plugins as $the_plugin) {
			if (stripos($the_plugin, 'cache') !== false) {
				return $the_plugin;
			}
		}
	}
	return '';
}
