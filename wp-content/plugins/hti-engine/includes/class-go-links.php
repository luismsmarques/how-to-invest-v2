<?php
/**
 * Managed outbound links for /go/{slug} — the self-service half of the
 * redirector (broker-affiliate skill).
 *
 * The broker section owns its own /go/ slugs (resolved from the `broker` CPT).
 * This class adds slugs the owner creates by hand in wp-admin, for links used
 * OFF the site — Telegram, social bios, newsletters, campaigns — where a
 * tracked, swappable URL is worth more than an editorial page:
 *
 * - the click is counted server-side, per slug and per channel (`loc`);
 * - the destination is swappable without touching anything that was published;
 * - the affiliate URL never appears anywhere public.
 *
 * A managed link NEVER shadows a broker: the redirector resolves brokers first,
 * and the admin refuses a slug that already belongs to one — the editorial
 * section's compliance guarantees (disclosure, CFD warning, verified data)
 * must not be bypassable by an option row.
 *
 * Compliance note surfaced in the admin: posting an affiliate link on an
 * external channel carries the same CMVM disclosure duty as a page on the
 * site — the disclosure belongs in the post/bio that carries the link.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Owner-managed /go/ slugs: store, admin screen and resolution.
 */
class Go_Links {

	/**
	 * Option holding the links: slug => { url, label, active, created }.
	 */
	public const OPTION = 'hti_go_links';

	/**
	 * Upper bound on managed links, so the option (and the per-day metrics
	 * breakdown) can never grow without limit.
	 */
	public const MAX_LINKS = 200;

	/**
	 * Wire the admin screen and its handlers.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_hti_go_link_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_hti_go_link_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/* -------------------------------------------------------------------------
	 * Store (pure helpers below are unit-tested without WordPress).
	 * ---------------------------------------------------------------------- */

