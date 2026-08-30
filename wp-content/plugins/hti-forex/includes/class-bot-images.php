<?php
/**
 * The bot's images, and the file_id cache that stops us re-uploading them.
 *
 * Telegram will fetch a photo from a public URL the first time, and hands back
 * a `file_id` that identifies the copy it now stores. Sending that id instead
 * of the URL on every subsequent message means our server is never asked for
 * the file again — which matters, because the alternative is Telegram pulling
 * a 250 KB PNG off a shared cPanel host once per person per message.
 *
 * The cache is fingerprinted by the file's mtime and size rather than its
 * contents: one stat() per send instead of hashing 250 KB, and replacing an
 * image on the server invalidates the id by itself. Nobody has to remember to
 * clear anything.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Image registry and file_id cache.
 */
class Bot_Images {

	/**
	 * Where the file_ids live: slug => { id, fingerprint }.
	 */
	private const OPTION = 'hti_forex_bot_images';

	/**
	 * The images the bot can send, and the file each one is.
	 *
	 * Closed set, fixed in code — a slug never comes from user input.
	 *
	 * @return array<string,string>
	 */
	public static function files(): array {
		return array(
			'start' => 'bot-start.png',
			'pip'   => 'bot-pip.png',
			'promo' => 'bot-promo.png',
		);
	}

	/**
	 * Absolute path of an image, or '' for an unknown slug.
	 *
	 * @param string $slug Image slug.
	 * @return string
	 */
	public static function path( string $slug ): string {
		$file = self::files()[ $slug ] ?? '';
		return '' === $file ? '' : HTI_FOREX_PATH . 'assets/brand/' . $file;
	}

	/**
	 * Public URL of an image, or '' for an unknown slug.
	 *
	 * @param string $slug Image slug.
	 * @return string
	 */
	public static function url( string $slug ): string {
		$file = self::files()[ $slug ] ?? '';
		return '' === $file ? '' : HTI_FOREX_URL . 'assets/brand/' . $file;
	}

	/**
	 * Whether the file is actually on disk. A deploy that dropped an asset
	 * should degrade to a text message, not to a broken send.
	 *
	 * @param string $slug Image slug.
	 * @return bool
	 */
	public static function exists( string $slug ): bool {
		$path = self::path( $slug );
		return '' !== $path && is_readable( $path );
	}

	/**
	 * What identifies this version of the file. Cheap by design.
	 *
	 * @param string $slug Image slug.
	 * @return string
	 */
	public static function fingerprint( string $slug ): string {
		$path = self::path( $slug );
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		return (string) filemtime( $path ) . ':' . (string) filesize( $path );
	}

	/**
	 * What to hand Telegram as the `photo` argument: the cached file_id when
	 * we have one for this exact version of the file, otherwise the URL, which
	 * makes Telegram fetch it once.
	 *
	 * @param string $slug Image slug.
	 * @return string Empty when there is no usable image.
	 */
	public static function photo( string $slug ): string {
		if ( ! self::exists( $slug ) ) {
			return '';
		}

		$cache = get_option( self::OPTION, array() );
		$cache = is_array( $cache ) ? $cache : array();
		$entry = $cache[ $slug ] ?? array();

		if (
			is_array( $entry )
			&& ! empty( $entry['id'] )
			&& ( $entry['fingerprint'] ?? '' ) === self::fingerprint( $slug )
		) {
			return (string) $entry['id'];
		}

		return self::url( $slug );
	}

	/**
	 * Whether what photo() handed back is a cached id rather than a URL.
	 *
	 * The distinction decides how the picture can be sent. A file_id costs
	 * Telegram a lookup and can ride back inside the webhook response; a URL
	 * makes Telegram come to this server for the file, and the response to a
	 * webhook carries no answer to read — so the id would never be learned and
	 * every recipient would cost another fetch.
	 *
	 * A Telegram file_id is opaque but never a URL, which is the whole test.
	 *
	 * @param string $slug  Image slug.
	 * @param string $value What photo() returned for it.
	 */
	public static function is_file_id( string $slug, string $value ): bool {
		return '' !== $value && ! str_starts_with( strtolower( $value ), 'http' );
	}

	/**
	 * Store the file_id Telegram returned for a slug.
	 *
	 * @param string $slug    Image slug.
	 * @param string $file_id Telegram file id.
	 */
	public static function remember( string $slug, string $file_id ): void {
		if ( '' === $file_id || ! isset( self::files()[ $slug ] ) ) {
			return;
		}

		$cache = get_option( self::OPTION, array() );
		$cache = is_array( $cache ) ? $cache : array();

		$cache[ $slug ] = array(
			'id'          => $file_id,
			'fingerprint' => self::fingerprint( $slug ),
		);

		update_option( self::OPTION, $cache, false );
	}

	/**
	 * Pull the biggest file_id out of a sendPhoto result. Telegram returns one
	 * entry per size it generated; the last is the largest, and the largest is
	 * the one worth reusing.
	 *
	 * @param mixed $result Decoded `result` from sendPhoto.
	 * @return string
	 */
	public static function file_id_from( $result ): string {
		if ( ! is_array( $result ) || ! isset( $result['photo'] ) || ! is_array( $result['photo'] ) ) {
			return '';
		}

		$last = end( $result['photo'] );

		return is_array( $last ) && isset( $last['file_id'] ) ? (string) $last['file_id'] : '';
	}
}
