<?php
/**
 * Plugin Name: Simple Rating Form
 * Description: فرم امتیازدهی ۱ تا ۱۰ همراه با بازخورد اختیاری و گزارش مدیریت.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: AmirFakhar
 * Text Domain: simple-rating-form
 */
defined('ABSPATH') || exit;

final class AFR_Rating_Form {
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'afr_rating_submissions';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rating tinyint unsigned NOT NULL,
            comment text NOT NULL,
            submission_time datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;");
    }

    public static function init() {
        add_shortcode('simple_rating_form', array(__CLASS__, 'form'));
        add_action('wp_ajax_afr_submit', array(__CLASS__, 'submit'));
        add_action('wp_ajax_nopriv_afr_submit', array(__CLASS__, 'submit'));
        add_action('wp_ajax_afr_token', array(__CLASS__, 'token'));
        add_action('wp_ajax_nopriv_afr_token', array(__CLASS__, 'token'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
    }

    public static function token() {
        nocache_headers();
        wp_send_json_success(array('nonce' => wp_create_nonce('afr_submit')));
    }

    public static function form() {
        wp_enqueue_style('afr-form', plugins_url('assets/form.css', __FILE__), array(), '1.0.0');
        wp_enqueue_script('afr-form', plugins_url('assets/form.js', __FILE__), array(), '1.0.0', true);
        $id = wp_unique_id('afr-');
        ob_start(); ?>
        <form class="afr-form" dir="rtl" data-endpoint="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <h2>تجربه‌تان چطور بود؟</h2>
            <p>از ۱ (ضعیف) تا ۱۰ (عالی) امتیاز دهید.</p>
            <fieldset><legend>امتیاز شما</legend><div class="afr-options">
                <?php for ($i = 1; $i <= 10; $i++) : ?>
                <label><input type="radio" name="rating" value="<?php echo esc_attr($i); ?>" required><span><?php echo esc_html($i); ?></span></label>
                <?php endfor; ?>
            </div></fieldset>
            <div class="afr-comment" hidden>
                <label for="<?php echo esc_attr($id); ?>">چطور بهتر شویم؟ (اختیاری)</label>
                <textarea id="<?php echo esc_attr($id); ?>" name="comment" maxlength="2000" rows="4" disabled></textarea>
                <small>حداکثر ۲۰۰۰ نویسه؛ لطفاً اطلاعات شخصی یا حساس ننویسید.</small>
            </div>
            <div class="afr-trap" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
            <button type="submit">ثبت امتیاز</button>
            <p class="afr-status" role="status" aria-live="polite"></p>
            <noscript>برای ارسال امتیاز، جاوااسکریپت مرورگر را فعال کنید.</noscript>
        </form>
        <?php return ob_get_clean();
    }

    public static function submit() {
        if (!check_ajax_referer('afr_submit', 'nonce', false)) {
            wp_send_json_error(array('message' => 'اعتبار فرم تمام شده؛ دوباره تلاش کنید.'), 403);
        }
        if (!isset($_POST['rating']) || !is_string($_POST['rating']) || !preg_match('/^(?:[1-9]|10)$/D', $_POST['rating'])) {
            wp_send_json_error(array('message' => 'امتیاز باید عدد صحیح بین ۱ تا ۱۰ باشد.'), 400);
        }
        if (!empty($_POST['website'])) {
            wp_send_json_error(array('message' => 'ارسال معتبر نیست.'), 400);
        }
        $rating = (int) $_POST['rating'];
        $raw = isset($_POST['comment']) ? $_POST['comment'] : '';
        if (!is_string($raw)) {
            wp_send_json_error(array('message' => 'متن نظر معتبر نیست.'), 400);
        }
        $comment = $rating < 5 ? sanitize_textarea_field(wp_unslash($raw)) : '';
        $length = function_exists('mb_strlen') ? mb_strlen($comment, 'UTF-8') : strlen($comment);
        if ($length > 2000) {
            wp_send_json_error(array('message' => 'نظر بیش از حد طولانی است.'), 400);
        }
        // Only a short-lived salted hash is stored, never the raw IP address.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'afr_limit_' . hash_hmac('sha256', $ip, wp_salt('nonce'));
        if (get_transient($key)) {
            wp_send_json_error(array('message' => 'لطفاً یک دقیقه تا ارسال بعدی صبر کنید.'), 429);
        }
        global $wpdb;
        $saved = $wpdb->insert($wpdb->prefix . 'afr_rating_submissions', array(
            'rating' => $rating,
            'comment' => $comment,
            'submission_time' => current_time('mysql'),
        ), array('%d', '%s', '%s'));
        if (false === $saved) {
            wp_send_json_error(array('message' => 'ذخیره‌سازی انجام نشد؛ دوباره تلاش کنید.'), 500);
        }
        set_transient($key, 1, MINUTE_IN_SECONDS);
        wp_send_json_success(array('message' => 'ممنون! امتیاز شما ثبت شد.'));
    }

    public static function menu() {
        add_management_page('بازخوردها', 'بازخوردها', 'manage_options', 'afr-ratings', array(__CLASS__, 'admin'));
    }

    public static function admin() {
        if (!current_user_can('manage_options')) { return; }
        global $wpdb;
        $table = $wpdb->prefix . 'afr_rating_submissions';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $average = $wpdb->get_var("SELECT AVG(rating) FROM $table");
        $pages = max(1, (int) ceil($count / 20));
        $page = isset($_GET['paged']) && is_scalar($_GET['paged']) ? min($pages, max(1, absint($_GET['paged']))) : 1;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT rating, comment, submission_time FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", 20, ($page - 1) * 20));
        echo '<div class="wrap" dir="rtl"><h1>بازخوردها</h1><p>تعداد: ' . esc_html(number_format_i18n($count)) . ' | میانگین: ' . esc_html($count ? number_format_i18n((float) $average, 1) . ' / 10' : '—') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>امتیاز</th><th>نظر</th><th>زمان (منطقهٔ زمانی سایت)</th></tr></thead><tbody>';
        if (!$rows) { echo '<tr><td colspan="3">هنوز بازخوردی ثبت نشده است.</td></tr>'; }
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->rating) . '</td><td style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html($row->comment) . '</td><td>' . esc_html($row->submission_time) . '</td></tr>';
        }
        echo '</tbody></table><p>';
        echo wp_kses_post(paginate_links(array('base' => add_query_arg('paged', '%#%', admin_url('tools.php?page=afr-ratings')), 'format' => '', 'current' => $page, 'total' => $pages)));
        echo '</p></div>';
    }
}
register_activation_hook(__FILE__, array('AFR_Rating_Form', 'activate'));
AFR_Rating_Form::init();
