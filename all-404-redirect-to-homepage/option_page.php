<?php

if (! defined('ABSPATH')) exit; // Exit if accessed directly

include_once "cf_dropdown.php";

global $wpdb, $table_prefix;

$redirect_to = (isset($_POST['redirect_to'])) ? sanitize_text_field($_POST['redirect_to']) : '';
$nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field($_REQUEST['_wpnonce']) : '';

if ($redirect_to !== '') {
    if (wp_verify_nonce($nonce, 'p404home_nounce')) {
        P404REDIRECT_save_option_value('p404_execlude_media', sanitize_text_field($_POST['p404_execlude_media']));
        P404REDIRECT_save_option_value('p404_redirect_to', $redirect_to);
        P404REDIRECT_save_option_value('p404_status', sanitize_text_field($_POST['p404_status']));
        P404REDIRECT_save_option_value('img_p404_status', sanitize_text_field($_POST['img_p404_status']));
        P404REDIRECT_save_option_value('image_id_p404_redirect_to', sanitize_text_field($_POST['misha-img']));
        P404REDIRECT_save_option_value('p404_redirect_type', sanitize_text_field($_POST['p404_redirect_type'])); // Save Redirect Type
        if (isset($_POST['img_p404_status']) && $_POST['img_p404_status'] == 1) {
            if (isset($_POST['misha-img']) && $_POST['misha-img'] != '') {
                $mod_file = p404_modify_htaccess();

                if ($mod_file['status']) {
                    P404REDIRECT_option_msg('Options Saved!');
                } else {
?>
                    <div class="no_access">
                        Your .htaccess file is not writable; You need to modify the file manually.<br>
                        Please copy and paste the following code into the first line of the .htaccess file <br>
                        <code>
                            <?php
                            $image = wp_get_attachment_image_src(absint($_POST['misha-img']));
                            $image = $image[0];

                            ?>
                            <br><br>
                            RewriteOptions inherit<br>
                            &#60;IfModule mod_rewrite.c><br>
                            RewriteEngine on<br>
                            RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI} !-f<br>
                            RewriteRule \.(gif|jpe?g|png|bmp) <?php echo esc_url($image); ?> [NC,L]<br>
                            &#60;/IfModule><br>

                        </code><br><br>
                    </div>
<?php
                }
            } else {
                P404REDIRECT_warning_option_msg('Please disable the broken images feature or upload a new image.');
            }
        } else {
            p404_clear_htaccess();
            P404REDIRECT_option_msg('Options Saved!');
        }
    } else {
        P404REDIRECT_failure_option_msg('Unable to save data!');
    }
}
$options = P404REDIRECT_get_my_options();
?>

<?php
if (P404REDIRECT_there_is_cache() != '') {
    P404REDIRECT_info_option_msg(
        "You have a cache plugin installed <b>'" . P404REDIRECT_there_is_cache() . "'</b>. You have to clear cache after any changes to get the changes reflected immediately!"
    );
}
?>
<style>
    .nav-tab-active,
    .nav-tab-active:focus,
    .nav-tab-active:focus:active,
    .nav-tab-active:hover {
        border-bottom: 1px solid #fff;
        background: #fff;
        color: #000;
    }

    #tabs_content {
        padding: 20px;
        background-color: #fff;
        border: 1px solid #ccc;
        border-top: 0;
    }

    a.misha-upl>span {
        text-decoration: underline;
    }

    a.misha-rmv {
        text-decoration: underline;
    }

    .no_access {
        background-color: #f7dbdb;
        width: 98%;
        border: 2px dashed red;
        padding: 20px;
        font-size: 15px;
        font-weight: bold;
    }

    .form-input-titles {
        width: 300px;
        line-height: 40px;
    }
</style>

