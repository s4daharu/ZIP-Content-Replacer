<?php
/**
 * Plugin Name: ZIP Content Replacer
 * Description: Replaces WordPress post content with ZIP file contents using AJAX batching with progress bar, logging, and dry run mode.
 * Version: 2.2
 * Author: MarineTL
 */

if (!defined('ABSPATH')) exit;

class ZipContentReplacer_Enhanced {
    private $max_file_size = 10485760; // 10MB
    private $allowed_extensions = ['txt', 'md', 'html'];

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_handle_zip_upload_ajax', array($this, 'handle_zip_upload_ajax'));
        add_action('wp_ajax_process_zip_batch', array($this, 'process_zip_batch'));
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_zip-content-replacer') return;

        wp_enqueue_script('zip-replacer-ajax', plugin_dir_url(__FILE__) . 'zip-replacer.js', ['jquery'], '2.2', true);
        wp_localize_script('zip-replacer-ajax', 'zipReplacerAjax', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'uploadNonce' => wp_create_nonce('zip_replacer_upload_nonce'),
            'processNonce'=> wp_create_nonce('zip_replacer_process_nonce')
        ]);
    }

    public function add_admin_menu() {
        add_management_page('ZIP Content Replacer', 'ZIP Content Replacer', 'manage_options', 'zip-content-replacer', array($this, 'admin_page'));
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>ZIP Content Replacer</h1>
            <p>This tool updates existing posts with content from files in a ZIP archive. The post is matched by its title, which must be identical to the filename (without extension).</p>
            
            <form id="zip-replacer-form" method="post" enctype="multipart/form-data">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="zip_file">ZIP File</label></th>
                        <td><input type="file" id="zip_file" name="zip_file" accept=".zip" required></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="post_type">Post Type</label></th>
                        <td>
                            <select name="post_type" id="post_type">
                                <?php
                                $post_types = get_post_types(['public' => true], 'objects');
                                foreach ($post_types as $type) {
                                    echo "<option value='{$type->name}'>{$type->labels->name}</option>";
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="batch_size">Processing Options</label></th>
                        <td>
                            <input type="checkbox" id="dry_run" name="dry_run" value="1" checked> 
                            <label for="dry_run">Perform a Dry Run (preview changes without saving).</label>
                            <br><br>
                            <label for="batch_size">Items per batch:</label>
                            <input type="number" id="batch_size" name="batch_size" value="10" min="1" max="100" style="width: 70px;">
                            <p class="description">A smaller number is less likely to cause server timeouts.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Upload and Process', 'primary', 'submit-zip-form'); ?>
            </form>

            <div id="zip-processing-area" style="display:none; margin-top:20px;">
                 <h2 id="processing-title">Processing...</h2>
                <div id="zip-progress-container" >
                    <div id="zip-progress-bar" style="width:100%; background:#eee; border:1px solid #ccc; border-radius: 4px; overflow: hidden;">
                        <div id="zip-progress-fill" style="width:0%; height:24px; background:#46b450; transition: width 0.5s ease-in-out;"></div>
                    </div>
                    <p id="zip-progress-text" style="text-align: center; margin-top: 5px; font-weight: bold;">Waiting to start...</p>
                </div>
                <h3>Log:</h3>
                <div id="zip-process-log" style="height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fafafa; font-family: monospace; font-size: 12px; white-space: pre-wrap;"></div>
            </div>
        </div>
        <?php
    }

    public function handle_zip_upload_ajax() {
        check_ajax_referer('zip_replacer_upload_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissions error.']);
        }

        if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
             wp_send_json_error(['message' => 'File upload error. Please try again.']);
        }
        
        $file = $_FILES['zip_file'];
        if ($file['size'] > $this->max_file_size) {
            wp_send_json_error(['message' => 'Error: File is larger than the allowed limit of 10MB.']);
        }
        
        $upload_dir = wp_upload_dir();
        $zip_filename = wp_unique_filename($upload_dir['path'], 'zip_content_temp.zip');
        $zip_path = $upload_dir['path'] . '/' . $zip_filename;
        
        if (!move_uploaded_file($file['tmp_name'], $zip_path)) {
            wp_send_json_error(['message' => 'Error: Could not move uploaded file.']);
        }

        $user_id = get_current_user_id();
        $settings = [
            'zip_path'    => $zip_path,
            'post_type'   => sanitize_text_field($_POST['post_type']),
            'batch_size'  => intval($_POST['batch_size']),
            'is_dry_run'  => isset($_POST['dry_run']),
        ];
        update_option("zip_replacer_settings_{$user_id}", $settings);

        wp_send_json_success(['message' => 'File uploaded successfully. Starting process...']);
    }

    public function process_zip_batch() {
        check_ajax_referer('zip_replacer_process_nonce', 'nonce');

        $user_id = get_current_user_id();
        $settings = get_option("zip_replacer_settings_{$user_id}");
        
        $zip_path = $settings['zip_path'] ?? null;
        $post_type = $settings['post_type'] ?? null;
        $batch_size = $settings['batch_size'] ?? 10;
        $is_dry_run = $settings['is_dry_run'] ?? false;
        
        if (!$zip_path || !file_exists($zip_path) || !$post_type) {
            wp_send_json_error(['message' => 'Session expired or file not found. Please start over.']);
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $logs = [];

        $zip = new ZipArchive;
        if ($zip->open($zip_path) !== TRUE) {
            wp_send_json_error(['message' => 'Error: Cannot open the ZIP archive.']);
        }

        $total_files = $zip->numFiles;
        $end = min($offset + $batch_size, $total_files);

        for ($i = $offset; $i < $end; $i++) {
            $filename = $zip->getNameIndex($i);

            if (substr($filename, -1) === '/' || !$this->is_supported_file($filename)) {
                $logs[] = "INFO: Skipping directory or unsupported file: {$filename}";
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $logs[] = "WARN: Could not read content from file: {$filename}";
                continue;
            }
            
            if (!mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8');
            }

            $post_title = pathinfo($filename, PATHINFO_FILENAME);
            $post = get_page_by_title($post_title, OBJECT, $post_type);

            if ($post) {
                if (!$is_dry_run) {
                    wp_update_post(['ID' => $post->ID, 'post_content' => wpautop($content)]);
                    $logs[] = "SUCCESS: Updated post '{$post_title}' (ID: {$post->ID}).";
                } else {
                    $logs[] = "[DRY RUN] SUCCESS: Would update post '{$post_title}' (ID: {$post->ID}).";
                }
            } else {
                 $logs[] = "INFO: Skipped - No post found with title '{$post_title}' in post type '{$post_type}'.";
            }
        }

        $zip->close();
        $next_offset = $end;
        $remaining = max(0, $total_files - $next_offset);

        if ($remaining === 0) {
            if (file_exists($zip_path)) unlink($zip_path);
            delete_option("zip_replacer_settings_{$user_id}");
            $logs[] = "-----> All files processed. Cleanup complete. <-----";
        }

        wp_send_json_success([
            'processed'   => $next_offset,
            'remaining'   => $remaining,
            'total'       => $total_files,
            'next_offset' => $next_offset,
            'logs'        => $logs,
            'is_dry_run'  => $is_dry_run
        ]);
    }

    private function is_supported_file($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $this->allowed_extensions);
    }
}

new ZipContentReplacer_Enhanced();