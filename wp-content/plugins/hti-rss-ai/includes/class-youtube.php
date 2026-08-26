<?php
/**
 * YouTube Data API v3 client: resolve a channel (id / @handle / URL) and list
 * its recent uploads. Server-side; the key never reaches the browser.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Thin YouTube Data API wrapper.
 */
class YouTube {

	private const API = 'https://www.googleapis.com/youtube/v3/';

	/**
	 * Whether an API key is available.
	 */
	public static function is_configured(): bool {
		return '' !== Settings::youtube_api_key();
	}

	/**
	 * Canonical watch URL for a video id.
	 *
	 * @param string $video_id Video id.
	 */
	public static function video_url( string $video_id ): string {
		return 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id );
	}

	/**
	 * Resolve any channel reference to its channel id + title.
	 *
	 * @param string $input Channel id (UC…), @handle, or a channel URL.
	 * @return array{channel_id:string,title:string}|\WP_Error
	 */
	public static function resolve_channel( string $input ) {
		$input = trim( $input );
		if ( '' === $input ) {
			return new \WP_Error( 'rssai_yt_empty', __( 'Enter a channel id, @handle or URL.', 'hti-rss-ai' ) );
		}

		// Bare or URL-embedded channel id.
		if ( preg_match( '~(UC[0-9A-Za-z_-]{20,})~', $input, $m ) ) {
			return self::channel_by( 'id', $m[1] );
		}
		// @handle (bare or in a URL).
		if ( preg_match( '~@([0-9A-Za-z._-]+)~', $input, $m ) ) {
			return self::channel_by( 'forHandle', '@' . $m[1] );
		}
		// /user/NAME (legacy).
		if ( preg_match( '~/user/([0-9A-Za-z._-]+)~', $input, $m ) ) {
			return self::channel_by( 'forUsername', $m[1] );
		}
		// /c/NAME or a plain name → search for the channel.
		$query = $input;
		if ( preg_match( '~/c/([0-9A-Za-z._-]+)~', $input, $m ) ) {
			$query = $m[1];
		}
		return self::search_channel( $query );
	}

	/**
	 * Look up a channel by a direct parameter (id|forHandle|forUsername).
	 *
	 * @param string $param Parameter name.
	 * @param string $value Parameter value.
	 * @return array{channel_id:string,title:string}|\WP_Error
	 */
	private static function channel_by( string $param, string $value ) {
		$res = self::api(
			'channels',
			array(
				'part'  => 'snippet',
				$param  => $value,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$item = $res['items'][0] ?? null;
		if ( ! $item || empty( $item['id'] ) ) {
			return new \WP_Error( 'rssai_yt_notfound', __( 'Channel not found.', 'hti-rss-ai' ) );
		}
		return array(
			'channel_id' => (string) $item['id'],
			'title'      => (string) ( $item['snippet']['title'] ?? '' ),
		);
	}

	/**
	 * Search for a channel by free text and return the first match.
	 *
	 * @param string $query Search text.
	 * @return array{channel_id:string,title:string}|\WP_Error
	 */
	private static function search_channel( string $query ) {
		$res = self::api(
			'search',
			array(
				'part'       => 'snippet',
				'type'       => 'channel',
				'maxResults' => 1,
				'q'          => $query,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$item = $res['items'][0] ?? null;
		$id   = $item['snippet']['channelId'] ?? ( $item['id']['channelId'] ?? '' );
		if ( ! $id ) {
			return new \WP_Error( 'rssai_yt_notfound', __( 'No channel matched that search.', 'hti-rss-ai' ) );
		}
		return array(
			'channel_id' => (string) $id,
			'title'      => (string) ( $item['snippet']['title'] ?? $query ),
		);
	}

	/**
	 * Recent uploads for a channel. Uses the uploads-playlist convention
	 * (UC… → UU…) to avoid an extra channels.list call.
	 *
	 * @param string $channel_id Channel id (UC…).
	 * @param int    $max        Max videos.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public static function recent_uploads( string $channel_id, int $max = 15 ) {
		if ( ! preg_match( '~^UC[0-9A-Za-z_-]{20,}$~', $channel_id ) ) {
			return new \WP_Error( 'rssai_yt_badid', __( 'Invalid channel id.', 'hti-rss-ai' ) );
		}
		$uploads = 'UU' . substr( $channel_id, 2 );
		$res     = self::api(
			'playlistItems',
			array(
				'part'       => 'snippet,contentDetails',
				'playlistId' => $uploads,
				'maxResults' => max( 1, min( 50, $max ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$videos = array();
		foreach ( (array) ( $res['items'] ?? array() ) as $item ) {
			$snip = $item['snippet'] ?? array();
			$vid  = $item['contentDetails']['videoId'] ?? ( $snip['resourceId']['videoId'] ?? '' );
			if ( ! $vid ) {
				continue;
			}
			$thumb = '';
			foreach ( array( 'maxres', 'standard', 'high', 'medium', 'default' ) as $size ) {
				if ( ! empty( $snip['thumbnails'][ $size ]['url'] ) ) {
					$thumb = (string) $snip['thumbnails'][ $size ]['url'];
					break;
				}
			}
			$videos[] = array(
				'video_id'     => (string) $vid,
				'title'        => (string) ( $snip['title'] ?? '' ),
				'description'  => (string) ( $snip['description'] ?? '' ),
				'published_at' => (string) ( $item['contentDetails']['videoPublishedAt'] ?? $snip['publishedAt'] ?? '' ),
				'thumbnail'    => $thumb,
				'channel'      => (string) ( $snip['channelTitle'] ?? '' ),
				'url'          => self::video_url( (string) $vid ),
			);
		}
		return $videos;
	}

	/**
	 * GET a YouTube API endpoint with the key appended.
	 *
	 * @param string              $endpoint Endpoint (e.g. "channels").
	 * @param array<string,mixed> $params   Query params.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function api( string $endpoint, array $params ) {
		$key = Settings::youtube_api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'rssai_yt_no_key', __( 'YouTube Data API key is not configured.', 'hti-rss-ai' ) );
		}
		$params['key'] = $key;
		$url           = self::API . $endpoint . '?' . http_build_query( $params );

		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) {
			Logger::log( 'youtube', 'API error (' . $endpoint . '): ' . $res->get_error_message() );
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
			Logger::log( 'youtube', 'HTTP ' . $code . ' (' . $endpoint . '): ' . $msg );
			return new \WP_Error( 'rssai_yt_http', (string) $msg );
		}
		return is_array( $body ) ? $body : array();
	}
}
