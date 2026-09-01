<?php
/**
 * Tests for Model_Catalog: the ListModels response is read and bucketed by
 * capability, malformed input is survived, and the retired-name table is
 * consistent with its replacements.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-model-catalog.php';

use HTI\RssAI\Model_Catalog;

/* ---------------------------------------------------------------- parse() */

$response = array(
	'models' => array(
		array(
			'name'                       => 'models/gemini-2.5-flash',
			'displayName'                => 'Gemini 2.5 Flash',
			'supportedGenerationMethods' => array( 'generateContent', 'countTokens' ),
		),
		array(
			'name'                       => 'models/gemini-2.5-flash-image',
			'displayName'                => 'Gemini 2.5 Flash Image',
			'supportedGenerationMethods' => array( 'generateContent' ),
		),
		array(
			'name'                       => 'models/imagen-4.0-generate-001',
			'displayName'                => 'Imagen 4',
			'supportedGenerationMethods' => array( 'predict' ),
		),
		array(
			'name'                       => 'models/gemini-embedding-001',
			'displayName'                => 'Gemini Embedding',
			'supportedGenerationMethods' => array( 'embedContent' ),
		),
	),
);

$models = Model_Catalog::parse( $response );
rssai_ok( 4 === count( $models ), 'all four models parsed' );
rssai_ok( 'gemini-2.5-flash' === $models[0]['id'], 'the models/ prefix is stripped' );
rssai_ok( 'Gemini 2.5 Flash' === $models[0]['label'], 'display name kept' );
rssai_ok( array( 'generateContent', 'countTokens' ) === $models[0]['methods'], 'methods kept' );

// An id with no display name falls back to the id.
$no_label = Model_Catalog::parse( array( 'models' => array( array( 'name' => 'models/x' ) ) ) );
rssai_ok( 'x' === $no_label[0]['label'], 'label falls back to the id' );
rssai_ok( array() === $no_label[0]['methods'], 'no methods → empty list' );

/* ------------------------------------------------- malformed / empty input */

rssai_ok( array() === Model_Catalog::parse( array() ), 'empty response → empty list' );
rssai_ok( array() === Model_Catalog::parse( array( 'models' => array() ) ), 'no models → empty list' );
rssai_ok( array() === Model_Catalog::parse( array( 'models' => 'nonsense' ) ), 'models not a list → empty' );
rssai_ok( array() === Model_Catalog::parse( array( 'models' => array( 'a string', 7 ) ) ), 'non-array rows skipped' );
rssai_ok( array() === Model_Catalog::parse( array( 'models' => array( array( 'name' => '' ) ) ) ), 'nameless model skipped' );
$mixed = Model_Catalog::parse(
	array(
		'models' => array(
			array( 'name' => 'models/good', 'supportedGenerationMethods' => array( 'generateContent', 5, '' ) ),
		),
	)
);
rssai_ok( array( 'generateContent' ) === $mixed[0]['methods'], 'non-string methods dropped' );

/* ---------------------------------------------------------------- group() */

$grouped = Model_Catalog::group( $models );
rssai_ok( array( 'gemini-2.5-flash-image' ) === array_column( $grouped['image_generate'], 'id' ), 'gemini image → :generateContent bucket' );
rssai_ok( array( 'imagen-4.0-generate-001' ) === array_column( $grouped['image_predict'], 'id' ), 'imagen → :predict bucket' );
rssai_ok( array( 'gemini-embedding-001' ) === array_column( $grouped['embed'], 'id' ), 'embedding model → embed bucket' );
rssai_ok( array( 'gemini-2.5-flash' ) === array_column( $grouped['text'], 'id' ), 'plain text model → text bucket' );

// Every bucket exists even when nothing lands in it, so the panel can render.
$empty = Model_Catalog::group( array() );
foreach ( Model_Catalog::BUCKETS as $bucket ) {
	rssai_ok( array_key_exists( $bucket, $empty ), "bucket $bucket always present: $bucket" );
	rssai_ok( array() === $empty[ $bucket ], "bucket $bucket empty: $bucket" );
}

// batchEmbedContents also counts as an embedding model.
$batch = Model_Catalog::group(
	array( array( 'id' => 'some-embedder', 'label' => 'x', 'methods' => array( 'batchEmbedContents' ) ) )
);
rssai_ok( 1 === count( $batch['embed'] ), 'batchEmbedContents recognised' );

// A model that supports nothing we use lands nowhere, rather than in "text".
$useless = Model_Catalog::group(
    array( array( 'id' => 'aqa', 'label' => 'x', 'methods' => array( 'generateAnswer' ) ) )
);
foreach ( Model_Catalog::BUCKETS as $bucket ) {
	rssai_ok( array() === $useless[ $bucket ], "unusable model not bucketed ($bucket): $bucket" );
}

// An imagen model that only does generateContent must not vanish.
$odd_imagen = Model_Catalog::group(
	array( array( 'id' => 'imagen-next', 'label' => 'x', 'methods' => array( 'generateContent' ) ) )
);
rssai_ok( 1 === count( $odd_imagen['image_generate'] ), 'imagen on generateContent still an image model' );

/* --------------------------------------------------- retired / replacement */

$retired      = Model_Catalog::retired();
$replacements = Model_Catalog::replacements();
foreach ( array_keys( $retired ) as $setting ) {
	rssai_ok( isset( $replacements[ $setting ] ), "every retired list has a replacement: $setting" );
}
foreach ( $retired as $setting => $names ) {
	foreach ( $names as $name ) {
		rssai_ok( Model_Catalog::is_retired( $setting, $name ), "$name flagged retired: $name" );
		// A replacement must never itself be on the retired list.
		rssai_ok( $replacements[ $setting ] !== $name, "replacement for $setting is not retired: $setting/$name" );
	}
}

// The two names found dead in production.
rssai_ok( Model_Catalog::is_retired( 'image_model', 'imagen-4.0-generate-001' ), 'the configured image model is flagged' );
rssai_ok( Model_Catalog::is_retired( 'embedding_model', 'text-embedding-001' ), 'the configured embedding model is flagged' );
rssai_ok( Model_Catalog::is_retired( 'embedding_model', 'text-embedding-004' ), 'the old code default is flagged too' );

// Live names, blanks and unknown settings are left alone.
rssai_ok( ! Model_Catalog::is_retired( 'image_model', 'gemini-2.5-flash-image' ), 'a live name is not flagged' );
rssai_ok( ! Model_Catalog::is_retired( 'embedding_model', 'gemini-embedding-001' ), 'the live embedder is not flagged' );
rssai_ok( ! Model_Catalog::is_retired( 'image_model', '' ), 'blank is not flagged' );
rssai_ok( ! Model_Catalog::is_retired( 'nonexistent_setting', 'text-embedding-004' ), 'unknown setting is not flagged' );
rssai_ok( Model_Catalog::is_retired( 'image_model', '  IMAGEN-4.0-GENERATE-001  ' ), 'matching ignores case and padding' );

rssai_done( 'model-catalog' );
