<?php
/**
 * Plugin Name:       HowToInvest Social Generator
 * Description:       Brand-faithful social media card generator (Instagram, Facebook, X, Stories, og:image). Edit the design templates and export PNG, or generate auto-filled cards from a News/Glossary post. Educational content only — disclaimers and asset-class language are baked in.
 * Version:           0.9.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            HowToInvest
 * Text Domain:       hti-social
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.9.2';

define( 'HTI_SOCIAL_FILE', __FILE__ );
define( 'HTI_SOCIAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'HTI_SOCIAL_URL', plugin_dir_url( __FILE__ ) );

require_once HTI_SOCIAL_DIR . 'includes/class-brand.php';
require_once HTI_SOCIAL_DIR . 'includes/class-templates.php';
require_once HTI_SOCIAL_DIR . 'includes/class-assets.php';
require_once HTI_SOCIAL_DIR . 'includes/class-logger.php';
require_once HTI_SOCIAL_DIR . 'includes/class-gemini.php';
require_once HTI_SOCIAL_DIR . 'includes/class-ffmpeg-cache.php';
require_once HTI_SOCIAL_DIR . 'includes/class-rest.php';
require_once HTI_SOCIAL_DIR . 'includes/class-admin.php';
require_once HTI_SOCIAL_DIR . 'includes/class-metabox.php';
require_once HTI_SOCIAL_DIR . 'includes/class-reels.php';
require_once HTI_SOCIAL_DIR . 'includes/class-logs-page.php';
require_once HTI_SOCIAL_DIR . 'includes/class-plugin.php';

Plugin::init();
