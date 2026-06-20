<?php
/**
 * Term-deposit comparator (PT): `[hti_depositos]`.
 *
 * Editorial, factual comparison of publicly-advertised term-deposit offers.
 * Data is maintained by an editor in wp-admin (paste TSV/CSV from a
 * spreadsheet) and stored in an option; the shortcode renders an accessible,
 * indexable card list enhanced by deposits.js (client-side filter/sort).
 *
 * Informational only — not financial advice, not a recommendation; rates and
 * conditions change, confirm with the bank. Capital guaranteed by the deposit
 * guarantee fund up to 100k€ per holder.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode, admin editor and data store for the deposit comparator.
 */
class Deposits {

	private const SHORTCODE = 'hti_depositos';
	private const OPTION     = 'hti_deposits';

	/**
	 * Hook the shortcode, assets and admin editor.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_hti_deposits_save', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * The stored dataset (meta + rows), seeded with a small sample the first time.
	 *
	 * @return array{title:string,version:string,intro:string,updated:string,rows:array<int,array<string,mixed>>}
	 */
	public static function data(): array {
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && ! empty( $stored['rows'] ) ) {
			return wp_parse_args( $stored, self::defaults() );
		}
		return self::defaults();
	}

	/**
	 * Default meta + a small, hand-checked sample of rows (demo until the
	 * editor pastes the full, verified table).
	 */
	private static function defaults(): array {
		return array(
			'title'   => 'Comparador de depósitos a prazo',
			'version' => 'Junho 2026 · versão 2',
			'updated' => '',
			'intro'   => 'Produtos com capital garantido (até 100 000€ por titular pelo Fundo de Garantia de Depósitos) que remuneram a uma taxa fixa durante um prazo. Sem reforços; por vezes permitem levantamentos antecipados (mobilização antecipada) com penalização de juros. A coluna IRS assinala os bancos estrangeiros, que requerem declaração da conta e dos juros recebidos.',
			'rows'    => self::sample_rows(),
		);
	}

	/**
	 * A dozen high-confidence rows as a working sample.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function sample_rows(): array {
		$r = static function ( $rate, $term, $bank, $product, $min, $max, $nc, $nm, $mobil, $irs, $notes ) {
			return compact( 'rate', 'term', 'bank', 'product', 'min', 'max', 'nc', 'nm', 'mobil', 'irs', 'notes' );
		};
		return array(
			$r( 3.50, 12, 'BluOr Bank', 'LĪGO Deposit', 1000, null, false, false, '', true, 'Disponível durante o mês de junho. Subscrever depósito sem abrir conta à ordem (que tem custos).' ),
			$r( 3.25, 3, 'Best', 'Novos Clientes Aniversário', 500, 75000, true, false, 'sim', false, 'Disponível durante os meses de junho e julho.' ),
			$r( 3.00, 3, 'ActivoBank', 'Novos Clientes', 500, 10000, true, false, 'sim', false, '' ),
			$r( 3.00, 3, 'BiG', 'Super Depósito', 5000, 50000, true, false, 'sim', false, '+0,10% para 18-30 anos, ACP e ordens profissionais.' ),
			$r( 3.00, 6, 'CGD', 'Boas Vindas', 250, 5000, true, false, 'sim', false, 'Novos clientes desde 01/09/2025 ou com conta sem saldo desde 31/12/2024. 4,95€/mês de manutenção, com possibilidade de isenção/redução.' ),
			$r( 3.00, 48, 'Klarna', 'Conta poupança', null, null, false, false, '', true, 'Juros anuais.' ),
			$r( 2.90, 36, 'Klarna', 'Conta poupança', null, null, false, false, '', true, 'Juros anuais.' ),
			$r( 2.85, 24, 'Klarna', 'Conta poupança', null, null, false, false, '', true, 'Juros anuais.' ),
			$r( 2.75, 3, 'Bankinter', 'Net Boas Vindas', 1000, 100000, true, false, 'sim', false, '' ),
			$r( 2.75, 12, 'Haitong Bank', 'Depósito a prazo', null, null, false, false, 'notas', false, '2,70% com mobilização antecipada. +0,05%/+0,10% para subscritores Proteste. 50€/ano de manutenção se < 5 000€.' ),
			$r( 2.70, 24, 'Finantia', 'Visão', 50000, 500000, false, true, 'sim', false, 'Opção de juros semestrais; mobilização antecipada só no fim de cada semestre.' ),
			$r( 2.65, 12, 'Easisave', 'Fixed term deposit', 1000, null, false, false, '', true, 'Juros trimestrais ou na maturidade.' ),
		);
	}

	/* ---------------------------------------------------------------- render */

	/**
	 * Whether the current singular view embeds the comparator.
	 */
	private static function is_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * Enqueue the comparator assets only where the shortcode is present.
	 */
	public static function enqueue(): void {
		if ( ! self::is_page() ) {
			return;
		}
		wp_enqueue_style( 'hti-deposits', HTI_ENGINE_URL . 'assets/css/deposits.css', array(), VERSION );
		wp_enqueue_script( 'hti-deposits', HTI_ENGINE_URL . 'assets/js/deposits.js', array(), VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/**
	 * Render the comparator (shortcode callback).
	 *
	 * @return string Safe HTML.
	 */
	public static function render(): string {
		$data = self::data();
		$rows = $data['rows'];

		// Sort by rate desc, then term asc, for a sensible default order.
		usort(
			$rows,
			static function ( $a, $b ) {
				return ( (float) $b['rate'] <=> (float) $a['rate'] ) ?: ( (int) $a['term'] <=> (int) $b['term'] );
			}
		);

		$banks = array();
		$terms = array();
		foreach ( $rows as $row ) {
			$banks[ $row['bank'] ] = true;
			$terms[ (int) $row['term'] ] = true;
		}
		$banks = array_keys( $banks );
		sort( $banks );
		$terms = array_keys( $terms );
		sort( $terms );

		ob_start();
		?>
		<section class="hti-dep" aria-label="<?php echo esc_attr( $data['title'] ); ?>">
			<header class="hti-dep__head">
				<h2 class="hti-dep__title"><?php echo esc_html( $data['title'] ); ?></h2>
				<?php if ( '' !== $data['version'] ) : ?>
					<p class="hti-dep__meta"><?php echo esc_html( $data['version'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $data['intro'] ) : ?>
					<p class="hti-dep__intro"><?php echo esc_html( $data['intro'] ); ?></p>
				<?php endif; ?>
			</header>

			<form class="hti-dep__filters" role="search" aria-label="Filtros">
				<label class="hti-dep__search">
					<span class="screen-reader-text">Pesquisar banco ou produto</span>
					<input type="search" class="hti-dep__q" placeholder="Pesquisar banco, produto ou nota…" autocomplete="off">
				</label>

				<div class="hti-dep__row">
					<label class="hti-dep__field">
						<span>Prazo</span>
						<select class="hti-dep__term">
							<option value="">Qualquer</option>
							<?php foreach ( $terms as $t ) : ?>
								<option value="<?php echo esc_attr( (string) $t ); ?>"><?php echo esc_html( $t . ( 1 === $t ? ' mês' : ' meses' ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="hti-dep__field">
						<span>Banco</span>
						<select class="hti-dep__bank">
							<option value="">Todos</option>
							<?php foreach ( $banks as $bank ) : ?>
								<option value="<?php echo esc_attr( $bank ); ?>"><?php echo esc_html( $bank ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="hti-dep__field">
						<span>Montante (€)</span>
						<input type="number" inputmode="numeric" min="0" step="500" class="hti-dep__amount" placeholder="ex.: 10000">
					</label>

					<label class="hti-dep__field">
						<span>Ordenar</span>
						<select class="hti-dep__sort">
							<option value="rate">Taxa (maior primeiro)</option>
							<option value="term">Prazo (menor primeiro)</option>
							<option value="min">Montante mínimo</option>
						</select>
					</label>
				</div>

				<div class="hti-dep__toggles">
					<label class="hti-dep__chk"><input type="checkbox" class="hti-dep__nc"> Novos clientes</label>
					<label class="hti-dep__chk"><input type="checkbox" class="hti-dep__mobil"> Mobilização antecipada</label>
					<label class="hti-dep__chk"><input type="checkbox" class="hti-dep__nat"> Excluir bancos estrangeiros (IRS)</label>
					<button type="button" class="hti-dep__reset">Limpar filtros</button>
				</div>

				<p class="hti-dep__count" role="status" aria-live="polite"><?php echo esc_html( (string) count( $rows ) ); ?> ofertas</p>
			</form>

			<ul class="hti-dep__list">
				<?php foreach ( $rows as $row ) : ?>
					<?php echo self::card( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in card(). ?>
				<?php endforeach; ?>
			</ul>

			<p class="hti-dep__empty" hidden>Sem ofertas para estes filtros. <button type="button" class="hti-dep__reset hti-dep__reset--link">Limpar filtros</button></p>

			<div class="hti-dep__disclaimer">
				<p><strong>Informação meramente informativa</strong>, recolhida de fontes públicas e sujeita a alterações sem aviso. Não é uma recomendação nem aconselhamento financeiro — confirma sempre as condições, taxas e custos junto de cada banco antes de subscrever. Capital garantido pelo Fundo de Garantia de Depósitos até 100 000€ por titular e por instituição.</p>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One offer card with filter data attributes.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return string Safe HTML.
	 */
	private static function card( array $row ): string {
		$rate   = (float) $row['rate'];
		$term   = (int) $row['term'];
		$min    = isset( $row['min'] ) && '' !== $row['min'] && null !== $row['min'] ? (int) $row['min'] : null;
		$max    = isset( $row['max'] ) && '' !== $row['max'] && null !== $row['max'] ? (int) $row['max'] : null;
		$mobil  = (string) ( $row['mobil'] ?? '' );
		$nc     = ! empty( $row['nc'] );
		$nm     = ! empty( $row['nm'] );
		$irs    = ! empty( $row['irs'] );
		$bank   = (string) $row['bank'];
		$prod   = (string) $row['product'];
		$notes  = (string) ( $row['notes'] ?? '' );

		$rate_str = number_format_i18n( $rate, 2 ) . '%';
		$term_str = $term . ( 1 === $term ? ' mês' : ' meses' );

		$amount = '';
		if ( null !== $min && null !== $max ) {
			$amount = self::money( $min ) . ' – ' . self::money( $max );
		} elseif ( null !== $min ) {
			$amount = 'A partir de ' . self::money( $min );
		} elseif ( null !== $max ) {
			$amount = 'Até ' . self::money( $max );
		}

		$badges = '';
		if ( $nc ) {
			$badges .= '<span class="hti-dep__badge hti-dep__badge--nc">Novos clientes</span>';
		}
		if ( $nm ) {
			$badges .= '<span class="hti-dep__badge hti-dep__badge--nm">Novos montantes</span>';
		}
		if ( '' !== $mobil ) {
			$badges .= '<span class="hti-dep__badge hti-dep__badge--mobil">Mobilização antecipada</span>';
		}
		if ( $irs ) {
			$badges .= '<span class="hti-dep__badge hti-dep__badge--irs" title="Banco estrangeiro: requer declaração de conta e juros">IRS estrangeiros</span>';
		}

		$search = strtolower( $bank . ' ' . $prod . ' ' . $notes );

		ob_start();
		?>
		<li class="hti-dep__card"
			data-rate="<?php echo esc_attr( (string) $rate ); ?>"
			data-term="<?php echo esc_attr( (string) $term ); ?>"
			data-bank="<?php echo esc_attr( $bank ); ?>"
			data-min="<?php echo esc_attr( null === $min ? '' : (string) $min ); ?>"
			data-max="<?php echo esc_attr( null === $max ? '' : (string) $max ); ?>"
			data-nc="<?php echo $nc ? '1' : '0'; ?>"
			data-mobil="<?php echo '' !== $mobil ? '1' : '0'; ?>"
			data-irs="<?php echo $irs ? '1' : '0'; ?>"
			data-text="<?php echo esc_attr( $search ); ?>">
			<div class="hti-dep__rate"><span class="hti-dep__rate-num"><?php echo esc_html( $rate_str ); ?></span><span class="hti-dep__rate-lbl">TANB</span></div>
			<div class="hti-dep__body">
				<div class="hti-dep__bank"><?php echo esc_html( $bank ); ?> <span class="hti-dep__prod"><?php echo esc_html( $prod ); ?></span></div>
				<div class="hti-dep__facts">
					<span class="hti-dep__term"><?php echo esc_html( $term_str ); ?></span>
					<?php if ( '' !== $amount ) : ?><span class="hti-dep__amt"><?php echo esc_html( $amount ); ?></span><?php endif; ?>
				</div>
				<?php if ( '' !== $badges ) : ?><div class="hti-dep__badges"><?php echo $badges; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></div><?php endif; ?>
				<?php if ( '' !== $notes ) : ?><p class="hti-dep__notes"><?php echo esc_html( $notes ); ?></p><?php endif; ?>
			</div>
		</li>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Format a euro amount (thousands separated, no decimals).
	 *
	 * @param int $n Amount.
	 */
	private static function money( int $n ): string {
		return number_format_i18n( $n ) . '€';
	}

	/* ----------------------------------------------------------------- admin */

	/**
	 * Register the editor under Settings.
	 */
	public static function admin_menu(): void {
		add_options_page(
			__( 'Depósitos (comparador)', 'hti-engine' ),
			__( 'Depósitos', 'hti-engine' ),
			'manage_options',
			'hti-deposits',
			array( __CLASS__, 'render_admin' )
		);
	}

	/**
	 * Render the editor page.
	 */
	public static function render_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$data    = self::data();
		$updated = $data['updated'] ? $data['updated'] : '—';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Comparador de depósitos', 'hti-engine' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %d: row count. */ __( 'Guardado — %d ofertas.', 'hti-engine' ), (int) count( $data['rows'] ) ) ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Cola a tabela a partir do Excel/Google Sheets (com cabeçalho). Colunas reconhecidas:', 'hti-engine' ); ?>
				<code>TANB</code> · <code>Prazo</code> · <code>Banco</code> · <code>Produto</code> · <code>Mínimo</code> · <code>Máximo</code> · <code>Novos clientes</code> · <code>Novos montantes</code> · <code>Mobil antecip</code> · <code>IRS</code> · <code>Notas</code>.
				<?php esc_html_e( 'Marca as colunas booleanas com “X” (ou “ver notas” na mobilização). Última atualização:', 'hti-engine' ); ?> <strong><?php echo esc_html( $updated ); ?></strong>.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hti_deposits_save">
				<?php wp_nonce_field( 'hti_deposits_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hti-dep-title"><?php esc_html_e( 'Título', 'hti-engine' ); ?></label></th>
						<td><input name="title" id="hti-dep-title" type="text" class="regular-text" value="<?php echo esc_attr( $data['title'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-dep-version"><?php esc_html_e( 'Versão / data', 'hti-engine' ); ?></label></th>
						<td><input name="version" id="hti-dep-version" type="text" class="regular-text" value="<?php echo esc_attr( $data['version'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-dep-intro"><?php esc_html_e( 'Introdução', 'hti-engine' ); ?></label></th>
						<td><textarea name="intro" id="hti-dep-intro" rows="3" class="large-text"><?php echo esc_textarea( $data['intro'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-dep-rows"><?php esc_html_e( 'Dados (colar do Excel)', 'hti-engine' ); ?></label></th>
						<td><textarea name="rows" id="hti-dep-rows" rows="18" class="large-text code" placeholder="TANB&#9;Prazo&#9;Banco&#9;Produto&#9;Mínimo&#9;Máximo&#9;Novos clientes&#9;Novos montantes&#9;Mobil antecip&#9;IRS&#9;Notas"><?php echo esc_textarea( self::rows_to_tsv( $data['rows'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Deixar em branco e guardar mantém os dados atuais. Para substituir, cola a tabela completa.', 'hti-engine' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Guardar comparador', 'hti-engine' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the editor form: parse the pasted table into rows.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hti_deposits_save' ) ) {
			wp_die( esc_html__( 'Não autorizado.', 'hti-engine' ) );
		}

		$current = self::data();
		$raw     = isset( $_POST['rows'] ) ? (string) wp_unslash( $_POST['rows'] ) : ''; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- parsed below.
		$rows    = trim( $raw ) !== '' ? self::parse( $raw ) : $current['rows'];

		$data = array(
			'title'   => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : $current['title'],
			'version' => isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : $current['version'],
			'intro'   => isset( $_POST['intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['intro'] ) ) : $current['intro'],
			'updated' => gmdate( 'Y-m-d' ),
			'rows'    => $rows,
		);
		update_option( self::OPTION, $data, false );

		wp_safe_redirect( add_query_arg( array( 'page' => 'hti-deposits', 'saved' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------- parsing */

	/**
	 * Parse a pasted TSV/CSV table (with header) into normalized rows.
	 *
	 * @param string $raw Pasted text.
	 * @return array<int,array<string,mixed>>
	 */
	private static function parse( string $raw ): array {
		$raw   = str_replace( "\r\n", "\n", $raw );
		$lines = array_values( array_filter( explode( "\n", $raw ), static fn( $l ) => '' !== trim( $l ) ) );
		if ( count( $lines ) < 2 ) {
			return array();
		}
		$delim  = ( substr_count( $lines[0], "\t" ) >= substr_count( $lines[0], ',' ) ) ? "\t" : ',';
		$header = array_map( array( __CLASS__, 'norm' ), str_getcsv( array_shift( $lines ), $delim ) );

		$map = array();
		foreach ( $header as $i => $h ) {
			$field = self::field_for( $h );
			if ( '' !== $field ) {
				$map[ $field ] = $i;
			}
		}

		$rows = array();
		foreach ( $lines as $line ) {
			$cells = str_getcsv( $line, $delim );
			$get   = static fn( $f ) => isset( $map[ $f ], $cells[ $map[ $f ] ] ) ? trim( (string) $cells[ $map[ $f ] ] ) : '';

			$rate = self::to_float( $get( 'rate' ) );
			$term = (int) preg_replace( '/\D+/', '', $get( 'term' ) );
			$bank = $get( 'bank' );
			if ( $rate <= 0 || '' === $bank ) {
				continue; // Skip non-data / section rows.
			}

			$rows[] = array(
				'rate'    => $rate,
				'term'    => $term,
				'bank'    => $bank,
				'product' => $get( 'product' ),
				'min'     => self::to_int( $get( 'min' ) ),
				'max'     => self::to_int( $get( 'max' ) ),
				'nc'      => self::truthy( $get( 'nc' ) ),
				'nm'      => self::truthy( $get( 'nm' ) ),
				'mobil'   => self::mobil( $get( 'mobil' ) ),
				'irs'     => self::truthy( $get( 'irs' ) ),
				'notes'   => sanitize_text_field( $get( 'notes' ) ),
			);
		}
		return $rows;
	}

	/**
	 * Normalize a header cell (lowercase, no accents, collapse spaces).
	 *
	 * @param string $s Cell.
	 */
	private static function norm( string $s ): string {
		$s = strtolower( trim( $s ) );
		$s = strtr( $s, array( 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c' ) );
		return preg_replace( '/\s+/', ' ', $s );
	}

	/**
	 * Map a normalized header to a row field.
	 *
	 * @param string $h Normalized header.
	 */
	private static function field_for( string $h ): string {
		$rules = array(
			'rate'    => array( 'tanb', 'taxa' ),
			'term'    => array( 'prazo' ),
			'bank'    => array( 'banco' ),
			'product' => array( 'produto' ),
			'min'     => array( 'minimo', 'min' ),
			'max'     => array( 'maximo', 'max' ),
			'nc'      => array( 'novos clientes', 'novosclientes' ),
			'nm'      => array( 'novos montantes', 'novosmontantes', 'novos montan' ),
			'mobil'   => array( 'mobil', 'mobilizacao' ),
			'irs'     => array( 'irs' ),
			'notes'   => array( 'notas', 'nota' ),
		);
		foreach ( $rules as $field => $needles ) {
			foreach ( $needles as $n ) {
				if ( str_contains( $h, $n ) ) {
					return $field;
				}
			}
		}
		return '';
	}

	/**
	 * Parse a percentage/number like "3,50%" or "3.5" into a float.
	 *
	 * @param string $s Value.
	 */
	private static function to_float( string $s ): float {
		$s = str_replace( array( '%', ' ' ), '', $s );
		$s = str_replace( ',', '.', $s );
		return is_numeric( $s ) ? (float) $s : 0.0;
	}

	/**
	 * Parse an amount like "1 000" / "150.000" into an int (null if empty).
	 *
	 * @param string $s Value.
	 * @return int|null
	 */
	private static function to_int( string $s ): ?int {
		$digits = preg_replace( '/\D+/', '', $s );
		return '' === $digits ? null : (int) $digits;
	}

	/**
	 * Whether a cell marks a boolean column (X / sim / ✓ / true).
	 *
	 * @param string $s Cell.
	 */
	private static function truthy( string $s ): bool {
		$s = strtolower( trim( $s ) );
		return in_array( $s, array( 'x', 'sim', 's', '✓', 'true', '1', 'yes' ), true );
	}

	/**
	 * Normalize the early-withdrawal cell to '', 'sim' or 'notas'.
	 *
	 * @param string $s Cell.
	 */
	private static function mobil( string $s ): string {
		$s = self::norm( $s );
		if ( str_contains( $s, 'nota' ) ) {
			return 'notas';
		}
		return self::truthy( $s ) ? 'sim' : '';
	}

	/**
	 * Serialize rows back to TSV for the editor textarea (round-trip).
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 */
	private static function rows_to_tsv( array $rows ): string {
		$out = array( "TANB\tPrazo\tBanco\tProduto\tMínimo\tMáximo\tNovos clientes\tNovos montantes\tMobil antecip\tIRS\tNotas" );
		foreach ( $rows as $row ) {
			$out[] = implode(
				"\t",
				array(
					number_format( (float) $row['rate'], 2, ',', '' ) . '%',
					(string) (int) $row['term'],
					(string) $row['bank'],
					(string) $row['product'],
					null === $row['min'] ? '' : (string) (int) $row['min'],
					null === $row['max'] ? '' : (string) (int) $row['max'],
					! empty( $row['nc'] ) ? 'X' : '',
					! empty( $row['nm'] ) ? 'X' : '',
					'notas' === $row['mobil'] ? 'ver notas' : ( 'sim' === $row['mobil'] ? 'X' : '' ),
					! empty( $row['irs'] ) ? 'X' : '',
					(string) ( $row['notes'] ?? '' ),
				)
			);
		}
		return implode( "\n", $out );
	}
}
