<?php
/**
 * Plugin Name: ZIP Content Replacer Enhanced
 * Description: Replaces WordPress post content with ZIP file contents using AJAX batching with progress bar, logging, dry run mode, backup/restore functionality, and advanced features.
 * Version: 3.0.0
 * Author: MarineTL
 */

if (!defined('ABSPATH')) exit;

class ZipContentReplacer_Enhanced {
    private $max_file_size = 10485760; // 10MB
    private $allowed_extensions = ['txt', 'md', 'html'];
    private const SETTINGS_TRANSIENT_KEY = 'zip_replacer_settings_';
    private const RATE_LIMIT_TRANSIENT = 'zip_replacer_rate_';
    private const RESUME_TRANSIENT_KEY = 'zip_replacer_resume_';
    private const CHAPTER_CACHE_KEY = 'zip_replacer_chapters_';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_handle_zip_upload_ajax', array($this, 'handle_zip_upload_ajax'));
        add_action('wp_ajax_process_zip_batch', array($this, 'process_zip_batch'));
        add_action('wp_ajax_check_resume_session', array($this, 'check_resume_session'));
        add_action('wp_ajax_resume_processing', array($this, 'resume_processing'));
        add_action('wp_ajax_restore_backup', array($this, 'restore_backup_ajax'));
        add_action('wp_ajax_delete_backup', array($this, 'delete_backup_ajax'));
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_zip-content-replacer') return;

