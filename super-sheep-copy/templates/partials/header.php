<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to included view scope.
/**
 * @var string $page_title
 * @var string $page_subtitle
 */
defined('ABSPATH') || exit;
$logo_url = (defined('SUPER_SHEEP_COPY_URL') ? SUPER_SHEEP_COPY_URL : '') . 'assets/images/super-sheep-copy-logo.png';
?>
<h1 class="super-sheep-copy-screen-title"><?php echo esc_html($page_title); ?></h1>
<div class="super-sheep-copy-header">
    <img class="super-sheep-copy-header-logo" src="<?php echo esc_attr($logo_url); ?>" alt="<?php echo esc_attr(__('Super Sheep Copy', 'super-sheep-copy')); ?>" width="72" height="72" />
    <div class="super-sheep-copy-header-copy">
        <span class="super-sheep-copy-header-title"><?php echo esc_html($page_title); ?></span>
        <p><?php echo esc_html($page_subtitle); ?></p>
    </div>
</div>
