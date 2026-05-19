<?php
/**
 * Fallback when no React build is available.
 *
 * When the wp-headless plugin is active and dist/index.html exists, the
 * plugin's FrontendBridge intercepts every public request before WordPress
 * reaches this file. Seeing this page means the plugin is inactive or the
 * theme has not been built yet.
 *
 * @package WPHeadlessStarterJs
 */

defined( 'ABSPATH' ) || exit;

$dist_exists    = is_readable( get_stylesheet_directory() . '/dist/index.html' );
$plugin_active  = is_plugin_active( 'wp-headless/wp-headless.php' );
$theme_basename = wp_basename( get_stylesheet_directory() );

get_header();
?>
<main id="main-content" style="max-width:40rem;margin:4rem auto;padding:0 1.5rem;font:17px/1.6 system-ui, sans-serif;color:#111;">
	<h1 style="font-size:1.75rem;margin:0 0 1rem;">
		<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
	</h1>
	<p style="color:#444;">
		This theme is headless. The React frontend is rendered by the
		<code>wp-headless</code> plugin and your <code>dist/</code> directory.
	</p>

	<?php if ( ! $plugin_active ) : ?>
		<h2>The wp-headless plugin is not active</h2>
		<p>
			Activate it in
			<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Plugins</a>
			to enable headless mode.
		</p>
	<?php elseif ( ! $dist_exists ) : ?>
		<h2>This theme needs a build</h2>
		<p>Run the build inside the theme directory:</p>
		<pre style="background:#f5f5f5;padding:1rem;border-radius:4px;overflow-x:auto;font-size:.9em;">cd wp-content/themes/<?php echo esc_html( $theme_basename ); ?>
npm install
npm run build</pre>
		<p>
			Once <code>dist/index.html</code> exists, the wp-headless plugin
			will take over and serve the React frontend.
		</p>
	<?php else : ?>
		<p>
			The plugin and build look correct, but template_redirect didn't fire
			for this request. Check that the plugin is loaded before your theme
			by visiting <a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-headless' ) ); ?>">WP Headless settings</a>.
		</p>
	<?php endif; ?>
</main>
<?php
get_footer();
