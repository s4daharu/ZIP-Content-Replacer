<?php
/**
 * Plugin Name: ZIP Content Replacer Enhanced
 * Plugin URI: https://github.com/MarineTL/zip-content-replacer
 * Description: Replaces WordPress post content with ZIP file contents using AJAX batching with progress bar, logging, dry run mode, backup/restore functionality, and advanced features. Designed for Fictioneer theme.
 * Version: 3.2.4
 * Author: MarineTL
 * Author URI: https://github.com/MarineTL
 * Text Domain: zip-content-replacer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH'))
    exit;

// Define plugin constants
define('ZIP_CONTENT_REPLACER_VERSION', '3.2.4');
define('ZIP_CONTENT_REPLACER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZIP_CONTENT_REPLACER_PLUGIN_URL', plugin_dir_url(__FILE__));

class ZipContentReplacer_Enhanced
{
    private $max_file_size = 10485760; // 10MB
    private $allowed_extensions = ['txt', 'md', 'html'];
    private const SETTINGS_TRANSIENT_KEY = 'zip_replacer_settings_';
    private const RATE_LIMIT_TRANSIENT = 'zip_replacer_rate_';
    private const RESUME_TRANSIENT_KEY = 'zip_replacer_resume_';
    private const CHAPTER_CACHE_KEY = 'zip_replacer_chapters_';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_handle_zip_upload_ajax', array($this, 'handle_zip_upload_ajax'));
        add_action('wp_ajax_process_zip_batch', array($this, 'process_zip_batch'));
        add_action('wp_ajax_check_resume_session', array($this, 'check_resume_session'));
        add_action('wp_ajax_resume_processing', array($this, 'resume_processing'));
        add_action('wp_ajax_restore_backup', array($this, 'restore_backup_ajax'));
        add_action('wp_ajax_delete_backup', array($this, 'delete_backup_ajax'));
    }

    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== 'tools_page_zip-content-replacer' && $hook !== 'tools_page_zip-content-restorer')
            return;

        // Enqueue modern admin styles
        wp_enqueue_style(
            'zip-replacer-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/zip-replacer-admin.css',
            [],
            ZIP_CONTENT_REPLACER_VERSION
        );

        wp_enqueue_script('zip-replacer-ajax', plugin_dir_url(__FILE__) . 'zip-replacer.js', ['jquery'], ZIP_CONTENT_REPLACER_VERSION, true);
        wp_localize_script('zip-replacer-ajax', 'zipReplacerAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'uploadNonce' => wp_create_nonce('zip_replacer_upload_nonce'),
            'processNonce' => wp_create_nonce('zip_replacer_process_nonce'),
            'resumeNonce' => wp_create_nonce('zip_replacer_resume_nonce')
        ]);
    }

    public function add_admin_menu()
    {
        add_management_page(
            __('ZIP Content Replacer', 'zip-content-replacer'),
            __('ZIP Content Replacer', 'zip-content-replacer'),
            'manage_options',
            'zip-content-replacer',
            array($this, 'admin_page')
        );
        add_management_page(
            __('Restore Backups', 'zip-content-replacer'),
            __('Restore Backups', 'zip-content-replacer'),
            'manage_options',
            'zip-content-restorer',
            array($this, 'restore_page')
        );
    }

    public function admin_page()
    {
        $user_id = get_current_user_id();
        $resume_session = get_transient(self::RESUME_TRANSIENT_KEY . $user_id);
        ?>
        <div class="zcr-wrap zcr-has-sticky-actions">
            <h1>ZIP Content Replacer</h1>
            <p class="zcr-description">Update Fictioneer chapter content with text from files in a ZIP archive. Chapters are
                matched by their title or slug and must belong to the selected Fictioneer Story.</p>

            <?php if ($resume_session): ?>
                <div class="zcr-notice zcr-notice-warning">
                    <span class="zcr-notice-icon">⚠️</span>
                    <div class="zcr-notice-content">
                        <h3>Incomplete Session Detected</h3>
                        <p>You have an incomplete processing session from a previous upload. Would you like to resume where you left
                            off?</p>
                        <div class="zcr-notice-actions">
                            <button type="button" id="resume-processing-btn" class="zcr-btn zcr-btn-primary">
                                <span>🔄</span> Resume Previous
                            </button>
                            <button type="button" id="cancel-resume-btn" class="zcr-btn zcr-btn-secondary">
                                Start Fresh
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form id="zip-replacer-form" method="post" enctype="multipart/form-data">
                <!-- Upload Card -->
                <div class="zcr-card">
                    <div class="zcr-card-header">
                        <h2><span class="zcr-icon">📁</span> Upload ZIP File</h2>
                    </div>

                    <div class="zcr-upload-zone" id="upload-zone">
                        <div class="zcr-upload-default">
                            <span class="zcr-upload-icon">📦</span>
                            <h3>Drop your ZIP file here</h3>
                            <p>or click to browse</p>
                            <span class="zcr-upload-hint">Supports .txt, .md, .html files • Max 10MB</span>
                        </div>
                        <div class="zcr-file-info">
                            <span>✅</span>
                            <span id="selected-file-name">No file selected</span>
                        </div>
                        <input type="file" id="zip_file" name="zip_file" accept=".zip" required>
                    </div>
                </div>

                <!-- Story Selection Card -->
                <div class="zcr-card">
                    <div class="zcr-card-header">
                        <h2><span class="zcr-icon">📚</span> Select Story</h2>
                    </div>

                    <div class="zcr-form-group">
                        <label class="zcr-label" for="fictioneer_story_id">
                            Target Story <span class="zcr-label-hint">— Only chapters from this story will be updated</span>
                        </label>
                        <select name="fictioneer_story_id" id="fictioneer_story_id" class="zcr-select" required>
                            <option value="">Choose a story...</option>
                            <?php
                            $stories = get_posts([
                                'post_type' => 'fcn_story',
                                'posts_per_page' => -1,
                                'orderby' => 'title',
                                'order' => 'ASC',
                                'post_status' => 'publish'
                            ]);

                            if (!empty($stories)) {
                                foreach ($stories as $story) {
                                    $chapter_count = count(get_post_meta($story->ID, 'fictioneer_story_chapters', true) ?: []);
                                    printf(
                                        '<option value="%d">%s (%d chapters)</option>',
                                        esc_attr($story->ID),
                                        esc_html($story->post_title),
                                        $chapter_count
                                    );
                                }
                            } else {
                                echo '<option value="" disabled>' . esc_html__('No Fictioneer Stories found.', 'zip-content-replacer') . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="zcr-form-group">
                        <label class="zcr-label">Match Method</label>
                        <div class="zcr-radio-group">
                            <div class="zcr-radio-pill">
                                <input type="radio" name="match_method" id="match_title" value="title" checked>
                                <label for="match_title">📝 Match by Title</label>
                            </div>
                            <div class="zcr-radio-pill">
                                <input type="radio" name="match_method" id="match_slug" value="slug">
                                <label for="match_slug">🔗 Match by Slug</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Options Card -->
                <div class="zcr-card">
                    <div class="zcr-card-header">
                        <h2><span class="zcr-icon">⚙️</span> Processing Options</h2>
                    </div>

                    <div class="zcr-option-card">
                        <div class="zcr-option-content">
                            <div class="zcr-option-title">
                                <span>🔍</span> Dry Run Mode
                            </div>
                            <div class="zcr-option-description">
                                Preview all changes without actually saving them to the database. Perfect for testing.
                            </div>
                        </div>
                        <label class="zcr-toggle">
                            <input type="checkbox" id="dry_run" name="dry_run" value="1" checked>
                            <span class="zcr-toggle-slider"></span>
                        </label>
                    </div>

                    <div class="zcr-option-card">
                        <div class="zcr-option-content">
                            <div class="zcr-option-title">
                                <span>👁️</span> Show Content Preview
                            </div>
                            <div class="zcr-option-description">
                                Display the first 150 characters of content in dry run logs.
                            </div>
                        </div>
                        <label class="zcr-toggle">
                            <input type="checkbox" id="show_preview" name="show_preview" value="1">
                            <span class="zcr-toggle-slider"></span>
                        </label>
                    </div>

                    <div class="zcr-option-card">
                        <div class="zcr-option-content">
                            <div class="zcr-option-title">
                                <span>💾</span> Backup Original Content
                            </div>
                            <div class="zcr-option-description">
                                Save original chapter content before updating. Allows easy restoration later.
                            </div>
                        </div>
                        <label class="zcr-toggle">
                            <input type="checkbox" id="backup_content" name="backup_content" value="1" checked>
                            <span class="zcr-toggle-slider"></span>
                        </label>
                    </div>

                    <div class="zcr-option-card">
                        <div class="zcr-option-content">
                            <div class="zcr-option-title">
                                <span>📊</span> Batch Size
                            </div>
                            <div class="zcr-option-description">
                                Files processed per request. Lower values prevent server timeouts.
                            </div>
                        </div>
                        <div class="zcr-number-input">
                            <input type="number" id="batch_size" name="batch_size" value="10" min="1" max="100">
                        </div>
                    </div>
                </div>

                <!-- Desktop Submit Button -->
                <div class="zcr-desktop-actions">
                    <button type="submit" id="submit-zip-form" class="zcr-btn zcr-btn-primary">
                        <span>🚀</span> Upload and Process
                    </button>
                </div>
            </form>

            <!-- Mobile Sticky Actions -->
            <div class="zcr-sticky-actions">
                <button type="button" id="submit-zip-form-mobile" class="zcr-btn zcr-btn-primary">
                    <span>🚀</span> Upload and Process
                </button>
            </div>

            <!-- Processing Area -->
            <div id="zip-processing-area" class="zcr-progress-area">
                <div class="zcr-card">
                    <div class="zcr-progress-header">
                        <div class="zcr-progress-title" id="processing-title">
                            <span class="zcr-spinner"></span>
                            Processing...
                        </div>
                        <span class="zcr-progress-badge zcr-dry-run" id="dry-run-badge" style="display: none;">DRY RUN</span>
                    </div>

                    <div class="zcr-progress-bar-container">
                        <div class="zcr-progress-bar-fill" id="zip-progress-fill"></div>
                    </div>

                    <div class="zcr-progress-stats">
                        <span class="zcr-progress-text" id="zip-progress-text">Waiting to start...</span>
                        <span class="zcr-progress-eta" id="zip-eta-text"></span>
                    </div>
                </div>

                <!-- Console -->
                <div class="zcr-console">
                    <div class="zcr-console-header">
                        <span class="zcr-console-title">
                            <span>📋</span> Processing Log
                        </span>
                        <div class="zcr-console-filters">
                            <button type="button" class="zcr-console-filter zcr-active" data-filter="all">All</button>
                            <button type="button" class="zcr-console-filter" data-filter="success">✓ Success</button>
                            <button type="button" class="zcr-console-filter" data-filter="error">✗ Errors</button>
                            <button type="button" class="zcr-console-filter" data-filter="warning">⚠ Warnings</button>
                            <button type="button" class="zcr-console-filter" data-filter="info">ℹ Info</button>
                        </div>
                    </div>
                    <div class="zcr-console-body" id="zip-process-log"></div>
                </div>

                <div class="zcr-desktop-actions" style="margin-top: 16px;">
                    <button type="button" id="export-log-btn" class="zcr-btn zcr-btn-secondary" style="display: none;">
                        <span>📥</span> Download Report
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    public function restore_page()
    {
        ?>
        <div class="zcr-wrap">
            <h1>Restore Backups</h1>
            <p class="zcr-description">Manage backup content created by the ZIP Content Replacer. You can restore individual
                chapters or delete old backups.</p>

            <?php
            $args = array(
                'post_type' => 'fcn_chapter',
                'posts_per_page' => -1,
                'post_status' => array('publish', 'future', 'draft', 'pending', 'private'),
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
                ?>
                <div class="zcr-card">
                    <div class="zcr-empty-state">
                        <span class="zcr-icon">📦</span>
                        <h3>No Backups Found</h3>
                        <p>Process some chapters using the ZIP Content Replacer to create backups.</p>
                    </div>
                </div>
                <?php
            } else {
                $backup_count = count($chapters_with_backups);
                ?>

                <!-- Batch Actions Toolbar -->
                <div class="zcr-batch-toolbar">
                    <div class="zcr-batch-info">
                        <strong><?php echo $backup_count; ?></strong> backup<?php echo $backup_count !== 1 ? 's' : ''; ?> available
                    </div>
                    <div class="zcr-batch-actions">
                        <button id="restore-all-btn" class="zcr-btn zcr-btn-secondary">
                            <span>🔄</span> Restore All
                        </button>
                        <button id="delete-all-btn" class="zcr-btn zcr-btn-danger">
                            <span>🗑️</span> Delete All
                        </button>
                    </div>
                </div>

                <!-- Backup Cards Grid -->
                <div class="zcr-backup-grid">
                    <?php foreach ($chapters_with_backups as $chapter):
                        $backup_time = get_post_meta($chapter->ID, '_zip_replacer_backup_time', true);
                        $backup_filename = get_post_meta($chapter->ID, '_zip_replacer_backup_filename', true);
                        $story_id = get_post_meta($chapter->ID, 'fictioneer_chapter_story', true);
                        $story_title = $story_id ? get_the_title($story_id) : 'N/A';

                        $post_status_labels = array(
                            'publish' => __('Published', 'zip-content-replacer'),
                            'future' => __('Scheduled', 'zip-content-replacer'),
                            'draft' => __('Draft', 'zip-content-replacer'),
                            'pending' => __('Pending', 'zip-content-replacer'),
                            'private' => __('Private', 'zip-content-replacer')
                        );
                        $status_label = isset($post_status_labels[$chapter->post_status]) ? $post_status_labels[$chapter->post_status] : $chapter->post_status;

                        $status_class = 'zcr-draft';
                        if ($chapter->post_status === 'publish') {
                            $status_class = 'zcr-published';
                        } elseif ($chapter->post_status === 'future') {
                            $status_class = 'zcr-scheduled';
                        }
                        ?>
                        <div class="zcr-backup-card" data-chapter-id="<?php echo esc_attr($chapter->ID); ?>">
                            <div class="zcr-backup-card-header">
                                <div>
                                    <h3 class="zcr-backup-card-title"><?php echo esc_html($chapter->post_title); ?></h3>
                                    <span class="zcr-backup-card-id">ID: <?php echo esc_html($chapter->ID); ?></span>
                                </div>
                                <span class="zcr-status-badge <?php echo $status_class; ?>">
                                    <?php echo esc_html($status_label); ?>
                                </span>
                            </div>

                            <div class="zcr-backup-meta">
                                <div class="zcr-backup-meta-item">
                                    <span class="zcr-icon">📚</span>
                                    <span><?php echo esc_html($story_title); ?></span>
                                </div>
                                <div class="zcr-backup-meta-item">
                                    <span class="zcr-icon">📁</span>
                                    <span><?php echo esc_html($backup_filename ? $backup_filename : 'N/A'); ?></span>
                                </div>
                                <div class="zcr-backup-meta-item">
                                    <span class="zcr-icon">📅</span>
                                    <span><?php echo esc_html($backup_time ? date_i18n('M j, Y \a\t g:i A', $backup_time) : 'N/A'); ?></span>
                                </div>
                            </div>

                            <div class="zcr-backup-actions">
                                <button class="zcr-btn zcr-btn-primary restore-backup-btn"
                                    data-chapter-id="<?php echo esc_attr($chapter->ID); ?>">
                                    <span>🔄</span> Restore
                                </button>
                                <button class="zcr-btn zcr-btn-ghost delete-backup-btn"
                                    data-chapter-id="<?php echo esc_attr($chapter->ID); ?>">
                                    <span>🗑️</span> Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php
            }
            ?>

            <div id="restore-result" style="margin-top: 20px;"></div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                $('.restore-backup-btn').on('click', function () {
                    const btn = $(this);
                    const chapterId = btn.data('chapter-id');
                    const card = btn.closest('.zcr-backup-card');

                    if (!confirm('Are you sure you want to restore this chapter to its backup content?')) {
                        return;
                    }

                    const originalHtml = btn.html();
                    btn.prop('disabled', true).html('<span class="zcr-spinner"></span> Restoring...');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'restore_backup',
                            nonce: '<?php echo wp_create_nonce('restore_backup_nonce'); ?>',
                            chapter_id: chapterId
                        },
                        success: function (response) {
                            if (response.success) {
                                card.css({
                                    'background': 'var(--zcr-success-light)',
                                    'border-color': 'var(--zcr-success)'
                                });
                                alert('✅ ' + response.data.message);
                                location.reload();
                            } else {
                                alert('❌ Error: ' + response.data.message);
                                btn.prop('disabled', false).html(originalHtml);
                            }
                        },
                        error: function () {
                            alert('❌ An error occurred during restoration.');
                            btn.prop('disabled', false).html(originalHtml);
                        }
                    });
                });

                $('.delete-backup-btn').on('click', function () {
                    const btn = $(this);
                    const chapterId = btn.data('chapter-id');
                    const card = btn.closest('.zcr-backup-card');

                    if (!confirm('Are you sure you want to delete this backup? This cannot be undone.')) {
                        return;
                    }

                    const originalHtml = btn.html();
                    btn.prop('disabled', true).html('<span class="zcr-spinner"></span>');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'delete_backup',
                            nonce: '<?php echo wp_create_nonce('delete_backup_nonce'); ?>',
                            chapter_id: chapterId
                        },
                        success: function (response) {
                            if (response.success) {
                                card.css('opacity', '0.5').slideUp(300, function () {
                                    $(this).remove();
                                    // Update count
                                    const remaining = $('.zcr-backup-card').length;
                                    if (remaining === 0) {
                                        location.reload();
                                    } else {
                                        $('.zcr-batch-info strong').text(remaining);
                                    }
                                });
                            } else {
                                alert('❌ Error: ' + response.data.message);
                                btn.prop('disabled', false).html(originalHtml);
                            }
                        },
                        error: function () {
                            alert('❌ An error occurred during deletion.');
                            btn.prop('disabled', false).html(originalHtml);
                        }
                    });
                });

                $('#restore-all-btn').on('click', async function () {
                    const totalCount = $('.restore-backup-btn').length;
                    if (!confirm('Are you sure you want to restore ALL ' + totalCount + ' chapters to their backup content?')) {
                        return;
                    }

                    const btn = $(this);
                    const originalHtml = btn.html();
                    btn.prop('disabled', true);
                    $('#delete-all-btn').prop('disabled', true);

                    const buttons = $('.restore-backup-btn').toArray();
                    let completed = 0;
                    let errors = 0;

                    for (const button of buttons) {
                        const chapterId = $(button).data('chapter-id');
                        btn.html('<span class="zcr-spinner"></span> ' + (completed + 1) + '/' + totalCount);

                        try {
                            await $.ajax({
                                url: ajaxurl,
                                method: 'POST',
                                data: {
                                    action: 'restore_backup',
                                    nonce: '<?php echo wp_create_nonce('restore_backup_nonce'); ?>',
                                    chapter_id: chapterId
                                }
                            });
                            completed++;
                        } catch (e) {
                            errors++;
                        }
                    }

                    if (errors > 0) {
                        alert('Restored ' + completed + ' chapters. ' + errors + ' failed.');
                    } else {
                        alert('✅ All ' + completed + ' backups restored successfully!');
                    }
                    location.reload();
                });

                $('#delete-all-btn').on('click', async function () {
                    const totalCount = $('.delete-backup-btn').length;
                    if (!confirm('⚠️ DANGER: Are you sure you want to DELETE ALL ' + totalCount + ' backups? This cannot be undone!')) {
                        return;
                    }

                    const btn = $(this);
                    btn.prop('disabled', true);
                    $('#restore-all-btn').prop('disabled', true);

                    const buttons = $('.delete-backup-btn').toArray();
                    let completed = 0;
                    let errors = 0;

                    for (const button of buttons) {
                        const chapterId = $(button).data('chapter-id');
                        btn.html('<span class="zcr-spinner"></span> ' + (completed + 1) + '/' + totalCount);

                        try {
                            await $.ajax({
                                url: ajaxurl,
                                method: 'POST',
                                data: {
                                    action: 'delete_backup',
                                    nonce: '<?php echo wp_create_nonce('delete_backup_nonce'); ?>',
                                    chapter_id: chapterId
                                }
                            });
                            completed++;
                        } catch (e) {
                            errors++;
                        }
                    }

                    if (errors > 0) {
                        alert('Deleted ' + completed + ' backups. ' + errors + ' failed.');
                    } else {
                        alert('✅ All ' + completed + ' backups deleted successfully!');
                    }
                    location.reload();
                });
            });
        </script>
        <?php
    }

    public function check_resume_session()
    {
        check_ajax_referer('zip_replacer_resume_nonce', 'nonce');
        $user_id = get_current_user_id();
        $resume_data = get_transient(self::RESUME_TRANSIENT_KEY . $user_id);

        if ($resume_data) {
            wp_send_json_success(['has_session' => true, 'data' => $resume_data]);
        } else {
            wp_send_json_success(['has_session' => false]);
        }
    }

    public function resume_processing()
    {
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

    private function check_rate_limit($user_id)
    {
        $key = self::RATE_LIMIT_TRANSIENT . $user_id;
        $count = get_transient($key);

        if ($count && $count > 20) {
            wp_send_json_error(['message' => 'Too many requests. Please wait 1 minute before trying again.']);
        }

        set_transient($key, ($count ? $count + 1 : 1), 60);
    }

    public function handle_zip_upload_ajax()
    {
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

        $max_size = $this->get_max_file_size();
        if ($file['size'] > $max_size) {
            $max_mb = round($max_size / 1048576, 1);
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: Maximum file size in MB */
                    __('Error: File is larger than the allowed limit of %sMB.', 'zip-content-replacer'),
                    $max_mb
                )
            ]);
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

        $match_method = isset($_POST['match_method']) ? sanitize_text_field(wp_unslash($_POST['match_method'])) : 'title';

        $settings = [
            'zip_path' => $zip_path,
            'post_type' => 'fcn_chapter',
            'fictioneer_story_id' => $fictioneer_story_id,
            'batch_size' => intval($_POST['batch_size']),
            'is_dry_run' => isset($_POST['dry_run']),
            'show_preview' => isset($_POST['show_preview']),
            'backup_content' => isset($_POST['backup_content']),
            'match_method' => $match_method
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

    public function process_zip_batch()
    {
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

                    /**
                     * Fires before a chapter is updated with new content.
                     * 
                     * @param int    $post_id  The chapter post ID.
                     * @param string $content  The new content to be applied.
                     * @param string $filename The source filename from the ZIP.
                     */
                    do_action('zip_content_replacer_before_update', $post->ID, $content, $filename);

                    /**
                     * Filter the content before saving.
                     * 
                     * @param string $content  The content to be saved.
                     * @param string $filename The source filename from the ZIP.
                     * @param int    $post_id  The chapter post ID.
                     */
                    $processed_content = $this->process_file_content($content, $filename);
                    $processed_content = apply_filters('zip_content_replacer_content', $processed_content, $filename, $post->ID);

                    $updated = wp_update_post([
                        'ID' => $post->ID,
                        'post_content' => $processed_content
                    ]);

                    if (is_wp_error($updated)) {
                        $logs[] = "<span style='color: red;'>❌ ERROR: Failed to update chapter '{$filename_base}' (ID: {$post->ID}). WP_Error: {$updated->get_error_message()}.</span>";
                        error_log("ZIP Replacer: Update failed for post {$post->ID} - " . $updated->get_error_message());
                    } elseif ($updated === 0) {
                        $logs[] = "<span style='color: #666;'>ℹ️ INFO: Chapter '{$filename_base}' (ID: {$post->ID}) found but no content changes detected.</span>";
                    } else {
                        /**
                         * Fires after a chapter has been successfully updated.
                         * 
                         * @param int    $post_id  The chapter post ID.
                         * @param string $content  The new content that was applied.
                         * @param string $filename The source filename from the ZIP.
                         */
                        do_action('zip_content_replacer_after_update', $post->ID, $content, $filename);

                        // Attempt to refresh Fictioneer caches if available
                        if (function_exists('fictioneer_refresh_post_caches')) {
                            fictioneer_refresh_post_caches($post->ID);
                        }

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
            if (file_exists($zip_path))
                unlink($zip_path);
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
            'processed' => $next_offset,
            'remaining' => $remaining,
            'total' => $total_files,
            'next_offset' => $next_offset,
            'logs' => $logs,
            'is_dry_run' => $is_dry_run
        ]);
    }

    private function get_story_chapters_map($story_id, $match_method)
    {
        $cache_key = self::CHAPTER_CACHE_KEY . $story_id . '_' . $match_method;
        $cached = get_transient($cache_key);

        if (false !== $cached) {
            return $cached;
        }

        // Try to use Fictioneer's helper first (more efficient, maintains order)
        $chapter_ids = null;
        if (function_exists('fictioneer_get_story_chapter_ids')) {
            $chapter_ids = fictioneer_get_story_chapter_ids($story_id);
        }

        // Fallback: direct meta read or query
        if (empty($chapter_ids)) {
            // Try reading from story meta directly
            $stored_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
            if (is_array($stored_chapters) && !empty($stored_chapters)) {
                $chapter_ids = $stored_chapters;
            } else {
                // Final fallback: query by meta
                $args = [
                    'post_type' => 'fcn_chapter',
                    'posts_per_page' => -1,
                    'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
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
            }
        }

        $map = [];
        foreach ($chapter_ids as $chapter_id) {
            // Verify post exists and is right type
            $post = get_post($chapter_id);
            if (!$post || $post->post_type !== 'fcn_chapter') {
                continue;
            }

            if ($match_method === 'slug') {
                $map[$post->post_name] = $chapter_id;
            } else {
                $map[$post->post_title] = $chapter_id;
            }
        }

        set_transient($cache_key, $map, 300);
        return $map;
    }

    private function find_chapter_by_slug($slug, $story_id, $post_type)
    {
        $args = [
            'post_type' => $post_type,
            'name' => $slug,
            'posts_per_page' => 1,
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
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

    private function check_for_duplicates($identifier, $story_id, $post_type, $match_method)
    {
        if ($match_method === 'slug') {
            $args = [
                'post_type' => $post_type,
                'name' => sanitize_title($identifier),
                'posts_per_page' => -1,
                'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
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
                'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
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

    private function is_supported_file($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = apply_filters('zip_content_replacer_allowed_extensions', $this->allowed_extensions);
        return in_array($ext, $allowed);
    }

    /**
     * Get maximum allowed file size.
     * 
     * @return int Maximum file size in bytes.
     */
    private function get_max_file_size()
    {
        return apply_filters('zip_content_replacer_max_file_size', $this->max_file_size);
    }

    /**
     * Process file content based on extension.
     * Converts markdown to HTML and wraps output in Gutenberg block format.
     * 
     * @param string $content Raw file content.
     * @param string $filename Original filename.
     * @return string Processed content with Gutenberg block markers.
     */
    private function process_file_content($content, $filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $html = '';

        switch ($ext) {
            case 'md':
                // Use Parsedown for markdown - check if bulk upload plugin's function exists
                if (function_exists('bcu_markdown_to_html')) {
                    $html = bcu_markdown_to_html($content);
                } else {
                    // Fallback: load local Parsedown if available, otherwise use wpautop
                    $parsedown_path = ZIP_CONTENT_REPLACER_PLUGIN_DIR . 'includes/Parsedown.php';
                    $parsedown_extra_path = ZIP_CONTENT_REPLACER_PLUGIN_DIR . 'includes/ParsedownExtra.php';

                    if (file_exists($parsedown_extra_path) && file_exists($parsedown_path)) {
                        require_once $parsedown_path;
                        require_once $parsedown_extra_path;
                        // Create new instance each time to reset footnote counter per chapter
                        $parsedown = new ParsedownExtra();
                        $parsedown->setSafeMode(true);
                        $parsedown->setBreaksEnabled(true);
                        $html = $parsedown->text($content);
                    } elseif (file_exists($parsedown_path)) {
                        require_once $parsedown_path;
                        // Create new instance each time to reset footnote counter per chapter
                        $parsedown = new Parsedown();
                        $parsedown->setSafeMode(true);
                        $parsedown->setBreaksEnabled(true);
                        $html = $parsedown->text($content);
                    } else {
                        // Basic fallback without markdown
                        $html = wpautop(wp_kses_post($content));
                    }
                }
                break;
            case 'html':
            case 'htm':
                // Already HTML, sanitize and use as-is
                $html = wp_kses_post($content);
                break;
            case 'txt':
            default:
                // Plain text - convert to paragraphs
                $html = wpautop(wp_kses_post($content));
                break;
        }

        // Convert to Gutenberg block format
        return $this->html_to_gutenberg_blocks($html);
    }

    /**
     * Convert raw HTML to Gutenberg block format.
     * 
     * @param string $html Raw HTML content.
     * @return string HTML with Gutenberg block markers.
     */
    private function html_to_gutenberg_blocks($html)
    {
        // Use bulk upload plugin's function if available
        if (function_exists('bcu_html_to_blocks')) {
            return bcu_html_to_blocks($html);
        }

        // Don't process if already has block markers
        if (strpos($html, '<!-- wp:') !== false) {
            return $html;
        }

        $html = trim($html);
        if (empty($html)) {
            return '';
        }

        // Use DOMDocument for reliable HTML parsing
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="zcr-wrapper">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $wrapper = $dom->getElementById('zcr-wrapper');
        if (!$wrapper) {
            // Fallback: wrap everything in a paragraph block
            return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->";
        }

        $output = '';

        foreach ($wrapper->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim($node->textContent);
                if (!empty($text)) {
                    $output .= "<!-- wp:paragraph -->\n<p>" . esc_html($text) . "</p>\n<!-- /wp:paragraph -->\n\n";
                }
                continue;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tagName = strtolower($node->nodeName);
            $outerHtml = $dom->saveHTML($node);

            switch ($tagName) {
                case 'p':
                    $output .= "<!-- wp:paragraph -->\n" . $outerHtml . "\n<!-- /wp:paragraph -->\n\n";
                    break;

                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $level = substr($tagName, 1);
                    $output .= "<!-- wp:heading {\"level\":" . $level . "} -->\n" . $outerHtml . "\n<!-- /wp:heading -->\n\n";
                    break;

                case 'ul':
                    $output .= "<!-- wp:list -->\n" . $outerHtml . "\n<!-- /wp:list -->\n\n";
                    break;

                case 'ol':
                    $output .= "<!-- wp:list {\"ordered\":true} -->\n" . $outerHtml . "\n<!-- /wp:list -->\n\n";
                    break;

                case 'blockquote':
                    $output .= "<!-- wp:quote -->\n" . $outerHtml . "\n<!-- /wp:quote -->\n\n";
                    break;

                case 'pre':
                    $output .= "<!-- wp:code -->\n" . $outerHtml . "\n<!-- /wp:code -->\n\n";
                    break;

                case 'hr':
                    $output .= "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n\n";
                    break;

                case 'table':
                    $output .= "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . $outerHtml . "</figure>\n<!-- /wp:table -->\n\n";
                    break;

                case 'div':
                    // Handle footnotes container from ParsedownExtra
                    $class = $node->getAttribute('class');
                    if (strpos($class, 'footnotes') !== false) {
                        $output .= "<!-- wp:html -->\n" . $outerHtml . "\n<!-- /wp:html -->\n\n";
                    } else {
                        // Other divs - wrap as group block
                        $output .= "<!-- wp:group -->\n" . $outerHtml . "\n<!-- /wp:group -->\n\n";
                    }
                    break;

                default:
                    // For unknown elements, just output as-is
                    $output .= $outerHtml . "\n\n";
                    break;
            }
        }

        return apply_filters('zip_content_replacer_gutenberg_blocks', trim($output));
    }

    public function restore_backup_ajax()
    {
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

    public function delete_backup_ajax()
    {
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