	/**
	 * All managed links, normalized.
	 *
	 * @return array<string,array{url:string,label:string,active:bool,created:string}>
	 */
	public static function links(): array {
		$raw = get_option( self::OPTION, array() );
		return self::normalize( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Persist a link set.
	 *
	 * @param array<string,mixed> $links Links keyed by slug.
	 */
	private static function put( array $links ): void {
		update_option( self::OPTION, self::normalize( $links ), false );
	}

	/**
	 * Normalize a stored/incoming link set: valid slugs, https destinations,
	 * bounded size. Pure.
	 *
	 * @param array<string,mixed> $links Raw links.
	 * @return array<string,array{url:string,label:string,active:bool,created:string}>
	 */
	public static function normalize( array $links ): array {
		$out = array();
		foreach ( $links as $slug => $link ) {
			$slug = self::clean_slug( (string) $slug );
			if ( '' === $slug || ! is_array( $link ) ) {
				continue;
			}
			$url = self::clean_url( (string) ( $link['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$out[ $slug ] = array(
				'url'     => $url,
				'label'   => trim( (string) ( $link['label'] ?? '' ) ),
				'active'  => ! empty( $link['active'] ),
				'created' => (string) ( $link['created'] ?? gmdate( 'Y-m-d' ) ),
			);
			if ( count( $out ) >= self::MAX_LINKS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A usable /go/ slug, or '' when the input can't make one. Pure.
	 *
	 * @param string $slug Raw slug.
	 */
	public static function clean_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = preg_replace( '/[^a-z0-9\-]/', '-', $slug );
		$slug = trim( (string) preg_replace( '/-+/', '-', (string) $slug ), '-' );
		// The route regex only matches lowercase letters, digits and hyphens.
		return (string) $slug;
	}

	/**
	 * An acceptable destination, or '' when it isn't one. HTTPS only — the
	 * same rule the broker meta enforces. Pure.
	 *
	 * @param string $url Raw URL.
	 */
	public static function clean_url( string $url ): string {
		$url = trim( $url );
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return '';
		}
		// Reject anything that isn't a plain absolute URL (no spaces, no
		// embedded control characters).
		if ( preg_match( '/\s/', $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Add or replace one link. Pure — the caller persists the result.
	 *
	 * @param array<string,mixed> $links  Existing links.
	 * @param string              $slug   Slug.
	 * @param string              $url    Destination.
	 * @param string              $label  Human label (for the admin table).
	 * @param bool                $active Whether the link redirects.
	 * @return array<string,mixed> The updated set (unchanged when invalid).
	 */
	public static function upsert( array $links, string $slug, string $url, string $label, bool $active ): array {
		$slug = self::clean_slug( $slug );
		$url  = self::clean_url( $url );
		if ( '' === $slug || '' === $url ) {
			return $links;
		}
		if ( ! isset( $links[ $slug ] ) && count( $links ) >= self::MAX_LINKS ) {
			return $links;
		}

		$links[ $slug ] = array(
			'url'     => $url,
			'label'   => trim( $label ),
			'active'  => $active,
			'created' => (string) ( $links[ $slug ]['created'] ?? gmdate( 'Y-m-d' ) ),
		);
		return $links;
	}

	/**
	 * Remove one link. Pure.
	 *
	 * @param array<string,mixed> $links Existing links.
	 * @param string              $slug  Slug to drop.
	 * @return array<string,mixed>
	 */
	public static function remove( array $links, string $slug ): array {
		unset( $links[ self::clean_slug( $slug ) ] );
		return $links;
	}

	/**
	 * Destination for a slug within a link set: only while the link exists,
	 * is active and still https. '' otherwise (the redirector 404s). Pure.
	 *
	 * @param array<string,mixed> $links Link set.
	 * @param string              $slug  Requested slug.
	 */
	public static function resolve( array $links, string $slug ): string {
		$slug = self::clean_slug( $slug );
		$link = $links[ $slug ] ?? null;
		if ( ! is_array( $link ) || empty( $link['active'] ) ) {
			return '';
		}
		return self::clean_url( (string) ( $link['url'] ?? '' ) );
	}

	/**
	 * Destination for a slug from the stored set ('' → not ours).
	 *
	 * @param string $slug Requested slug.
	 */
	public static function destination( string $slug ): string {
		return self::resolve( self::links(), $slug );
	}

	/**
	 * Whether a slug is already taken by a broker post — those own their /go/
	 * slug and must never be shadowed by a managed link.
	 *
	 * @param string $slug Slug.
	 */
	public static function slug_taken_by_broker( string $slug ): bool {
		return get_page_by_path( $slug, OBJECT, 'broker' ) instanceof \WP_Post;
	}

	/* -------------------------------------------------------------------------
	 * Admin (Tools → Outbound links).
	 * ---------------------------------------------------------------------- */

	/**
	 * Register the Tools page.
	 */
	public static function admin_menu(): void {
		add_management_page(
			__( 'HowToInvest — Outbound links', 'hti-engine' ),
			__( 'Outbound links', 'hti-engine' ),
			'manage_options',
			'hti-go-links',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Clicks per slug over the last 30 days, from the first-party counters.
	 *
	 * @return array<string,int>
	 */
	private static function clicks(): array {
		$totals = Metrics::totals( 30 );
		$bkr    = isset( $totals['bkr'] ) && is_array( $totals['bkr'] ) ? $totals['bkr'] : array();
		return array_map( 'intval', $bkr );
	}

	/**
	 * Render the link manager.
	 */
	public static function render_page(): void {
		$links  = self::links();
		$clicks = self::clicks();
		$edit   = isset( $_GET['edit'] ) ? self::clean_slug( sanitize_key( wp_unslash( $_GET['edit'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefills the form only.
		$row    = $edit && isset( $links[ $edit ] ) ? $links[ $edit ] : array( 'url' => '', 'label' => '', 'active' => true );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'HowToInvest — Outbound links', 'hti-engine' ); ?></h1>
			<p><?php echo esc_html__( 'Tracked, swappable links under /go/ for use OFF the site — Telegram, social bios, newsletters, campaigns. Clicks are counted server-side per slug and per channel, and the destination can be changed at any time without touching anything you already published.', 'hti-engine' ); ?></p>
			<p><strong><?php echo esc_html__( 'Disclosure still applies.', 'hti-engine' ); ?></strong>
				<?php echo esc_html__( 'A link with a commercial relationship must say so where it is posted (the Telegram message, the bio, the email) — the redirector does not carry that duty for you.', 'hti-engine' ); ?></p>

			<h2><?php echo $edit ? esc_html__( 'Edit link', 'hti-engine' ) : esc_html__( 'New link', 'hti-engine' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hti_go_link_save" />
				<?php wp_nonce_field( 'hti_go_link_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hti-go-slug"><?php echo esc_html__( 'Slug', 'hti-engine' ); ?></label></th>
						<td>
							<code><?php echo esc_html( trailingslashit( home_url( '/go' ) ) ); ?></code>
							<input name="slug" id="hti-go-slug" type="text" class="regular-text" value="<?php echo esc_attr( $edit ); ?>"
								pattern="[a-z0-9\-]+" required <?php echo $edit ? 'readonly' : ''; ?> />
							<p class="description"><?php echo esc_html__( 'Lowercase letters, digits and hyphens (e.g. "xm"). The slug is permanent — to rename, create a new link and delete the old one.', 'hti-engine' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-go-url"><?php echo esc_html__( 'Destination', 'hti-engine' ); ?></label></th>
						<td>
							<input name="url" id="hti-go-url" type="url" class="large-text code" value="<?php echo esc_attr( (string) $row['url'] ); ?>" placeholder="https://…" required />
							<p class="description"><?php echo esc_html__( 'HTTPS only. Paste the affiliate URL here — it never appears in public HTML.', 'hti-engine' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-go-label"><?php echo esc_html__( 'Label', 'hti-engine' ); ?></label></th>
						<td><input name="label" id="hti-go-label" type="text" class="regular-text" value="<?php echo esc_attr( (string) $row['label'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Only for this screen — e.g. "XM — Telegram campaign".', 'hti-engine' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Active', 'hti-engine' ); ?></th>
						<td><label><input name="active" type="checkbox" value="1" <?php checked( ! empty( $row['active'] ) ); ?> />
							<?php echo esc_html__( 'Redirect visitors (uncheck to park the link — it then returns 404)', 'hti-engine' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( $edit ? __( 'Save link', 'hti-engine' ) : __( 'Create link', 'hti-engine' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Your links', 'hti-engine' ); ?></h2>
			<?php if ( ! $links ) : ?>
				<p><?php echo esc_html__( 'No managed links yet.', 'hti-engine' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Link', 'hti-engine' ); ?></th>
							<th><?php echo esc_html__( 'Destination', 'hti-engine' ); ?></th>
							<th><?php echo esc_html__( 'Label', 'hti-engine' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'hti-engine' ); ?></th>
							<th style="text-align:right"><?php echo esc_html__( 'Clicks (30d)', 'hti-engine' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $links as $slug => $link ) : ?>
							<tr>
								<td><code><?php echo esc_html( Broker_Go::url( $slug ) ); ?></code></td>
								<td style="word-break:break-all"><?php echo esc_html( $link['url'] ); ?></td>
								<td><?php echo esc_html( $link['label'] ); ?></td>
								<td><?php echo $link['active'] ? esc_html__( 'Active', 'hti-engine' ) : esc_html__( 'Parked', 'hti-engine' ); ?></td>
								<td style="text-align:right;font-variant-numeric:tabular-nums"><?php echo esc_html( number_format_i18n( (int) ( $clicks[ $slug ] ?? 0 ) ) ); ?></td>
								<td style="white-space:nowrap">
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'hti-go-links', 'edit' => $slug ), admin_url( 'tools.php' ) ) ); ?>"><?php echo esc_html__( 'Edit', 'hti-engine' ); ?></a>
									&nbsp;
									<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'hti_go_link_delete', 'slug' => $slug ), admin_url( 'admin-post.php' ) ), 'hti_go_link_delete_' . $slug ) ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'Delete this link? Anywhere it was posted will stop working.', 'hti-engine' ) ); ?>');"><?php echo esc_html__( 'Delete', 'hti-engine' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Tracking the channel', 'hti-engine' ); ?></h2>
			<p><?php echo esc_html__( 'Add ?loc= to tell channels apart in the HTI Funnel — for example:', 'hti-engine' ); ?></p>
			<p><code><?php echo esc_html( Broker_Go::url( 'xm', 'telegram' ) ); ?></code></p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: comma-separated list of allowed loc values. */
					esc_html__( 'Accepted values: %s. Anything else is counted without a channel.', 'hti-engine' ),
					esc_html( implode( ', ', Broker_Go::LOCATIONS ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Create/update a link.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hti-engine' ) );
		}
		check_admin_referer( 'hti_go_link_save' );

		$slug  = self::clean_slug( isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '' );
		$url   = self::clean_url( isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '' );
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$live  = ! empty( $_POST['active'] );

		$status = 'saved';
		if ( '' === $slug || '' === $url ) {
			$status = 'invalid';
		} elseif ( self::slug_taken_by_broker( $slug ) ) {
			// The broker section owns this slug; shadowing it here would bypass
			// the editorial section's disclosure and CFD rules.
			$status = 'broker';
		} else {
			$links = self::upsert( self::links(), $slug, $url, $label, $live );
			if ( ! isset( $links[ $slug ] ) ) {
				$status = 'full';
			} else {
				self::put( $links );
			}
		}

		set_transient( 'hti_go_link_notice', $status, 60 );
		wp_safe_redirect( add_query_arg( 'page', 'hti-go-links', admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Delete a link.
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hti-engine' ) );
		}
		$slug = self::clean_slug( isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '' );
		check_admin_referer( 'hti_go_link_delete_' . $slug );

		self::put( self::remove( self::links(), $slug ) );

		set_transient( 'hti_go_link_notice', 'deleted', 60 );
		wp_safe_redirect( add_query_arg( 'page', 'hti-go-links', admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Result notice after a save/delete.
	 */
	public static function admin_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_hti-go-links' !== $screen->id ) {
			return;
		}
		$status = get_transient( 'hti_go_link_notice' );
		if ( ! is_string( $status ) || '' === $status ) {
			return;
		}
		delete_transient( 'hti_go_link_notice' );

		$map = array(
			'saved'   => array( 'success', __( 'Link saved.', 'hti-engine' ) ),
			'deleted' => array( 'success', __( 'Link deleted.', 'hti-engine' ) ),
			'invalid' => array( 'error', __( 'Nothing saved: a slug and an https:// destination are both required.', 'hti-engine' ) ),
			'broker'  => array( 'error', __( 'That slug belongs to a broker in the editorial section — its /go/ link is managed on the broker itself.', 'hti-engine' ) ),
			'full'    => array( 'error', __( 'Link limit reached — delete an unused link first.', 'hti-engine' ) ),
		);
		if ( ! isset( $map[ $status ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $map[ $status ][0] ),
			esc_html( $map[ $status ][1] )
		);
	}
}