        wp_enqueue_script('zip-replacer-ajax', plugin_dir_url(__FILE__) . 'zip-replacer.js', ['jquery'], '3.0.0', true);
        wp_localize_script('zip-replacer-ajax', 'zipReplacerAjax', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'uploadNonce' => wp_create_nonce('zip_replacer_upload_nonce'),
            'processNonce'=> wp_create_nonce('zip_replacer_process_nonce'),
            'resumeNonce' => wp_create_nonce('zip_replacer_resume_nonce')
        ]);
    }

    public function add_admin_menu() {
        add_management_page('ZIP Content Replacer', 'ZIP Content Replacer', 'manage_options', 'zip-content-replacer', array($this, 'admin_page'));
        add_management_page('Restore Backups', 'Restore Backups', 'manage_options', 'zip-content-restorer', array($this, 'restore_page'));
    }

    public function admin_page() {
        $user_id = get_current_user_id();
        $resume_session = get_transient(self::RESUME_TRANSIENT_KEY . $user_id);
        ?>
        <div class="wrap">
            <h1>ZIP Content Replacer for Fictioneer Chapters</h1>
            <p>This tool updates Fictioneer chapter content with text from files in a ZIP archive. Chapters are matched by their title or slug and must belong to the selected Fictioneer Story.</p>
            
            <?php if ($resume_session): ?>
            <div class="notice notice-info" style="padding: 15px; margin-bottom: 20px;">
                <h3>⚠️ Incomplete Session Detected</h3>
                <p>You have an incomplete processing session. Would you like to resume?</p>
                <button type="button" id="resume-processing-btn" class="button button-primary">Resume Previous Operation</button>
                <button type="button" id="cancel-resume-btn" class="button">Cancel & Start New</button>
            </div>
            <?php endif; ?>
            
            <form id="zip-replacer-form" method="post" enctype="multipart/form-data">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="zip_file">ZIP File</label></th>
                        <td><input type="file" id="zip_file" name="zip_file" accept=".zip" required></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="fictioneer_story_id">Select Fictioneer Story</label></th>
                        <td>
                            <select name="fictioneer_story_id" id="fictioneer_story_id" required>
                                <option value="">-- Select a Story --</option>
                                <?php
                                $stories = get_posts([
                                    'post_type'      => 'fcn_story',
                                    'posts_per_page' => -1,
                                    'orderby'        => 'title',
                                    'order'          => 'ASC',
                                    'post_status'    => 'publish'
                                ]);

                                if (!empty($stories)) {
                                    foreach ($stories as $story) {
                                        echo "<option value='{$story->ID}'>{$story->post_title} (ID: {$story->ID})</option>";
                                    }
                                } else {
                                    echo "<option value='' disabled>No Fictioneer Stories found.</option>";
                                }
                                ?>
                            </select>
                            <p class="description">Only chapters belonging to this story will be considered for updates.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="match_method">Match Method</label></th>
                        <td>
                            <label><input type="radio" name="match_method" value="title" checked> Match by Title</label><br>
                            <label><input type="radio" name="match_method" value="slug"> Match by Slug (filename)</label>
                            <p class="description">Choose how to match files to chapters. Title matching requires exact title match, slug matching uses the post slug.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="batch_size">Processing Options</label></th>
                        <td>
                            <input type="checkbox" id="dry_run" name="dry_run" value="1" checked> 
                            <label for="dry_run">Perform a Dry Run (preview changes without saving).</label>
                            <br><br>
                            <input type="checkbox" id="show_preview" name="show_preview" value="1"> 
                            <label for="show_preview">Show content preview in dry run logs (first 150 characters).</label>
                            <br><br>
                            <input type="checkbox" id="backup_content" name="backup_content" value="1" checked> 
                            <label for="backup_content">Backup original content before updating (allows undo).</label>
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
                <div id="zip-progress-container">
                    <div id="zip-progress-bar" style="width:100%; background:#eee; border:1px solid #ccc; border-radius: 4px; overflow: hidden;">
                        <div id="zip-progress-fill" style="width:0%; height:24px; background:#46b450; transition: width 0.5s ease-in-out;"></div>
                    </div>
                    <p id="zip-progress-text" style="text-align: center; margin-top: 5px; font-weight: bold;">Waiting to start...</p>
                    <p id="zip-eta-text" style="text-align: center; margin-top: 5px; color: #666; font-size: 14px;"></p>
                </div>
                <div style="margin: 10px 0;">
                    <button type="button" id="export-log-btn" class="button" style="display:none;">📥 Download Report</button>
                </div>
                <h3>Log:</h3>
                <div id="zip-process-log" style="height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fafafa; font-family: monospace; font-size: 12px; white-space: pre-wrap;"></div>
            </div>
        </div>
        <?php
    }

    public function restore_page() {
        ?>
        <div class="wrap">
            <h1>Restore Chapter Content Backups</h1>
            <p>This page shows all chapters that have backup content from the ZIP Content Replacer. You can restore individual chapters or delete old backups.</p>
            
            <?php
            $args = array(
                'post_type' => 'fcn_chapter',
                'posts_per_page' => -1,
                'post_status' => array('publish', 'future', 'draft', 'pending'),
                'meta_query' => array(
                    array(
                        'key' => '_zip_replacer_backup',
                        'compare' => 'EXISTS'
                    )
                ),
                'orderby' => 'modified',
                'order' => 'DESC'
            );
            
            $chapters_with_backups = get_posts($args);
            
            if (empty($chapters_with_backups)) {
                echo '<div class="notice notice-info"><p>No backup data found. Process some chapters first using the ZIP Content Replacer.</p></div>';
            } else {
                ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 5%;">ID</th>
                            <th style="width: 25%;">Chapter Title</th>
                            <th style="width: 15%;">Story</th>
                            <th style="width: 20%;">Source File</th>
                            <th style="width: 15%;">Backup Date</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($chapters_with_backups as $chapter): 
                            $backup_time = get_post_meta($chapter->ID, '_zip_replacer_backup_time', true);
                            $backup_filename = get_post_meta($chapter->ID, '_zip_replacer_backup_filename', true);
                            $story_id = get_post_meta($chapter->ID, 'fictioneer_chapter_story', true);
                            $story_title = $story_id ? get_the_title($story_id) : 'N/A';
                            $post_status_labels = array(
                                'publish' => 'Published',
                                'future' => 'Scheduled',
                                'draft' => 'Draft',
                                'pending' => 'Pending'
                            );
                            $status_label = isset($post_status_labels[$chapter->post_status]) ? $post_status_labels[$chapter->post_status] : $chapter->post_status;
                        ?>
                        <tr data-chapter-id="<?php echo esc_attr($chapter->ID); ?>">
                            <td><?php echo esc_html($chapter->ID); ?></td>
                            <td>
                                <strong><?php echo esc_html($chapter->post_title); ?></strong>
                                <div class="row-actions">
                                    <span class="edit"><a href="<?php echo get_edit_post_link($chapter->ID); ?>" target="_blank">Edit Chapter</a></span>
                                </div>
                            </td>
                            <td><?php echo esc_html($story_title); ?></td>
                            <td><?php echo esc_html($backup_filename ? $backup_filename : 'N/A'); ?></td>
                            <td><?php echo esc_html($backup_time ? date('Y-m-d H:i:s', $backup_time) : 'N/A'); ?></td>
                            <td>
                                <span class="status-badge" style="padding: 3px 8px; border-radius: 3px; font-size: 11px; background: <?php 
                                    echo $chapter->post_status === 'publish' ? '#46b450' : ($chapter->post_status === 'future' ? '#f0ad4e' : '#999'); 
                                ?>; color: white;">
                                    <?php echo esc_html($status_label); ?>
                                </span>
                            </td>
                            <td>
                                <button class="button button-primary restore-backup-btn" data-chapter-id="<?php echo esc_attr($chapter->ID); ?>">
                                    🔄 Restore
                                </button>
                                <button class="button button-link-delete delete-backup-btn" data-chapter-id="<?php echo esc_attr($chapter->ID); ?>" style="color: #b32d2e;">
                                    🗑️ Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 20px;">
                    <button id="restore-all-btn" class="button button-secondary">🔄 Restore All Backups</button>
                    <button id="delete-all-btn" class="button button-link-delete" style="color: #b32d2e; margin-left: 10px;">🗑️ Delete All Backups</button>
                </div>
                <?php
            }
            ?>
            
            <div id="restore-result" style="margin-top: 20px;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.restore-backup-btn').on('click', function() {
                const btn = $(this);
                const chapterId = btn.data('chapter-id');
                const row = btn.closest('tr');
                
                if (!confirm('Are you sure you want to restore this chapter to its backup content?')) {
                    return;
                }
                
                btn.prop('disabled', true).text('Restoring...');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'restore_backup',
                        nonce: '<?php echo wp_create_nonce('restore_backup_nonce'); ?>',
                        chapter_id: chapterId
                    },
                    success: function(response) {
                        if (response.success) {
                            row.css('background-color', '#d4edda');
                            alert('✅ ' + response.data.message);
                            location.reload();
                        } else {
                            alert('❌ Error: ' + response.data.message);
                            btn.prop('disabled', false).text('🔄 Restore');
                        }
                    },
                    error: function() {
                        alert('❌ An error occurred during restoration.');
                        btn.prop('disabled', false).text('🔄 Restore');
                    }
                });
            });
            
            $('.delete-backup-btn').on('click', function() {
                const btn = $(this);
                const chapterId = btn.data('chapter-id');
                const row = btn.closest('tr');
                
                if (!confirm('Are you sure you want to delete this backup? This cannot be undone.')) {
                    return;
                }
                
                btn.prop('disabled', true).text('Deleting...');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'delete_backup',
                        nonce: '<?php echo wp_create_nonce('delete_backup_nonce'); ?>',
                        chapter_id: chapterId
                    },
                    success: function(response) {
                        if (response.success) {
                            row.fadeOut(300, function() { $(this).remove(); });
                            alert('✅ ' + response.data.message);
                        } else {
                            alert('❌ Error: ' + response.data.message);
                            btn.prop('disabled', false).text('🗑️ Delete');
                        }
                    },
                    error: function() {
                        alert('❌ An error occurred during deletion.');
                        btn.prop('disabled', false).text('🗑️ Delete');
                    }
                });
            });
            
            $('#restore-all-btn').on('click', function() {
                if (!confirm('Are you sure you want to restore ALL chapters to their backup content? This will process ' + $('.restore-backup-btn').length + ' chapters.')) {
                    return;
                }
                
                const btn = $(this);
                btn.prop('disabled', true).text('Restoring all...');
                
                let completed = 0;
                const total = $('.restore-backup-btn').length;
                
                $('.restore-backup-btn').each(function() {
                    const chapterId = $(this).data('chapter-id');
                    
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'restore_backup',
                            nonce: '<?php echo wp_create_nonce('restore_backup_nonce'); ?>',
                            chapter_id: chapterId
                        },
                        success: function() {
                            completed++;
                            if (completed === total) {
                                alert('✅ All backups restored successfully!');
                                location.reload();
                            }
                        }
                    });
                });
            });
            
            $('#delete-all-btn').on('click', function() {
                if (!confirm('Are you sure you want to DELETE ALL backups? This cannot be undone and will remove backup data for ' + $('.delete-backup-btn').length + ' chapters.')) {
                    return;
                }
                
                const btn = $(this);
                btn.prop('disabled', true).text('Deleting all...');
                
                let completed = 0;
                const total = $('.delete-backup-btn').length;
                
                $('.delete-backup-btn').each(function() {
                    const chapterId = $(this).data('chapter-id');
                    
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'delete_backup',
                            nonce: '<?php echo wp_create_nonce('delete_backup_nonce'); ?>',
                            chapter_id: chapterId
                        },
                        success: function() {
                            completed++;
                            if (completed === total) {
                                alert('✅ All backups deleted successfully!');
                                location.reload();
                            }
                        }
                    });
                });
            });
        });
        </script>
        
        <style>
        .wp-list-table th, .wp-list-table td {
            vertical-align: middle;
        }
        .status-badge {
            display: inline-block;
            text-transform: uppercase;
            font-weight: 600;
        }
        </style>
        <?php
    }

    public function check_resume_session() {
        check_ajax_referer('zip_replacer_resume_nonce', 'nonce');
        $user_id = get_current_user_id();
        $resume_data = get_transient(self::RESUME_TRANSIENT_KEY . $user_id);
        
        if ($resume_data) {
            wp_send_json_success(['has_session' => true, 'data' => $resume_data]);
        } else {
            wp_send_json_success(['has_session' => false]);
        }
    }

    public function resume_processing() {
        check_ajax_referer('zip_replacer_resume_nonce', 'nonce');
        $user_id = get_current_user_id();
        $resume_data = get_transient(self::RESUME_TRANSIENT_KEY . $user_id);
        
        if (!$resume_data) {
            wp_send_json_error(['message' => 'No resume session found.']);
        }
        
        set_transient(self::SETTINGS_TRANSIENT_KEY . $user_id, $resume_data['settings'], 3600);
        update_option(self::SETTINGS_TRANSIENT_KEY . $user_id . '_backup', $resume_data['settings'], false);
        
        wp_send_json_success([
            'message' => 'Session restored. Resuming processing...',
            'offset' => $resume_data['offset'],
            'total' => $resume_data['total']
        ]);
    }

    private function check_rate_limit($user_id) {
        $key = self::RATE_LIMIT_TRANSIENT . $user_id;
        $count = get_transient($key);
        
        if ($count && $count > 20) {
            wp_send_json_error(['message' => 'Too many requests. Please wait 1 minute before trying again.']);
        }
        
        set_transient($key, ($count ? $count + 1 : 1), 60);
    }

    public function handle_zip_upload_ajax() {
        check_ajax_referer('zip_replacer_upload_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissions error.']);
        }

        $user_id = get_current_user_id();
        $this->check_rate_limit($user_id);

        if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'File upload error. Please try again. Code: ' . ($_FILES['zip_file']['error'] ?? 'unknown')]);
        }
        
        if (!isset($_POST['fictioneer_story_id']) || empty($_POST['fictioneer_story_id'])) {
            wp_send_json_error(['message' => 'Please select a Fictioneer Story.']);
        }

        $fictioneer_story_id = intval($_POST['fictioneer_story_id']);
        if ($fictioneer_story_id <= 0 || get_post_type($fictioneer_story_id) !== 'fcn_story') {
            wp_send_json_error(['message' => 'Invalid Fictioneer Story selected.']);
        }

        $file = $_FILES['zip_file'];
        
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if ($mime !== 'application/zip' && $mime !== 'application/x-zip-compressed') {
                wp_send_json_error(['message' => 'Invalid file type. Only ZIP files are allowed.']);
            }
        }

        if ($file['size'] > $this->max_file_size) {
            wp_send_json_error(['message' => 'Error: File is larger than the allowed limit of 10MB.']);
        }
        
        $upload_dir = wp_upload_dir();
        $zip_filename = wp_unique_filename($upload_dir['path'], 'zip_content_temp_' . time() . '.zip');
        $zip_path = $upload_dir['path'] . '/' . $zip_filename;
        
        if (!move_uploaded_file($file['tmp_name'], $zip_path)) {
            $last_error = error_get_last();
            $error_message = 'Error: Could not move uploaded file.';
            if (isset($last_error['message'])) {
                $error_message .= ' Details: ' . $last_error['message'];
            }
            error_log("ZIP Replacer: Upload failed - {$error_message}");
            wp_send_json_error(['message' => $error_message]);
        }

        $match_method = isset($_POST['match_method']) ? sanitize_text_field($_POST['match_method']) : 'title';
        
        $settings = [
            'zip_path'            => $zip_path,
            'post_type'           => 'fcn_chapter',
            'fictioneer_story_id' => $fictioneer_story_id,
            'batch_size'          => intval($_POST['batch_size']),
            'is_dry_run'          => isset($_POST['dry_run']),
            'show_preview'        => isset($_POST['show_preview']),
            'backup_content'      => isset($_POST['backup_content']),
            'match_method'        => $match_method
        ];

        $transient_key = self::SETTINGS_TRANSIENT_KEY . $user_id;
        
        set_transient($transient_key, $settings, 3600);
        update_option($transient_key . '_backup', $settings, false);
        
        delete_transient(self::RESUME_TRANSIENT_KEY . $user_id);

        wp_send_json_success([
            'message' => 'File uploaded successfully. Starting process...',
            'transient_key' => $transient_key
        ]);
    }

    public function process_zip_batch() {
        check_ajax_referer('zip_replacer_process_nonce', 'nonce');

        $user_id = get_current_user_id();
        $this->check_rate_limit($user_id);

        $transient_key = self::SETTINGS_TRANSIENT_KEY . $user_id;
        $settings = get_transient($transient_key);
        
        if (false === $settings) {
            $settings = get_option($transient_key . '_backup', false);
            
            if (false === $settings) {
                error_log("ZIP Replacer: Both transient and backup option failed for user {$user_id}");
                wp_send_json_error(['message' => 'Session expired. Please restart the upload process.']);
            }
            
            set_transient($transient_key, $settings, 3600);
        }

        $zip_path = $settings['zip_path'] ?? null;
        $post_type = $settings['post_type'] ?? 'fcn_chapter';
        $fictioneer_story_id = $settings['fictioneer_story_id'] ?? null;
        $batch_size = $settings['batch_size'] ?? 10;
        $is_dry_run = $settings['is_dry_run'] ?? false;
        $show_preview = $settings['show_preview'] ?? false;
        $backup_content = $settings['backup_content'] ?? false;
        $match_method = $settings['match_method'] ?? 'title';
        
        if (!$zip_path || !file_exists($zip_path) || !$fictioneer_story_id) {
            error_log("ZIP Replacer: Session data missing or file not found. Path: {$zip_path}");
            wp_send_json_error(['message' => 'Session expired or file not found. Please start over.']);
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $logs = [];

        $zip = new ZipArchive;
        if ($zip->open($zip_path) !== TRUE) {
            error_log("ZIP Replacer: Cannot open ZIP archive at {$zip_path}");
            wp_send_json_error(['message' => 'Error: Cannot open the ZIP archive.']);
        }

        $total_files = $zip->numFiles;
        $end = min($offset + $batch_size, $total_files);

        $chapter_map = $this->get_story_chapters_map($fictioneer_story_id, $match_method);

        for ($i = $offset; $i < $end; $i++) {
            $filename = $zip->getNameIndex($i);

            if (substr($filename, -1) === '/' || !$this->is_supported_file($filename)) {
                $logs[] = "<span style='color: #999;'>⏭️ INFO: Skipping directory or unsupported file: {$filename}</span>";
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $logs[] = "<span style='color: orange;'>⚠️ WARN: Could not read content from file: {$filename}</span>";
                continue;
            }
            
            if (!mb_check_encoding($content, 'UTF-8')) {
                $detected_encoding = mb_detect_encoding($content, 'UTF-8, ISO-8859-1, Windows-1252, GB2312, GBK', true);
                if ($detected_encoding && $detected_encoding !== 'UTF-8') {
                    $content = mb_convert_encoding($content, 'UTF-8', $detected_encoding);
                    $logs[] = "<span style='color: #666;'>🔄 INFO: Converted encoding of '{$filename}' from {$detected_encoding} to UTF-8.</span>";
                } else {
                    $content = mb_convert_encoding($content, 'UTF-8');
                    $logs[] = "<span style='color: #666;'>🔄 INFO: Attempted encoding conversion for '{$filename}'.</span>";
                }
            }

            $filename_base = pathinfo($filename, PATHINFO_FILENAME);
            
            $post = null;
            if ($match_method === 'slug') {
                $slug = sanitize_title($filename_base);
                $post = $this->find_chapter_by_slug($slug, $fictioneer_story_id, $post_type);
            } else {
                if (isset($chapter_map[$filename_base])) {
                    $post = get_post($chapter_map[$filename_base]);
                }
            }

            if ($post) {
                $duplicate_check = $this->check_for_duplicates($filename_base, $fictioneer_story_id, $post_type, $match_method);
                if ($duplicate_check > 1) {
                    $logs[] = "<span style='color: orange;'>⚠️ WARNING: Multiple chapters ({$duplicate_check}) found for '{$filename_base}'. Using first match (ID: {$post->ID}).</span>";
                }

                if (!$is_dry_run) {
                    if ($backup_content) {
                        update_post_meta($post->ID, '_zip_replacer_backup', $post->post_content);
                        update_post_meta($post->ID, '_zip_replacer_backup_time', time());
                        update_post_meta($post->ID, '_zip_replacer_backup_filename', $filename);
                    }

                    $updated = wp_update_post([
                        'ID' => $post->ID,
                        'post_content' => wp_kses_post(wpautop($content))
                    ]);

                    if (is_wp_error($updated)) {
                        $logs[] = "<span style='color: red;'>❌ ERROR: Failed to update chapter '{$filename_base}' (ID: {$post->ID}). WP_Error: {$updated->get_error_message()}.</span>";
                        error_log("ZIP Replacer: Update failed for post {$post->ID} - " . $updated->get_error_message());
                    } elseif ($updated === 0) {
                        $logs[] = "<span style='color: #666;'>ℹ️ INFO: Chapter '{$filename_base}' (ID: {$post->ID}) found but no content changes detected.</span>";
                    } else {
                        $logs[] = "<span style='color: green;'>✅ SUCCESS: Updated chapter '{$filename_base}' (ID: {$post->ID}) for story ID {$fictioneer_story_id}.</span>";
                    }
                } else {
                    $preview_text = '';
                    if ($show_preview) {
                        $preview = mb_substr(strip_tags($content), 0, 150);
                        $preview_text = " | Preview: " . esc_html($preview) . "...";
                    }
                    $logs[] = "<span style='color: blue;'>🔍 [DRY RUN] Would update chapter '{$filename_base}' (ID: {$post->ID}) for story ID {$fictioneer_story_id}.{$preview_text}</span>";
                }
            } else {
                $logs[] = "<span style='color: #999;'>⏭️ INFO: Skipped - No chapter found with " . ($match_method === 'slug' ? 'slug' : 'title') . " '{$filename_base}' belonging to story ID {$fictioneer_story_id}.</span>";
            }
        }

        $zip->close();
        $next_offset = $end;
        $remaining = max(0, $total_files - $next_offset);

        if ($remaining === 0) {
            if (file_exists($zip_path)) unlink($zip_path);
            delete_transient($transient_key);
            delete_option($transient_key . '_backup');
            delete_transient(self::RESUME_TRANSIENT_KEY . $user_id);
            delete_transient(self::CHAPTER_CACHE_KEY . $fictioneer_story_id . '_' . $match_method);
            $logs[] = "<span style='color: #046; font-weight: bold;'>✨ -----> All files processed. Cleanup complete. <-----</span>";
        } else {
            set_transient(self::RESUME_TRANSIENT_KEY . $user_id, [
                'offset' => $next_offset,
                'total' => $total_files,
                'settings' => $settings
            ], 3600);
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

    private function get_story_chapters_map($story_id, $match_method) {
        $cache_key = self::CHAPTER_CACHE_KEY . $story_id . '_' . $match_method;
        $cached = get_transient($cache_key);
        
        if (false !== $cached) {
            return $cached;
        }

        $args = [
            'post_type' => 'fcn_chapter',
            'posts_per_page' => -1,
            'post_status' => ['publish', 'future', 'draft', 'pending'],
            'meta_query' => [
                [
                    'key' => 'fictioneer_chapter_story',
                    'value' => $story_id,
                    'compare' => '=',
                    'type' => 'NUMERIC'
                ]
            ],
            'fields' => 'ids'
        ];

        $chapter_ids = get_posts($args);
        $map = [];

        foreach ($chapter_ids as $chapter_id) {
            if ($match_method === 'slug') {
                $post = get_post($chapter_id);
                $map[$post->post_name] = $chapter_id;
            } else {
                $title = get_the_title($chapter_id);
                $map[$title] = $chapter_id;
            }
        }

        set_transient($cache_key, $map, 300);
        return $map;
    }

    private function find_chapter_by_slug($slug, $story_id, $post_type) {
        $args = [
            'post_type' => $post_type,
            'name' => $slug,
            'posts_per_page' => 1,
            'post_status' => ['publish', 'future', 'draft', 'pending'],
            'meta_query' => [
                [
                    'key' => 'fictioneer_chapter_story',
                    'value' => $story_id,
                    'compare' => '=',
                    'type' => 'NUMERIC'
                ]
            ]
        ];

        $chapters = get_posts($args);
        return !empty($chapters) ? $chapters[0] : null;
    }

    private function check_for_duplicates($identifier, $story_id, $post_type, $match_method) {
        if ($match_method === 'slug') {
            $args = [
                'post_type' => $post_type,
                'name' => sanitize_title($identifier),
                'posts_per_page' => -1,
                'post_status' => ['publish', 'future', 'draft', 'pending'],
                'meta_query' => [
                    [
                        'key' => 'fictioneer_chapter_story',
                        'value' => $story_id,
                        'compare' => '=',
                        'type' => 'NUMERIC'
                    ]
                ],
                'fields' => 'ids'
            ];
        } else {
            $args = [
                'post_type' => $post_type,
                'title' => $identifier,
                'posts_per_page' => -1,
                'post_status' => ['publish', 'future', 'draft', 'pending'],
                'meta_query' => [
                    [
                        'key' => 'fictioneer_chapter_story',
                        'value' => $story_id,
                        'compare' => '=',
                        'type' => 'NUMERIC'
                    ]
                ],
                'fields' => 'ids'
            ];
        }

        $chapters = get_posts($args);
        return count($chapters);
    }

    private function is_supported_file($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $this->allowed_extensions);
    }

    public function restore_backup_ajax() {
        check_ajax_referer('restore_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        
        $chapter_id = isset($_POST['chapter_id']) ? intval($_POST['chapter_id']) : 0;
        
        if ($chapter_id <= 0) {
            wp_send_json_error(['message' => 'Invalid chapter ID.']);
        }
        
        $backup_content = get_post_meta($chapter_id, '_zip_replacer_backup', true);
        
        if (empty($backup_content)) {
            wp_send_json_error(['message' => 'No backup found for this chapter.']);
        }
        
        $updated = wp_update_post([
            'ID' => $chapter_id,
            'post_content' => $backup_content
        ]);
        
        if (is_wp_error($updated)) {
            wp_send_json_error(['message' => 'Failed to restore content: ' . $updated->get_error_message()]);
        }
        
        $chapter_title = get_the_title($chapter_id);
        wp_send_json_success(['message' => "Chapter '{$chapter_title}' (ID: {$chapter_id}) has been restored to backup content."]);
    }

    public function delete_backup_ajax() {
        check_ajax_referer('delete_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        
        $chapter_id = isset($_POST['chapter_id']) ? intval($_POST['chapter_id']) : 0;
        
        if ($chapter_id <= 0) {
            wp_send_json_error(['message' => 'Invalid chapter ID.']);
        }
        
        delete_post_meta($chapter_id, '_zip_replacer_backup');
        delete_post_meta($chapter_id, '_zip_replacer_backup_time');
        delete_post_meta($chapter_id, '_zip_replacer_backup_filename');
        
        $chapter_title = get_the_title($chapter_id);
        wp_send_json_success(['message' => "Backup deleted for chapter '{$chapter_title}' (ID: {$chapter_id})."]);
    }
}

new ZipContentReplacer_Enhanced();
