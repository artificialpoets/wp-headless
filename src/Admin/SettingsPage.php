<?php
/**
 * WP Headless admin status page.
 *
 * Theme activation is handled by WordPress itself (Appearance → Themes).
 * This page is informational only: it tells the admin whether headless
 * mode is currently engaged, which theme is acting as the headless theme,
 * and what to do if the build is missing or the wrong theme is active.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Admin;

use WPHeadless\Config\Config;
use WPHeadless\Theme\ThemeManager;
use WPHeadless\Contracts\Module;

class SettingsPage implements Module {
	/** @var ThemeManager */
	private ThemeManager $theme_manager;

	/** @var Config */
	private Config $config;

	public function __construct( ThemeManager $theme_manager, ?Config $config = null ) {
		$this->theme_manager = $theme_manager;
		$this->config        = $config ?? new Config();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'WP Headless', 'wp-headless' ),
			__( 'WP Headless', 'wp-headless' ),
			'manage_options',
			'wp-headless',
			array( $this, 'render' )
		);
	}

	public function enqueue_styles( string $hook ): void {
		if ( 'settings_page_wp-headless' !== $hook ) {
			return;
		}

		wp_add_inline_style( 'wp-admin', $this->page_css() );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_theme = $this->theme_manager->get_active_theme();
		$has_build    = $this->theme_manager->has_build();
		$dist_path    = $this->theme_manager->resolve_dist_path();
		$is_engaged   = (bool) $active_theme && $has_build;

		?>
		<div class="wrap wph-page">
			<h1><?php esc_html_e( 'WP Headless', 'wp-headless' ); ?></h1>

			<div class="wph-status wph-status--<?php echo $is_engaged ? 'on' : 'off'; ?>">
				<div class="wph-status__dot" aria-hidden="true"></div>
				<div class="wph-status__body">
					<?php if ( $is_engaged ) : ?>
						<strong><?php esc_html_e( 'Headless mode is active.', 'wp-headless' ); ?></strong>
						<p>
							<?php
							printf(
								/* translators: %s: theme display name */
								esc_html__( 'The active theme %s has a React build at dist/index.html, so the wp-headless plugin is serving the frontend.', 'wp-headless' ),
								'<code>' . esc_html( $active_theme ? $active_theme->name() : '' ) . '</code>'
							);
							?>
						</p>
					<?php elseif ( $active_theme && ! $has_build ) : ?>
						<strong><?php esc_html_e( 'Headless mode is paused.', 'wp-headless' ); ?></strong>
						<p>
							<?php
							printf(
								/* translators: 1: theme name, 2: dist path */
								esc_html__( 'The active theme %1$s does not have a built React app. Run the build to enable headless mode (expected: %2$s).', 'wp-headless' ),
								'<code>' . esc_html( $active_theme->name() ) . '</code>',
								'<code>' . esc_html( $active_theme->dist_path() . '/index.html' ) . '</code>'
							);
							?>
						</p>
					<?php else : ?>
						<strong><?php esc_html_e( 'No active theme detected.', 'wp-headless' ); ?></strong>
					<?php endif; ?>
				</div>
			</div>

			<h2><?php esc_html_e( 'How activation works', 'wp-headless' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: link to Appearance → Themes */
					esc_html__( 'Switch the headless theme on or off through %s. Any theme that ships a built React app at dist/index.html will automatically take over the public frontend when activated.', 'wp-headless' ),
					'<a href="' . esc_url( admin_url( 'themes.php' ) ) . '">Appearance → Themes</a>'
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Build status', 'wp-headless' ); ?></h2>
			<table class="widefat striped wph-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Active theme', 'wp-headless' ); ?></th>
						<td>
							<?php if ( $active_theme ) : ?>
								<strong><?php echo esc_html( $active_theme->name() ); ?></strong>
								<?php if ( $active_theme->version() ) : ?>
									<span class="wph-muted">v<?php echo esc_html( $active_theme->version() ); ?></span>
								<?php endif; ?>
								&mdash;
								<code><?php echo esc_html( $active_theme->slug() ); ?></code>
							<?php else : ?>
								<em><?php esc_html_e( 'None', 'wp-headless' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Theme directory', 'wp-headless' ); ?></th>
						<td><code><?php echo esc_html( $active_theme ? $active_theme->path() : '' ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Build dist path', 'wp-headless' ); ?></th>
						<td>
							<?php if ( $dist_path ) : ?>
								<code><?php echo esc_html( $dist_path ); ?></code> ✓
							<?php else : ?>
								<em><?php esc_html_e( 'No build found', 'wp-headless' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Asset mount', 'wp-headless' ); ?></th>
						<td><code><?php echo esc_url( $this->config->asset_base_url() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'REST namespace', 'wp-headless' ); ?></th>
						<td><code><?php echo esc_url( rest_url( $this->config->namespace() . '/' ) ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Modules', 'wp-headless' ); ?></h2>
			<p>
				<?php esc_html_e( 'Each feature boots as a module. Disable any module per site via the config file — modules.{key}.enabled — or register additional modules through the wp_headless_modules filter (see docs/HOOKS.md).', 'wp-headless' ); ?>
			</p>
			<table class="widefat striped wph-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Module', 'wp-headless' ); ?></th>
						<th><?php esc_html_e( 'Class', 'wp-headless' ); ?></th>
						<th><?php esc_html_e( 'Enabled', 'wp-headless' ); ?></th>
						<th><?php esc_html_e( 'Registered', 'wp-headless' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->module_status() as $key => $module ) : ?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><code><?php echo esc_html( $module['class'] ); ?></code></td>
							<td><?php echo $module['enabled'] ? '✓' : '<em>' . esc_html__( 'disabled', 'wp-headless' ) . '</em>'; ?></td>
							<td><?php echo $module['registered'] ? '✓' : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'SEO & discovery', 'wp-headless' ); ?></h2>
			<table class="widefat striped wph-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Schema graph', 'wp-headless' ); ?></th>
						<td>
							<?php if ( \WPHeadless\Runtime\SeoHead::seo_plugin_active() ) : ?>
								<em><?php esc_html_e( 'Standing down — a dedicated SEO plugin owns the head output.', 'wp-headless' ); ?></em>
							<?php elseif ( $this->config->get( 'seo.schema.enabled', true ) ) : ?>
								✓ <?php esc_html_e( 'JSON-LD @graph emitted on served pages.', 'wp-headless' ); ?>
							<?php else : ?>
								<em><?php esc_html_e( 'Disabled via config (seo.schema.enabled).', 'wp-headless' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'llms.txt', 'wp-headless' ); ?></th>
						<td>
							<?php if ( $this->config->get( 'seo.llms.enabled', true ) ) : ?>
								<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a>
								<?php if ( $this->config->get( 'seo.llms.full', false ) ) : ?>
									&mdash; <a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank" rel="noopener">llms-full.txt</a>
								<?php endif; ?>
							<?php else : ?>
								<em><?php esc_html_e( 'Disabled via config (seo.llms.enabled).', 'wp-headless' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI crawler policy', 'wp-headless' ); ?></th>
						<td><code><?php echo esc_html( (string) $this->config->get( 'seo.robots_txt.ai_policy', 'allow' ) ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Endpoints', 'wp-headless' ); ?></h2>
			<ul class="wph-endpoints">
				<li>
					<code>GET <?php echo esc_html( rest_url( $this->config->namespace() . '/runtime' ) ); ?></code>
					&mdash; <?php esc_html_e( 'Runtime payload injected into every public request.', 'wp-headless' ); ?>
				</li>
				<li>
					<code>GET <?php echo esc_html( rest_url( $this->config->namespace() . '/resolve' ) ); ?>?url={URL}</code>
					&mdash; <?php esc_html_e( 'Resolve a URL to its WordPress request context.', 'wp-headless' ); ?>
				</li>
				<li>
					<code>GET <?php echo esc_html( rest_url( $this->config->namespace() . '/menus' ) ); ?>?location={slug}</code>
					&mdash; <?php esc_html_e( 'Fetch a nav menu by location, slug, or id.', 'wp-headless' ); ?>
				</li>
				<li>
					<code>GET <?php echo esc_html( home_url( '/llms.txt' ) ); ?></code>
					&mdash; <?php esc_html_e( 'Site map for LLM/AI agents (llmstxt.org format).', 'wp-headless' ); ?>
				</li>
			</ul>

			<h2><?php esc_html_e( 'Recommended starter themes', 'wp-headless' ); ?></h2>
			<p>
				<?php esc_html_e( 'The wp-headless project ships two starter themes that you can install and activate like any other WordPress theme:', 'wp-headless' ); ?>
			</p>
			<ul>
				<li><strong>WP Headless Starter (JS)</strong> &mdash; React + Vite + CSS Modules.</li>
				<li><strong>WP Headless Starter (TS)</strong> &mdash; React + TypeScript + Vite + CSS Modules.</li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Module status rows from the shared plugin instance.
	 *
	 * @return array<string, array{class: string, enabled: bool, registered: bool}>
	 */
	private function module_status(): array {
		if ( ! function_exists( 'wp_headless_plugin' ) ) {
			return array();
		}

		return wp_headless_plugin()->modules();
	}

	private function page_css(): string {
		return '
.wph-page { max-width: 960px; }
.wph-status {
	display: flex;
	gap: 16px;
	align-items: flex-start;
	padding: 18px 20px;
	margin: 16px 0 24px;
	border-radius: 6px;
	border: 1px solid #dcdcde;
	background: #fff;
}
.wph-status--on  { background: #f0f9ee; border-color: #b8dab2; }
.wph-status--off { background: #fcf9e8; border-color: #dad0a6; }
.wph-status__dot {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	margin-top: 6px;
	flex-shrink: 0;
	background: #999;
}
.wph-status--on  .wph-status__dot { background: #2da94f; }
.wph-status--off .wph-status__dot { background: #d4a017; }
.wph-status__body strong { display: block; margin-bottom: 4px; }
.wph-status__body p { margin: 0; color: #50575e; }
.wph-table { margin: 16px 0; }
.wph-table th { width: 200px; }
.wph-muted { color: #8c8f94; margin-left: 6px; }
.wph-endpoints { padding-left: 0; list-style: none; }
.wph-endpoints li { margin-bottom: 8px; }
		';
	}
}
