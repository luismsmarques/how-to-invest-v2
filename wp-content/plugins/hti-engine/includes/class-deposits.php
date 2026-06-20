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
		add_action( 'wp_head', array( __CLASS__, 'schema' ) );
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
			'title'   => 'Comparador de Depósitos a Prazo',
			'version' => 'Junho 2026 · versão 2',
			'updated' => '',
			'aforro'  => 2.215,
			'amount'  => 10000,
			'intro'   => 'Compara a TANB, prazos e condições de depósitos a prazo em Portugal. Define o teu montante e vê o juro estimado, lado a lado. Produtos com capital garantido (até 100 000€ por titular pelo Fundo de Garantia de Depósitos) que remuneram a uma taxa fixa durante um prazo.',
			'footer'  => "Muitos destes bancos oferecem transferências online gratuitas (Banco CTT, BAI, Bankinter, Best, BIL, Bison, BPG, Carregosa, Easisave, Klarna, MeDirect, Openbank), sujeitas a um limite diário entre 5 000€ e 25 000€. Outros cobram 0,50€ (BluOr, Finantia), 1,00€ (BiG, EuroBic) ou 1,50€ (Atlántico, BNI, Haitong).\n\nProdutos como o Bankinter Conta Mais Ordenado, o capital não investido na Trade Republic ou a Poupança de Acesso Imediato da Revolut não são depósitos a prazo (permitem reforços e levantamentos sem penalização), pelo que não constam desta tabela.\n\nPara comparação, as novas subscrições de certificados de aforro têm taxa inicial de 2,215%.",
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
			$r( 3.5, 12, 'BluOr Bank', 'LIGO Deposit', 1000, null, false, false, '', false, 'Disponível durante o mês de junho. Subscrever depósito sem abrir conta (que tem custos). Pedir redução da taxa de retenção' ),
			$r( 3.25, 3, 'Best', 'Novos Clientes Aniversário', 500, 75000, true, false, 'sim', false, 'Disponível durante os meses de junho e julho' ),
			$r( 3.0, 3, 'ActivoBank', 'Novos Clientes', 500, 10000, true, false, 'sim', false, '' ),
			$r( 3.0, 3, 'BiG', 'Super Depósito', 5000, 50000, true, false, 'sim', false, '+0,10% para 18-30 anos, ACP, ordens profissionais' ),
			$r( 3.0, 6, 'CGD', 'Boas Vindas', 250, 5000, true, false, 'sim', false, 'Novos clientes desde 01/09/2025 ou conta sem saldo desde 31/12/2024. 4,95€/mês manutenção, com possibilidades de isenção ou redução por idade, domiciliação, etc' ),
			$r( 3.0, 48, 'Klarna', 'Conta poupança', null, null, false, false, '', true, 'Juros anuais' ),
			$r( 2.9, 36, 'Klarna', 'Conta poupança', null, null, false, false, '', true, 'Juros anuais' ),
			$r( 2.85, 24, 'Klarna', 'Conta poupança', null, null, false, false, '', true, 'Juros anuais' ),
			$r( 2.75, 2, 'Bankinter', 'TOP Premier', 50000, 500000, false, true, 'sim', false, '' ),
			$r( 2.75, 2, 'Bankinter', 'Net Boas Vindas', 1000, 100000, true, false, 'sim', false, '' ),
			$r( 2.75, 3, 'BPG', 'Start', 2500, 100000, false, false, '', false, 'Juros no início. Limitado a uma subscrição' ),
			$r( 2.75, 12, 'Haitong Bank', 'Depósito a prazo', null, null, false, false, 'notas', false, '2,70% mobilizável. +0,05% ou +0,10% para subscritores Proteste. 50€/ano manutenção se < 5 000€' ),
			$r( 2.75, 24, 'Haitong Bank', 'Depósito a prazo', null, null, false, false, 'notas', false, '2,70% mobilizável. 50€/ano manutenção se < 5 000€' ),
			$r( 2.71, 12, 'Klarna', 'Conta poupança', null, null, false, false, '', true, '' ),
			$r( 2.7, 6, 'Haitong Bank', 'Depósito a prazo', null, null, false, false, 'notas', false, '2,65% mobilizável. +0,05% ou +0,10% para subscritores Proteste. 50€/ano manutenção se < 5 000€' ),
			$r( 2.7, 24, 'Finantia', 'Visão', 50000, 500000, false, true, 'sim', false, 'Opção de juros semestrais, mobilizável só no final de cada semestre' ),
			$r( 2.65, 3, 'Haitong Bank', 'Depósito a prazo', null, null, false, false, 'notas', false, '2,60% mobilizável. +0,05% ou +0,10% para subscritores Proteste. 50€/ano manutenção se < 5 000€' ),
			$r( 2.65, 12, 'BAI Europa', 'Depósito a prazo', 1000, 10000000, false, false, 'notas', false, '2,00% mobilizável' ),
			$r( 2.65, 12, 'Easisave', 'Fixed term deposit', 1000, null, false, false, '', true, 'Juros trimestrais ou na maturidade' ),
			$r( 2.65, 12, 'Finantia', 'Jump', 50000, 500000, false, true, '', false, 'Juros semestrais' ),
			$r( 2.65, 24, 'Easisave', 'Fixed term deposit', 1000, null, false, false, '', true, 'Juros trimestrais ou na maturidade' ),
			$r( 2.65, 36, 'Easisave', 'Fixed term deposit', 1000, null, false, false, '', true, 'Juros trimestrais ou na maturidade' ),
			$r( 2.6, 6, 'Finantia', 'Jump', 50000, 500000, false, true, '', false, '' ),
			$r( 2.5, 2, 'Bankinter', 'TOP', 5000, 50000, false, true, 'sim', false, '' ),
			$r( 2.5, 2, 'Bankinter', 'TOP Premier', 50000, 500000, false, true, 'sim', false, '' ),
			$r( 2.5, 3, 'Carregosa', 'Bem-Vindo', 25000, 100000, false, true, '', false, '' ),
			$r( 2.5, 3, 'Carregosa', 'Welcome Boost', 1000, 150000, true, false, '', false, 'Canal NextGen, para até 30 anos' ),
			$r( 2.5, 3, 'Invest', 'Choice Novos Montantes', 2000, 150000, false, true, 'sim', false, '7,50€/trimestre manutenção se < 5 000€ e sem movimentos há 12 meses. Limitado a 150 000€ em depósitos de novos montantes' ),
			$r( 2.5, 3, 'Atlantico', 'Global Living', 2500, 100000, false, true, 'sim', false, '4,99€/mês manutenção' ),
			$r( 2.5, 3, 'BAI Europa', 'Depósito a prazo', 1000, 10000000, false, false, 'notas', false, '2,10% mobilizável' ),
			$r( 2.5, 3, 'BAI Europa', 'Novos Clientes', 2500, 100000, true, false, '', false, '+0,05% ou +0,10% para subscritores Proteste' ),
			$r( 2.5, 6, 'BiG', 'Super Depósito', 10000, 50000, false, false, 'sim', false, '+0,10% para ACP e ordens profissionais' ),
			$r( 2.5, 6, 'Invest', 'Choice Novos Montantes', 2000, 150000, false, true, 'sim', false, '7,50€/trimestre manutenção se < 5 000€ e sem movimentos há 12 meses. Limitado a 150 000€ em depósitos de novos montantes' ),
			$r( 2.5, 6, 'Openbank', 'Novos Clientes Tri', 1, null, true, false, 'sim', true, 'Juros trimestrais' ),
			$r( 2.5, 6, 'Banco CTT', 'Novos Montantes', 5000, 150000, false, true, '', false, '' ),
			$r( 2.5, 12, 'BiL', 'Term deposit', 10000, null, false, false, '', true, '' ),
			$r( 2.5, 12, 'BluOr Bank', 'Deposit account', 1000, null, false, false, '', true, 'Subscrever depósito sem abrir conta (que tem custos). Pedir redução da taxa de retenção' ),
			$r( 2.5, 12, 'Bison Bank', 'Rendimento Premium', 25000, 250000, false, true, '', false, '' ),
			$r( 2.5, 24, 'BluOr Bank', 'Deposit account', 1000, null, false, false, '', true, 'Subscrever depósito sem abrir conta (que tem custos). Pedir redução da taxa de retenção' ),
			$r( 2.5, 24, 'Finantia', 'Rendimento', 50000, 500000, false, false, 'notas', false, 'Juros semestrais. 2,35% mobilizável' ),
			$r( 2.5, 36, 'BluOr Bank', 'Deposit account', 1000, null, false, false, '', true, 'Subscrever depósito sem abrir conta (que tem custos). Pedir redução da taxa de retenção' ),
			$r( 2.5, 60, 'BluOr Bank', 'Deposit account', 1000, null, false, false, '', true, 'Subscrever depósito sem abrir conta (que tem custos). Pedir redução da taxa de retenção' ),
			$r( 2.45, 6, 'Finantia', 'Rendimento', 50000, 500000, false, false, 'notas', false, '2,15% mobilizável' ),
			$r( 2.45, 12, 'Finantia', 'Rendimento', 50000, 500000, false, false, 'notas', false, '2,20% mobilizável' ),
			$r( 2.45, 9, 'BluOr Bank', 'Deposit account', 1000, null, false, false, '', true, 'Subscrever depósito sem abrir conta (que tem custos). Pedir redução da taxa de retenção' ),
			$r( 2.4, 12, 'BPG', 'Save', 5000, 250000, false, false, '', false, 'Juros trimestrais com opção de capitalização' ),
			$r( 2.4, 24, 'MeDirect', 'Fixed term deposit', 100, null, false, false, '', true, 'Juros trimestrais, semestrais ou na maturidade' ),
			$r( 2.4, 36, 'BNI Europa', 'Depósito a prazo', 2500, null, false, false, 'notas', false, '2,35% com juros mensais. 2,20% mobilizável. 2,50€/mês manutenção se < 5 000€' ),
			$r( 2.4, 36, 'MeDirect', 'Fixed term deposit', 100, null, false, false, '', true, 'Juros trimestrais, semestrais ou na maturidade' ),
			$r( 2.4, 48, 'BNI Europa', 'Depósito a prazo', 2500, null, false, false, 'notas', false, '2,35% com juros mensais. 2,20% mobilizável. 2,50€/mês manutenção se < 5 000€' ),
			$r( 2.4, 48, 'MeDirect', 'Fixed term deposit', 100, null, false, false, '', true, 'Juros trimestrais, semestrais ou na maturidade' ),
			$r( 2.4, 60, 'BNI Europa', 'Depósito a prazo', 2500, null, false, false, 'notas', false, '2,35% com juros mensais. 2,20% mobilizável. 2,50€/mês manutenção se < 5 000€' ),
			$r( 2.4, 60, 'MeDirect', 'Fixed term deposit', 100, null, false, false, '', true, 'Juros trimestrais, semestrais ou na maturidade' ),
			$r( 2.38, 12, 'FCM Bank', 'Fixed term deposit', 2000, null, false, false, '', true, 'Juros mensais, trimestrais, semestrais ou na maturidade' ),
			$r( 2.38, 12, 'MeDirect', 'Fixed term deposit', 100, null, false, false, '', true, 'Juros trimestrais, semestrais ou na maturidade' ),
			$r( 2.35, 24, 'BNI Europa', 'Depósito a prazo', 2500, null, false, false, 'notas', false, '2,30% com juros mensais. 2,20% mobilizável. 2,50€/mês manutenção se < 5 000€' ),
			$r( 2.35, 24, 'BNI Europa', 'Depósito taxa crescente', 2500, null, false, false, 'sim', false, '1,85%, 2,20%, 2,35%, 3,00% no 1º, 2º, 3º, 4º semestre. Juros semestrais' ),
			$r( 2.35, 24, 'BAI Europa', 'Depósito a prazo', 1000, 10000000, false, false, 'notas', false, '2,00% mobilizável' ),
			$r( 2.3, 3, 'BAI Europa', 'Novos Clientes', 2500, 10000000, true, false, '', false, '' ),
			$r( 2.3, 3, 'Easisave', 'Fixed term deposit', 1000, null, false, false, '', true, '' ),
			$r( 2.3, 6, 'BluOr Bank', 'Deposit account', 1000, null, false, false, '', true, 'Subscrever depósito sem abrir conta (que tem custos). Pedir redução da taxa de retenção' ),
			$r( 2.3, 6, 'Easisave', 'Fixed term deposit', 1000, null, false, false, '', true, 'Juros trimestrais ou na maturidade' ),
			$r( 2.3, 9, 'Easisave', 'Fixed term deposit', 1000, null, false, false, '', true, 'Juros trimestrais ou na maturidade' ),
			$r( 2.3, 12, 'BNI Europa', 'Depósito a prazo', 2500, null, false, false, 'notas', false, '2,25% com juros mensais. 2,20% mobilizável. 2,50€/mês manutenção se < 5 000€' ),
			$r( 2.25, 3, 'Bankinter', 'TOP', 5000, 50000, false, true, 'sim', false, '' ),
			$r( 2.25, 6, 'BNI Europa', 'Depósito a prazo', 2500, null, false, false, 'notas', false, '2,20% com juros mensais. 2,10% mobilizável. 2,50€/mês manutenção se < 5 000€' ),
			$r( 2.25, 12, 'Bison Bank', 'Rendimento Premium', 25000, 250000, false, true, '', false, '' ),
			$r( 2.23, 9, 'Klarna', 'Conta poupança', null, null, false, false, 'sim', false, '' ),
			$r( 2.17, 36, 'ActivoBank', 'Depósito crescente', 5000, null, false, false, 'sim', false, '1,50%, 2,00%, 3,00% no 1º, 2º, 3º ano. Juros anuais' ),
			$r( 2.15, 12, 'BAI Europa', 'Premium', 2500, 10000000, false, false, 'sim', false, '+0,05% ou +0,10% para subscritores Proteste' ),
			$r( 2.15, 12, 'BPG', 'Valor', 5000, 750000, false, true, '', false, '' ),
			$r( 2.11, 12, 'bunq', 'Depósito a prazo', 1000, 100000, false, false, 'notas', true, 'Mobilização antecipada com penalização de 1%' ),
			$r( 2.11, 18, 'Klarna', 'Conta poupança', null, null, false, false, '', true, '' ),
			$r( 2.1, 2, 'ActivoBank', 'Flash', 2500, null, false, false, 'sim', false, 'Disponível durante o mês de junho' ),
			$r( 2.1, 3, 'BNI Europa', 'Depósito a prazo', 2500, null, false, false, '', false, '2,50€/mês manutenção se < 5 000€' ),
			$r( 2.1, 6, 'BiL', 'Term deposit', 10000, null, false, false, '', true, '' ),
			$r( 2.1, 24, 'BAI Europa', 'Premium', 2500, 10000000, false, false, 'sim', false, '' ),
			$r( 2.08, 3, 'FCM Bank', 'Fixed term deposit', 2000, null, false, false, '', true, 'Juros mensais ou na maturidade' ),
			$r( 2.05, 6, 'BPG', 'Valor', 5000, 750000, false, true, '', false, '' ),
			$r( 2.01, 6, 'FCM Bank', 'Fixed term deposit', 2000, null, false, false, '', true, 'Juros mensais, trimestrais ou na maturidade' ),
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
		$data   = self::data();
		$rows   = $data['rows'];
		$aforro = (float) ( $data['aforro'] ?? 2.215 );
		$amount = (int) ( $data['amount'] ?? 10000 );

		// Sort by rate desc, then term asc, for a sensible default order.
		usort(
			$rows,
			static function ( $a, $b ) {
				return ( (float) $b['rate'] <=> (float) $a['rate'] ) ?: ( (int) $a['term'] <=> (int) $b['term'] );
			}
		);

		$banks    = array();
		$terms    = array();
		$max_tanb = 0.0;
		foreach ( $rows as $row ) {
			$banks[ $row['bank'] ]       = true;
			$terms[ (int) $row['term'] ] = true;
			$max_tanb                    = max( $max_tanb, (float) $row['rate'] );
		}
		$banks = array_keys( $banks );
		sort( $banks );
		$terms = array_keys( $terms );
		sort( $terms );

		// Round the slider ceiling up to the next 0.5 so the top offer is reachable.
		$slider_max  = max( 0.5, ceil( $max_tanb * 2 ) / 2 );
		$top_tanb    = $max_tanb;
		$beats_count = 0;
		foreach ( $rows as $row ) {
			if ( (float) $row['rate'] > $aforro ) {
				++$beats_count;
			}
		}

		$aforro_label = number_format_i18n( $aforro, 3 ) . '%';

		ob_start();
		?>
		<section class="hti-dep" aria-label="<?php echo esc_attr( $data['title'] ); ?>"
			data-amount="<?php echo esc_attr( (string) $amount ); ?>"
			data-aforro="<?php echo esc_attr( (string) $aforro ); ?>">
			<header class="hti-dep__head">
				<span class="hti-dep__eyebrow"><span class="hti-dep__eyebrow-dot"></span>Ferramenta educativa</span>
				<h1 class="hti-dep__title"><?php echo esc_html( $data['title'] ); ?></h1>
				<?php if ( '' !== $data['intro'] ) : ?>
					<p class="hti-dep__intro"><?php echo esc_html( $data['intro'] ); ?></p>
				<?php endif; ?>
			</header>

			<!-- Amount calculator -->
			<div class="hti-dep__calc">
				<div class="hti-dep__calc-field">
					<label class="hti-dep__calc-label" for="hti-dep-amount">Montante a depositar</label>
					<div class="hti-dep__calc-input">
						<input type="text" inputmode="numeric" id="hti-dep-amount" class="hti-dep__amount" value="<?php echo esc_attr( self::group( $amount ) ); ?>" aria-label="Montante a depositar em euros">
						<span class="hti-dep__calc-eur">€</span>
					</div>
				</div>
				<p class="hti-dep__calc-note">O juro mostrado em cada cartão é <strong>estimado e ilustrativo</strong>, calculado sobre este montante, no prazo de cada produto, já com a retenção de IRS de 28%.</p>
			</div>

			<div class="hti-dep__layout">
				<!-- Filters -->
				<form class="hti-dep__filters" role="search" aria-label="Filtros">
					<div class="hti-dep__filters-head">
						<h2 class="hti-dep__filters-title">Filtros</h2>
						<button type="button" class="hti-dep__reset">Limpar</button>
					</div>

					<div class="hti-dep__search">
						<span class="hti-dep__search-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg></span>
						<input type="search" class="hti-dep__q" placeholder="Banco ou produto…" autocomplete="off" aria-label="Pesquisar banco ou produto">
					</div>

					<div class="hti-dep__group">
						<div class="hti-dep__group-head"><label for="hti-dep-tanb">TANB mínima</label><span class="hti-dep__tanb-val">0,00%</span></div>
						<input type="range" id="hti-dep-tanb" class="hti-dep__tanb" min="0" max="<?php echo esc_attr( (string) $slider_max ); ?>" step="0.05" value="0">
					</div>

					<div class="hti-dep__group">
						<span class="hti-dep__group-label">Prazo</span>
						<div class="hti-dep__chips">
							<button type="button" class="hti-dep__chip is-active" data-term="">Todos</button>
							<?php foreach ( $terms as $t ) : ?>
								<button type="button" class="hti-dep__chip" data-term="<?php echo esc_attr( (string) $t ); ?>"><?php echo esc_html( $t . 'm' ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="hti-dep__group">
						<label class="hti-dep__group-label" for="hti-dep-bank">Banco</label>
						<select class="hti-dep__bank" id="hti-dep-bank">
							<option value="">Todos os bancos</option>
							<?php foreach ( $banks as $bank ) : ?>
								<option value="<?php echo esc_attr( $bank ); ?>"><?php echo esc_html( $bank ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="hti-dep__switches">
						<label class="hti-dep__switch"><span>Novos clientes</span><input type="checkbox" class="hti-dep__nc"><span class="hti-dep__track" aria-hidden="true"></span></label>
						<label class="hti-dep__switch"><span>Mobilização antecipada</span><input type="checkbox" class="hti-dep__early"><span class="hti-dep__track" aria-hidden="true"></span></label>
						<label class="hti-dep__switch"><span>Bancos estrangeiros (IRS)</span><input type="checkbox" class="hti-dep__naores"><span class="hti-dep__track" aria-hidden="true"></span></label>
					</div>
				</form>

				<!-- Results -->
				<div class="hti-dep__results">
					<div class="hti-dep__results-head">
						<p class="hti-dep__count" role="status" aria-live="polite"><span class="hti-dep__count-n"><?php echo esc_html( (string) count( $rows ) ); ?></span> depósitos · <span class="hti-dep__beats"><span class="hti-dep__beats-n"><?php echo esc_html( (string) $beats_count ); ?></span> acima dos Certificados de Aforro</span></p>
						<label class="hti-dep__sortwrap">Ordenar:
							<select class="hti-dep__sort" aria-label="Ordenar resultados">
								<option value="rate">Maior taxa</option>
								<option value="term">Menor prazo</option>
								<option value="min">Menor mínimo</option>
							</select>
						</label>
					</div>

					<div class="hti-dep__aforro">
						<span class="hti-dep__aforro-icon" aria-hidden="true"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg></span>
						<span>Referência do Estado: <strong>Certificados de Aforro a <?php echo esc_html( $aforro_label ); ?></strong> (TANB ilustrativa). Um depósito só compensa se a taxa, líquida de custos, superar esta alternativa de baixo risco.</span>
					</div>

					<div class="hti-dep__list">
						<?php foreach ( $rows as $row ) : ?>
							<?php echo self::card( $row, $amount, $aforro, $top_tanb ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in card(). ?>
						<?php endforeach; ?>
					</div>

					<div class="hti-dep__empty" hidden>
						<p class="hti-dep__empty-t">Nenhum depósito corresponde aos filtros</p>
						<p class="hti-dep__empty-d">Experimenta baixar a TANB mínima ou alargar o prazo e o montante.</p>
						<button type="button" class="hti-dep__reset hti-dep__reset--btn">Limpar filtros</button>
					</div>

					<!-- Contextual notes -->
					<div class="hti-dep__notes-grid">
						<div class="hti-dep__note"><div class="hti-dep__note-t hti-dep__note-t--green">Garantia até 100 000 €</div><p>O Fundo de Garantia de Depósitos (FGD) cobre até 100 000 € por depositante e por instituição. Acima disso, convém dividir entre bancos.</p></div>
						<div class="hti-dep__note"><div class="hti-dep__note-t hti-dep__note-t--purple">Custos escondidos</div><p>Alguns bancos exigem conta à ordem com custos de manutenção. Confirma se as transferências para constituir o depósito são gratuitas.</p></div>
						<div class="hti-dep__note"><div class="hti-dep__note-t hti-dep__note-t--amber">IRS de 28%</div><p>Os juros são tributados a 28% (retenção na fonte). Não residentes podem ter regras diferentes — confirma a tua situação fiscal.</p></div>
					</div>

					<?php if ( '' !== trim( (string) $data['footer'] ) ) : ?>
						<div class="hti-dep__footer">
							<?php
							$paras = preg_split( '/\n\s*\n/', str_replace( "\r\n", "\n", (string) $data['footer'] ) );
							foreach ( (array) $paras as $p ) {
								$p = trim( $p );
								if ( '' !== $p ) {
									echo '<p>' . esc_html( $p ) . '</p>';
								}
							}
							?>
						</div>
					<?php endif; ?>

					<!-- Strong disclaimer -->
					<div class="hti-dep__disclaimer">
						<span class="hti-dep__disclaimer-icon" aria-hidden="true">!</span>
						<p><strong>Importante:</strong> esta ferramenta é educativa. As taxas, prazos e condições são <strong>ilustrativos</strong>, recolhidos de fontes públicas, e não refletem ofertas em tempo real. Não é aconselhamento financeiro nem uma recomendação. Confirma sempre as condições atuais junto de cada instituição e na respetiva FIN (Ficha de Informação Normalizada) antes de decidir. Os juros estimados não consideram custos de conta nem todos os impostos aplicáveis. Capital garantido pelo FGD até 100 000€ por titular e por instituição.</p>
					</div>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Emit Dataset JSON-LD for the comparator page (semantic SEO for a data
	 * table). Only on the singular view that embeds the shortcode.
	 */
	public static function schema(): void {
		if ( ! self::is_page() ) {
			return;
		}
		$data = self::data();
		$post = get_queried_object();
		$url  = $post instanceof \WP_Post ? get_permalink( $post ) : home_url( '/' );

		$node = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'Dataset',
			'name'            => wp_strip_all_tags( (string) $data['title'] ),
			'description'     => wp_strip_all_tags( (string) $data['intro'] ),
			'inLanguage'      => 'pt-PT',
			'url'             => $url,
			'isAccessibleForFree' => true,
			'creator'         => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
			'variableMeasured' => array( 'TANB', 'Prazo', 'Montante mínimo', 'Montante máximo' ),
		);
		if ( ! empty( $data['updated'] ) ) {
			$node['dateModified'] = (string) $data['updated'];
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * One offer card with filter data attributes and an estimated-interest block.
	 *
	 * @param array<string,mixed> $row      Row.
	 * @param int                 $amount   Default deposit amount for the estimate.
	 * @param float               $aforro   Savings-certificate reference rate.
	 * @param float               $top_tanb Highest TANB in the set (for the badge).
	 * @return string Safe HTML.
	 */
	private static function card( array $row, int $amount, float $aforro, float $top_tanb ): string {
		$rate  = (float) $row['rate'];
		$term  = (int) $row['term'];
		$min   = isset( $row['min'] ) && '' !== $row['min'] && null !== $row['min'] ? (int) $row['min'] : null;
		$max   = isset( $row['max'] ) && '' !== $row['max'] && null !== $row['max'] ? (int) $row['max'] : null;
		$mobil = (string) ( $row['mobil'] ?? '' );
		$nc    = ! empty( $row['nc'] );
		$nm    = ! empty( $row['nm'] );
		$irs   = ! empty( $row['irs'] );
		$early = '' !== $mobil;
		$bank  = (string) $row['bank'];
		$prod  = (string) $row['product'];
		$notes = (string) ( $row['notes'] ?? '' );

		$rate_str = number_format_i18n( $rate, 2 ) . '%';
		$term_str = $term . ( 1 === $term ? ' mês' : ' meses' );
		$is_top   = $rate >= $top_tanb && $top_tanb > 0;
		$beats    = $rate > $aforro;

		// Estimated interest over the full term (server default; JS recomputes).
		$gross = $amount * $rate / 100 * $term / 12;
		$net   = $gross * 0.72;

		$min_label = null !== $min ? 'Mín. ' . self::money( $min ) : 'Sem mínimo';
		$max_label = null !== $max ? 'Máx. ' . self::money( $max ) : 'Sem limite';

		// Mobilization badge (tri-state mapped from the stored field).
		if ( 'sim' === $mobil ) {
			$mob_label = 'Mobilização antecipada';
			$mob_class = 'hti-dep__chiptag--green';
		} elseif ( 'notas' === $mobil ) {
			$mob_label = 'Mobilização (ver notas)';
			$mob_class = 'hti-dep__chiptag--amber';
		} else {
			$mob_label = 'Sem mobilização';
			$mob_class = '';
		}

		$search = strtolower( $bank . ' ' . $prod . ' ' . $notes );

		ob_start();
		?>
		<div class="hti-dep__card<?php echo $is_top ? ' is-top' : ''; ?>"
			data-tanb="<?php echo esc_attr( (string) $rate ); ?>"
			data-term="<?php echo esc_attr( (string) $term ); ?>"
			data-bank="<?php echo esc_attr( $bank ); ?>"
			data-min="<?php echo esc_attr( null === $min ? '' : (string) $min ); ?>"
			data-max="<?php echo esc_attr( null === $max ? '' : (string) $max ); ?>"
			data-novos="<?php echo $nc ? '1' : '0'; ?>"
			data-early="<?php echo $early ? '1' : '0'; ?>"
			data-naores="<?php echo $irs ? '1' : '0'; ?>"
			data-text="<?php echo esc_attr( $search ); ?>">
			<span class="hti-dep__top" aria-hidden="<?php echo $is_top ? 'false' : 'true'; ?>"<?php echo $is_top ? '' : ' hidden'; ?>>★ Melhor taxa</span>
			<div class="hti-dep__card-main">
				<div class="hti-dep__avatar" aria-hidden="true"><?php echo esc_html( self::initials( $bank ) ); ?></div>
				<div class="hti-dep__id">
					<div class="hti-dep__bank"><?php echo esc_html( $bank ); ?></div>
					<div class="hti-dep__prod"><?php echo esc_html( trim( $prod . ' · ' . $term_str, ' ·' ) ); ?></div>
					<div class="hti-dep__chiptags">
						<span class="hti-dep__chiptag <?php echo esc_attr( $mob_class ); ?>"><?php echo esc_html( $mob_label ); ?></span>
						<span class="hti-dep__chiptag"><?php echo esc_html( $nc ? 'Novos clientes' : 'Todos os clientes' ); ?></span>
						<?php if ( $nm ) : ?><span class="hti-dep__chiptag">Novos montantes</span><?php endif; ?>
						<?php if ( $irs ) : ?><span class="hti-dep__chiptag hti-dep__chiptag--blue">Não residentes</span><?php endif; ?>
					</div>
				</div>
			</div>
			<div class="hti-dep__tanb">
				<div class="hti-dep__tanb-num"><?php echo esc_html( $rate_str ); ?></div>
				<div class="hti-dep__tanb-lbl">TANB</div>
				<div class="hti-dep__tanb-beats"<?php echo $beats ? '' : ' hidden'; ?>>▲ acima do Aforro</div>
			</div>
			<div class="hti-dep__est">
				<div class="hti-dep__est-lbl">Juro líquido estimado</div>
				<div class="hti-dep__est-net">+<?php echo esc_html( self::money( (int) round( $net ) ) ); ?></div>
				<div class="hti-dep__est-gross">bruto <?php echo esc_html( self::money( (int) round( $gross ) ) ); ?></div>
				<div class="hti-dep__est-minmax"><?php echo esc_html( $min_label . ' · ' . $max_label ); ?></div>
			</div>
			<?php if ( '' !== $notes ) : ?><p class="hti-dep__card-notes"><?php echo esc_html( $notes ); ?></p><?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Bank initials for the avatar (first letters of the meaningful words).
	 *
	 * @param string $bank Bank name.
	 */
	private static function initials( string $bank ): string {
		$words = preg_split( '/\s+/', trim( $bank ) );
		$out   = '';
		foreach ( (array) $words as $w ) {
			if ( mb_strlen( $w ) > 2 && mb_strlen( $out ) < 2 ) {
				$out .= mb_strtoupper( mb_substr( $w, 0, 1 ) );
			}
		}
		if ( '' === $out && '' !== $bank ) {
			$out = mb_strtoupper( mb_substr( $bank, 0, 2 ) );
		}
		return $out;
	}

	/**
	 * Format a euro amount (thousands separated, no decimals).
	 *
	 * @param int $n Amount.
	 */
	private static function money( int $n ): string {
		return number_format_i18n( $n ) . ' €';
	}

	/**
	 * Group an integer with thin spaces for the amount input default.
	 *
	 * @param int $n Amount.
	 */
	private static function group( int $n ): string {
		return number_format( $n, 0, ',', ' ' );
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
						<th scope="row"><label for="hti-dep-aforro"><?php esc_html_e( 'Certificados de Aforro (TANB %)', 'hti-engine' ); ?></label></th>
						<td><input name="aforro" id="hti-dep-aforro" type="text" class="small-text" value="<?php echo esc_attr( number_format( (float) ( $data['aforro'] ?? 2.215 ), 3, ',', '' ) ); ?>"> %
						<p class="description"><?php esc_html_e( 'Taxa de referência do Estado usada na comparação “acima do Aforro”. Ex.: 2,215', 'hti-engine' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-dep-amount"><?php esc_html_e( 'Montante por omissão (€)', 'hti-engine' ); ?></label></th>
						<td><input name="amount" id="hti-dep-amount" type="number" min="0" step="500" class="regular-text" value="<?php echo esc_attr( (string) (int) ( $data['amount'] ?? 10000 ) ); ?>">
						<p class="description"><?php esc_html_e( 'Valor inicial da calculadora de juro estimado.', 'hti-engine' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-dep-intro"><?php esc_html_e( 'Introdução', 'hti-engine' ); ?></label></th>
						<td><textarea name="intro" id="hti-dep-intro" rows="3" class="large-text"><?php echo esc_textarea( $data['intro'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-dep-footer"><?php esc_html_e( 'Notas de rodapé', 'hti-engine' ); ?></label></th>
						<td><textarea name="footer" id="hti-dep-footer" rows="6" class="large-text"><?php echo esc_textarea( $data['footer'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Texto de contexto mostrado abaixo da tabela (transferências, custos por banco, comparação com certificados de aforro…). Separa parágrafos com uma linha em branco.', 'hti-engine' ); ?></p></td>
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

		$aforro = isset( $_POST['aforro'] ) ? self::to_float( (string) wp_unslash( $_POST['aforro'] ) ) : (float) ( $current['aforro'] ?? 2.215 );
		$amount = isset( $_POST['amount'] ) ? max( 0, (int) wp_unslash( $_POST['amount'] ) ) : (int) ( $current['amount'] ?? 10000 );

		$data = array(
			'title'   => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : $current['title'],
			'version' => isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : $current['version'],
			'aforro'  => $aforro > 0 ? $aforro : 2.215,
			'amount'  => $amount > 0 ? $amount : 10000,
			'intro'   => isset( $_POST['intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['intro'] ) ) : $current['intro'],
			'footer'  => isset( $_POST['footer'] ) ? sanitize_textarea_field( wp_unslash( $_POST['footer'] ) ) : ( $current['footer'] ?? '' ),
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
