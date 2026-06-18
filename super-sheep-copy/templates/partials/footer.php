<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial has no external input.
defined('ABSPATH') || exit;
$plugin_version = defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.1';
$github_url = 'https://github.com/humbertqz/Super-Sheep-Copy';
?>
<footer class="super-sheep-copy-footer">
    <div class="super-sheep-copy-footer-brand">
        <strong><?php echo esc_html__('Super Sheep Copy', 'super-sheep-copy'); ?></strong>
        <span><?php echo esc_html(sprintf(__('Version %s', 'super-sheep-copy'), $plugin_version)); ?></span>
    </div>
    <a class="super-sheep-copy-footer-link" href="<?php echo esc_attr($github_url); ?>" target="_blank" rel="noopener noreferrer">
        <?php echo esc_html__('GitHub repository', 'super-sheep-copy'); ?>
    </a>
</footer>