<div class="wrap">
    <div>
        <div class='inner'>
            <h2>All 404 Redirect to Homepage</h2>

            <?php
            $mytab = isset($_REQUEST['mytab']) ? sanitize_text_field($_REQUEST['mytab']) : "options";

            ?>

            <nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
                <a href="?page=all-404-redirect-to-homepage.php&mytab=options" class="nav-tab <?php if ($mytab == 'options') echo 'nav-tab-active'; ?>">Options</a>
                <a href="?page=all-404-redirect-to-homepage.php&mytab=404urls" class="nav-tab <?php if ($mytab == '404urls') echo 'nav-tab-active'; ?>">404 URLs</a>
            </nav>
            <div id="tabs_content">
                <?php
                // ----   Options Tab ----------------
                if ($mytab == "options") {
                ?>
                    <form method="POST">
                        <h3>404 Pages</h3>
                        <div class="form-input-titles">404 Redirection Status:</div>
                        <?php
                        $drop = new p404redirect_dropdown('p404_status');
                        $drop->add('Enabled', '1');
                        $drop->add('Disabled', '2');
                        $drop->dropdown_print();
                        $drop->select($options['p404_status']);


                        ?>

                        <br /><br />

                        <div class="form-input-titles">Redirect all 404 pages to:</div>
                        <input type="text" name="redirect_to" id="redirect_to" size="30" value="<?php echo esc_attr($options['p404_redirect_to']); ?>">


                        <br /><br />
                        <div class="form-input-titles">Select Redirect Type:</div>
                        <?php
                        $drop = new p404redirect_dropdown('p404_redirect_type');
                        $drop->add('301 Permanent Redirect', '301');
                        $drop->add('302 Temporary Redirect', '302');
                        $drop->dropdown_print();

                        $redirect_type = isset($options['p404_redirect_type']) ? $options['p404_redirect_type'] : '301'; // Default to 301
                        $drop->select($redirect_type);
                        ?>



                        <br /><br />
                        <div class="form-input-titles">Prevent logging media links:</div>
                        <?php


                        $drop = new p404redirect_dropdown('p404_execlude_media');
                        $drop->add('Yes', '1');
                        $drop->add('No', '2');
                        $drop->dropdown_print();
                        $p404_execlude_media_val = '1';
                        if (isset($options['p404_execlude_media'])) {
                            $p404_execlude_media_val = $options['p404_execlude_media'];
                        }
                        $drop->select($p404_execlude_media_val);

                        ?>

                        <br /><br />

                        <h3>404 Images</h3>
                        <div class="form-input-titles">Image 404 Redirection Status:</div>
                        <?php
                        $drop = new p404redirect_dropdown('img_p404_status');
                        $drop->add('Enabled', '1');
                        $drop->add('Disabled', '2');
                        $drop->dropdown_print();
                        $image_status_val = '2';
                        if (isset($options['img_p404_status'])) {
                            $image_status_val = $options['img_p404_status'];
                        }
                        $drop->select($image_status_val);
                        ?>
                        <b>Try our <a style="color:red" href="https://wordpress.org/plugins/broken-images-redirection" target="_blank">Broken Images Redirection</a> Plugin for more options</b>

                        <br /><br />

                        <div id="myimage404_lable">
                            Redirect all 404 images to:
                        </div>
                        <?php
                        $image_id = isset($options['image_id_p404_redirect_to']) ? absint($options['image_id_p404_redirect_to']) : '';
                        // if( !wp_get_attachment_image_src($image_id) &&  $image_id == '') {

                        ?>
                        <a href="#" class="misha-upl"><span>Upload image</span></a>
                        <a href="#" onclick="javascript:document.getElementById('myimage404').style.display='none'; document.getElementById('myimage404_lable').style.display='none'; " class="misha-rmv" style="display:none">Remove image</a>
                        <input type="hidden" class="misha-img" name="misha-img" value="">
                        <?php
                        //}
                        ?>
                        <br />

                        <?php
                        if (wp_get_attachment_image_src($image_id) &&  $image_id != '') {
                            $image = wp_get_attachment_image_src($image_id);
                        ?>

                            <br><a href="#" class="misha-upl"><img id="myimage404" src="<?php echo esc_url($image[0]); ?>" style="max-width: 80px;" /></a><br>
                            <a href="#" onclick="javascript:document.getElementById('myimage404').style.display='none'; document.getElementById('myimage404_lable').style.display='none'; " class="misha-rmv">Remove image</a>
                            <input type="hidden" class="misha-img" name="misha-img" value="<?php esc_attr_e($image_id); ?>">
                        <?php
                        } ?>
                        <br /><br />

                        <input type="hidden" id="_wpnonce" name="_wpnonce" value="<?php echo $nonce = wp_create_nonce('p404home_nounce'); ?>" />
                        <br />
                        <br />
                        <hr>
                        <br />
                        <input class="button-primary" type="submit" value="  Update Options  " name="Save_Options">
                    </form>
                <?php
                } else if ($mytab == "404urls") {
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'redirects_404';

                    // Fetch the total number of redirects (stored in the `links` option)
                    $total_redirects = P404REDIRECT_read_option_value('links', 0);

                    // Fetch the latest 20 redirected URLs from the database
                    $latest_redirects = $wpdb->get_results(
                        "SELECT * FROM {$table_name} ORDER BY last_redirected DESC LIMIT 20",
                        ARRAY_A
                    );
                ?>

                    <!-- Clear the Log Button -->
                    <button id="openModal" class="button-danger">
                        Clear All Discoverd
                    </button>
                    <div>
                        <hr />
                        <b style="color:red"><?php echo esc_html($total_redirects); ?></b>
                        is the number of redirects that were made to many incorrect links on your site since the plugin install in
                        <?php echo esc_attr(P404REDIRECT_read_option_value('install_date', date("Y-m-d h:i a"))); ?>
                        <hr />
                        <b>Latest 20 URLs Redirected:</b><br /><br />

                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th width="30">#</th>
                                    <th width="150">Date</th>
                                    <th>URL</th>
                                    <th width="30">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($latest_redirects)) { ?>
                                    <tr>
                                        <td colspan="4">No 404 links redirected yet.</td>
                                    </tr>
                                    <?php } else {
                                    foreach ($latest_redirects as $index => $redirect) { ?>
                                        <tr>
                                            <td><?php echo (int) $index + 1; ?></td>
                                            <td><?php echo esc_html($redirect['last_redirected']); ?></td>
                                            <td>
                                                <a target="_blank" href="<?php echo esc_url($redirect['url']); ?>">
                                                    <?php echo esc_url($redirect['url']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo esc_html($redirect['count']); ?></td>

                                        </tr>
                                <?php }
                                } ?>
                            </tbody>
                        </table>
                        <br />
                    </div>


                    <!-- Modal -->
                    <div id="confirmationModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999; justify-content: center; align-items: center;">
                        <div style="background: white; padding: 20px; width: 400px; text-align: center; border-radius: 10px;">
                            <h2>Are you sure?</h2>
                            <p style="font-size: 14px; font-weight: bold; margin-bottom: 10px;">
                                ⚠️ <strong style="color:red">Caution:</strong> Clearing the log will delete all 404 redirects from the database. This action <strong>cannot be undone</strong>.
                            </p>
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                <?php wp_nonce_field('clear_redirects_log', 'clear_redirects_nonce'); ?>
                                <input type="hidden" name="action" value="clear_redirects_log">
                                <button type="submit" class="button button-danger" style="background-color: #dc3545; border-color: #dc3545; color: white;">Yes, Clear the Log</button>
                                <button type="button" id="closeModal" class="button">Cancel</button>
                            </form>
                        </div>
                    </div>

                    <hr />
                    <script>
                        document.getElementById('openModal').addEventListener('click', function() {
                            document.getElementById('confirmationModal').style.display = 'flex';
                        });

                        document.getElementById('closeModal').addEventListener('click', function() {
                            document.getElementById('confirmationModal').style.display = 'none';
                        });
                    </script>
                    <style>
                        .button-danger {
                            margin: 20px 0;
                            padding: 12px 20px;
                            font-size: 16px;
                            font-weight: bold;
                            background-color: #e63946;
                            color: white;
                            border: none;
                            border-radius: 5px;
                            cursor: pointer;
                            box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
                            transition: background-color 0.3s, transform 0.2s;
                        }

                        .button-danger:hover {
                            background-color: #d62839;
                            transform: scale(1.05);
                        }
                    </style>
                <?php
                }


                // ----   End of Tabs ----------------
                ?>
                <br />
                <hr />
                <b style="color:red">Have many broken links?</b> keep track of 404 errors using our powerfull <a target="_blank" href="https://www.wp-buy.com/product/seo-redirection-premium-wordpress-plugin/#404plugin_msg">SEO Redirection Plugin</a> with an advanced <b>404 Manager</b> and set many rules to handle 404 links
                <p>
                    <a href="https://www.wp-buy.com/product/seo-redirection-premium-wordpress-plugin/#404plugin_img" target="_blank"><img src="<?php echo esc_url(plugin_dir_url(__FILE__)); ?>/images/seopro.png" /></a>
                </p>
            </div>
        </div>
    </div>