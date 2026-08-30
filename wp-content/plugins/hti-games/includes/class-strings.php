<?php
/**
 * Every word the two games show, in English and Portuguese.
 *
 * Not `__()`. The site runs the `pt_PT_ao90` locale while the translation
 * files are named `pt_PT`, and WordPress does not fall back between them — a
 * `__()` string does not warn when its translation is missing, it just renders
 * in English. So user-facing copy lives in a plain table, the way
 * HTI\Forex\Config does it, and tests/test-strings.php fails if any key is
 * missing a language.
 *
 * Two conventions worth knowing before editing:
 *
 * 1. Anything with a number in it takes a `%d`/`%s` placeholder and is filled
 *    at render time from the engine. The risk warnings are the reason: the
 *    design prototype hardcoded "at 2% you can lose 30 trades in a row", which
 *    comes from a linear 90/risk model and is wrong — the compounding answer
 *    is 114. A game whose whole subject is risk arithmetic cannot ship
 *    arithmetic that does not hold, and copy that carries its own numbers
 *    drifts from the engine the first time either changes.
 *
 * 2. The voice is the site's: calm, second person, conditional. No urgency, no
 *    promises, no "beat the market", no imperative to act. A leaderboard pulls
 *    hard in the other direction, which is exactly why the rule is written
 *    down here. See .claude/skills/brand-voice/SKILL.md.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * The bilingual copy table. Pure.
 */
class Strings {

	/**
	 * Languages this table is complete in.
	 */
	public const LANGS = array( 'en', 'pt' );

	/**
	 * Every string, keyed, with both languages side by side so a missing
	 * translation is visible in the diff rather than at runtime.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	public static function all(): array {
		return array_merge(
			self::shared(),
			self::onboarding(),
			self::stc(),
			self::reveal(),
			self::social(),
			self::badges(),
			self::labels(),
			self::account(),
			self::states()
		);
	}

	/**
	 * The table flattened to one language.
	 *
	 * @param string $lang 'en' or 'pt'.
	 * @return array<string,string>
	 */
	public static function table( string $lang ): array {
		$lang = in_array( $lang, self::LANGS, true ) ? $lang : 'en';
		$out  = array();
		foreach ( self::all() as $key => $pair ) {
			$out[ $key ] = $pair[ $lang ] ?? $pair['en'];
		}
		return $out;
	}

	/**
	 * One string.
	 *
	 * @param string $key  Key.
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function get( string $key, string $lang ): string {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return '';
		}
		$lang = in_array( $lang, self::LANGS, true ) ? $lang : 'en';
		return $all[ $key ][ $lang ] ?? $all[ $key ]['en'];
	}

	/**
	 * Chrome, disclaimers and the words both games share.
	 *
	 * The disclaimers are adapted from the canonical set in
	 * docs/Textos_Finais_HowToInvest_MVP.md — the game context needs "virtual
	 * money, nothing executed" said plainly, which the portfolio wording does
	 * not cover — but they keep its shape and its promises, and no game screen
	 * invents its own.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function shared(): array {
		return array(
			'section_name'       => array(
				'en' => 'Educational games',
				'pt' => 'Jogos educacionais',
			),
			'disclaimer_short'   => array(
				'en' => 'Educational simulation · virtual money · not financial advice',
				'pt' => 'Simulação educativa · dinheiro virtual · não é aconselhamento',
			),
			'disclaimer_full'    => array(
				'en' => 'This is an educational simulation. The money is virtual, nothing here is executed anywhere, and nothing here is financial advice or a recommendation to buy or sell any asset. Investing carries risk, including loss of capital. Past outcomes say nothing about future ones.',
				'pt' => 'Isto é uma simulação educativa. O dinheiro é virtual, nada aqui é executado em lado nenhum, e nada aqui é aconselhamento financeiro nem recomendação de compra ou venda de qualquer ativo. Investir envolve risco, incluindo a perda de capital. Resultados passados não dizem nada sobre os futuros.',
			),
			'no_brokers'         => array(
				'en' => 'No sign-up needed, no real money, and no broker anywhere in this section.',
				'pt' => 'Sem registo, sem dinheiro real, e sem nenhuma corretora nesta secção.',
			),
			'chip_no_signup'     => array(
				'en' => 'No sign-up',
				'pt' => 'Sem registo',
			),
			'chip_no_money'      => array(
				'en' => 'No real money',
				'pt' => 'Sem dinheiro real',
			),
			'chip_two_minutes'   => array(
				'en' => 'Two minutes',
				'pt' => 'Dois minutos',
			),
			'capital_label'      => array(
				'en' => 'Your capital',
				'pt' => 'O teu capital',
			),
			'streak_label'       => array(
				'en' => 'Streak',
				'pt' => 'Série',
			),
			'record_label'       => array(
				'en' => 'Record',
				'pt' => 'Recorde',
			),
			'day_label'          => array(
				'en' => 'Day %d',
				'pt' => 'Dia %d',
			),
			'next_reset'         => array(
				'en' => 'A new challenge in %s',
				'pt' => 'Um desafio novo daqui a %s',
			),
			'cta_play_today'     => array(
				'en' => "Play today's challenge",
				'pt' => 'Jogar o desafio de hoje',
			),
			'cta_next_day'       => array(
				'en' => 'Next day',
				'pt' => 'Dia seguinte',
			),
			'cta_start_again'    => array(
				'en' => 'Start again',
				'pt' => 'Começar de novo',
			),
			'cta_back'           => array(
				'en' => 'Back',
				'pt' => 'Voltar',
			),
			'cta_skip'           => array(
				'en' => 'Skip',
				'pt' => 'Saltar',
			),
			'cta_share'          => array(
				'en' => 'Share result',
				'pt' => 'Partilhar resultado',
			),
			'cta_copy_card'      => array(
				'en' => 'Copy card',
				'pt' => 'Copiar cartão',
			),
			'copied'             => array(
				'en' => 'Copied',
				'pt' => 'Copiado',
			),
			'lesson_of_the_day'  => array(
				'en' => 'Lesson of the day',
				'pt' => 'Lição do dia',
			),
			'already_played'     => array(
				'en' => "You have already played today. The decision stands — that is the point of one a day.",
				'pt' => 'Já jogaste hoje. A decisão fica — é essa a ideia de ser um por dia.',
			),
			'day_moved'          => array(
				'en' => 'The day rolled over while this was open. Here is the new challenge.',
				'pt' => 'O dia virou enquanto isto esteve aberto. Aqui está o desafio novo.',
			),
		);
	}

	/**
	 * The three onboarding cards, per game, and the acknowledgement.
	 *
	 * The checkbox is worded as an acknowledgement, never as consent: a box you
	 * must tick to play is not freely given, so leaning on it as a lawful basis
	 * would be building on sand. The identity cookie is strictly necessary for
	 * the thing the visitor asked for; the newsletter box is separate, unticked
	 * and genuinely optional.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function onboarding(): array {
		return array(
			'ob_ack'              => array(
				'en' => 'I understand this is an educational simulation with virtual money, that nothing is executed anywhere, and that nothing here is investment advice.',
				'pt' => 'Compreendo que isto é uma simulação educativa com dinheiro virtual, que nada é executado em lado nenhum, e que nada aqui é aconselhamento de investimento.',
			),
			'ob_ack_gate'         => array(
				'en' => 'Tick the box to continue',
				'pt' => 'Marca a caixa para continuar',
			),
			'ob_accept'           => array(
				'en' => 'Understood — start',
				'pt' => 'Percebido — começar',
			),
			'ob_next'             => array(
				'en' => 'Next',
				'pt' => 'Seguinte',
			),

			'stc_ob1_kicker'      => array(
				'en' => 'The premise',
				'pt' => 'A premissa',
			),
			'stc_ob1_title'       => array(
				'en' => 'One chart a day. Buy, sell, or walk away.',
				'pt' => 'Um gráfico por dia. Comprar, vender, ou passar ao lado.',
			),
			'stc_ob1_body'        => array(
				'en' => 'Eighty candles, no name on the market, and no way to look it up. You call the direction, choose how much of the account to put behind it, and watch it play out.',
				'pt' => 'Oitenta velas, sem nome no mercado, e sem forma de o ir procurar. Escolhes a direção, escolhes quanto da conta pões atrás dela, e vês o que acontece.',
			),
			'stc_ob2_kicker'      => array(
				'en' => 'The rules',
				'pt' => 'As regras',
			),
			'stc_ob2_title'       => array(
				'en' => 'Four rules and you are in.',
				'pt' => 'Quatro regras e já está.',
			),
			'stc_ob2_r1'          => array(
				'en' => '$10,000 of virtual capital, carried from day to day.',
				'pt' => '10 000 $ de capital virtual, transportado de dia para dia.',
			),
			'stc_ob2_r2'          => array(
				'en' => 'The stop sits one ATR away, the target one and a half. Same every day.',
				'pt' => 'O stop fica a um ATR de distância, o alvo a um e meio. Igual todos os dias.',
			),
			'stc_ob2_r3'          => array(
				'en' => 'Reaching $1,000 blows the account. You start over; the record stays.',
				'pt' => 'Chegar aos 1 000 $ rebenta a conta. Recomeças; o recorde fica.',
			),
			'stc_ob2_r4'          => array(
				'en' => 'Passing never breaks the streak. Some days passing is the move.',
				'pt' => 'Passar nunca quebra a série. Há dias em que passar é a jogada.',
			),
			'stc_ob3_kicker'      => array(
				'en' => 'Before you start',
				'pt' => 'Antes de começar',
			),
			'stc_ob3_title'       => array(
				'en' => 'This is a simulation, not a tip.',
				'pt' => 'Isto é uma simulação, não é uma dica.',
			),

			'rev_ob1_kicker'      => array(
				'en' => 'The premise',
				'pt' => 'A premissa',
			),
			'rev_ob1_title'       => array(
				'en' => 'A real company. A real year. No name.',
				'pt' => 'Uma empresa real. Um ano real. Sem nome.',
			),
			'rev_ob1_body'        => array(
				'en' => 'Each day opens a dossier on a company at a moment in its history: sector, fundamentals, and the headlines of the time. No name, no chart. You decide whether it deserves your money — and only then comes the reveal.',
				'pt' => 'Todos os dias abres o dossiê de uma empresa num momento da sua história: setor, fundamentais e as manchetes da época. Sem nome, sem gráfico. Decides se merece o teu dinheiro — e só depois vem a revelação.',
			),
			'rev_ob2_kicker'      => array(
				'en' => 'The rules',
				'pt' => 'As regras',
			),
			'rev_ob2_title'       => array(
				'en' => 'Four rules and you are in.',
				'pt' => 'Quatro regras e já está.',
			),
			'rev_ob2_r1'          => array(
				'en' => '$10,000 of virtual capital, carried from day to day.',
				'pt' => '10 000 $ de capital virtual, transportado de dia para dia.',
			),
			'rev_ob2_r2'          => array(
				'en' => 'You commit 5%, 10%, 25% or 50% of it — or you pass.',
				'pt' => 'Comprometes 5%, 10%, 25% ou 50% dele — ou passas.',
			),
			'rev_ob2_r3'          => array(
				'en' => 'The outcome is what the company actually returned over the five years that followed.',
				'pt' => 'O resultado é o que a empresa realmente rendeu nos cinco anos seguintes.',
			),
			'rev_ob2_r4'          => array(
				'en' => 'Reaching $1,000 blows the account. Passing never breaks the streak.',
				'pt' => 'Chegar aos 1 000 $ rebenta a conta. Passar nunca quebra a série.',
			),
			'rev_ob3_kicker'      => array(
				'en' => 'Before you start',
				'pt' => 'Antes de começar',
			),
			'rev_ob3_title'       => array(
				'en' => 'These are case studies, not tips.',
				'pt' => 'Isto são casos de estudo, não são dicas.',
			),
			'rev_ob3_body'        => array(
				'en' => 'Every case is historical, at least five years past, and checked against a published source you can open. None of it is a view on that company today.',
				'pt' => 'Todos os casos são históricos, com pelo menos cinco anos, e verificados contra uma fonte publicada que podes abrir. Nada disto é uma opinião sobre essa empresa hoje.',
			),
		);
	}

	/**
	 * Survive the Charts.
	 *
	 * The risk warnings all carry a %d filled from
	 * STC_Engine::losses_to_ruin(), so the copy and the maths cannot drift.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function stc(): array {
		return array(
			'stc_name'            => array(
				'en' => 'Survive the Charts',
				'pt' => 'Sobreviver aos Gráficos',
			),
			'stc_tagline'         => array(
				'en' => 'One chart a day. Most accounts do not last a month.',
				'pt' => 'Um gráfico por dia. A maioria das contas não dura um mês.',
			),
			'stc_survival'        => array(
				'en' => 'Account health',
				'pt' => 'Saúde da conta',
			),
			'stc_from_start'      => array(
				'en' => '%s from the start',
				'pt' => '%s desde o início',
			),
			'stc_chart_decide'    => array(
				'en' => "Today's challenge",
				'pt' => 'O desafio de hoje',
			),
			'stc_chart_tag'       => array(
				'en' => 'market hidden · 80 candles',
				'pt' => 'mercado escondido · 80 velas',
			),
			'stc_chart_levels'    => array(
				'en' => 'Stop and target set',
				'pt' => 'Stop e alvo definidos',
			),
			'stc_chart_replay'    => array(
				'en' => 'Playing out',
				'pt' => 'A desenrolar',
			),
			'stc_chart_done'      => array(
				'en' => 'Outcome revealed',
				'pt' => 'Resultado revelado',
			),
			'stc_buy'             => array(
				'en' => 'Buy',
				'pt' => 'Comprar',
			),
			'stc_sell'            => array(
				'en' => 'Sell',
				'pt' => 'Vender',
			),
			'stc_pass'            => array(
				'en' => 'Pass — no trade today',
				'pt' => 'Passar — hoje não',
			),
			'stc_risk_title'      => array(
				'en' => 'How much of the account goes behind this?',
				'pt' => 'Quanto da conta vai atrás disto?',
			),
			'stc_at_risk'         => array(
				'en' => 'At risk',
				'pt' => 'Em risco',
			),
			'stc_double'          => array(
				'en' => 'Double the stake',
				'pt' => 'Dobrar a aposta',
			),
			'stc_double_note'     => array(
				'en' => 'Doubles what you can win and what you can lose.',
				'pt' => 'Duplica o que podes ganhar e o que podes perder.',
			),
			'stc_confirm'         => array(
				'en' => 'Take the trade',
				'pt' => 'Fazer a operação',
			),
			'stc_confirm_high'    => array(
				'en' => 'Leap of faith',
				'pt' => 'Salto de fé',
			),
			'stc_skip_replay'     => array(
				'en' => 'Skip to the result',
				'pt' => 'Saltar para o resultado',
			),
			// Risk tiers. %d is the number of consecutive full-risk losses
			// that take $10,000 to $1,000 under compounding.
			'stc_warn_50'         => array(
				'en' => 'Survivable. At 0.5% you could lose %d in a row and still be here.',
				'pt' => 'Sobrevivível. A 0,5% podias perder %d seguidas e continuar aqui.',
			),
			'stc_warn_100'        => array(
				'en' => 'What professionals tend to use. %d losses in a row to blow up.',
				'pt' => 'O que os profissionais costumam usar. %d perdas seguidas para rebentar.',
			),
			'stc_warn_200'        => array(
				'en' => 'The classic ceiling. %d losses in a row to blow up — the edge of sane.',
				'pt' => 'O teto clássico. %d perdas seguidas para rebentar — o limite do razoável.',
			),
			'stc_warn_500'        => array(
				'en' => 'Aggressive. %d in a row ends it, and a six-loss month is ordinary.',
				'pt' => 'Agressivo. %d seguidas acabam com isto, e um mês com seis perdas é banal.',
			),
			'stc_warn_1000'       => array(
				'en' => 'Steep. %d losses in a row and it is over. %d happens.',
				'pt' => 'Pesado. %d perdas seguidas e acabou. %d acontece.',
			),
			'stc_warn_2500'       => array(
				'en' => 'This is not trading. %d bad days end the account, and bad days are ordinary.',
				'pt' => 'Isto não é operar. %d dias maus acabam com a conta, e dias maus são banais.',
			),
			'stc_res_target'      => array(
				'en' => 'Target hit',
				'pt' => 'Alvo atingido',
			),
			'stc_res_stop'        => array(
				'en' => 'Stopped out',
				'pt' => 'Stop accionado',
			),
			'stc_res_timeout'     => array(
				'en' => 'Closed at expiry',
				'pt' => 'Fechado no prazo',
			),
			'stc_res_pass'        => array(
				'en' => 'No trade',
				'pt' => 'Sem operação',
			),
			'stc_title_win'       => array(
				'en' => 'You survive today.',
				'pt' => 'Sobrevives a hoje.',
			),
			'stc_title_loss'      => array(
				'en' => 'Lesson paid for.',
				'pt' => 'Lição paga.',
			),
			'stc_title_pass_good' => array(
				'en' => 'Well passed.',
				'pt' => 'Bem passado.',
			),
			'stc_title_pass'      => array(
				'en' => 'You sat that one out.',
				'pt' => 'Ficaste de fora dessa.',
			),
			'stc_lucky_win'       => array(
				'en' => 'That came off, at a size where it did not have to. The account survived the call, not the sizing.',
				'pt' => 'Correu bem, num tamanho em que podia não ter corrido. A conta sobreviveu à leitura, não ao tamanho.',
			),
			'stc_crowd_lost'      => array(
				'en' => 'Players who lost on this one',
				'pt' => 'Jogadores que perderam nesta',
			),
			'stc_crowd_entered'   => array(
				'en' => 'Players who entered and lost',
				'pt' => 'Jogadores que entraram e perderam',
			),
			'stc_dead_title'      => array(
				'en' => 'Account blown',
				'pt' => 'Conta rebentada',
			),
			'stc_dead_days'       => array(
				'en' => 'Days survived',
				'pt' => 'Dias sobrevividos',
			),
			'stc_dead_cause'      => array(
				'en' => 'What ended it',
				'pt' => 'O que acabou com isto',
			),
			'stc_dead_avg'        => array(
				'en' => 'Average risk per trade',
				'pt' => 'Risco médio por operação',
			),
			'stc_dead_counter'    => array(
				'en' => 'At 2% per trade it would have taken %d losses in a row to get here.',
				'pt' => 'A 2% por operação teriam sido precisas %d perdas seguidas para chegar aqui.',
			),
			'stc_dead_tool'       => array(
				'en' => 'See what position size does to an account',
				'pt' => 'Vê o que o tamanho da posição faz a uma conta',
			),
			// The landing claim, in two variants. The real one is only ever
			// shown when Library::is_real() says the whole pool is imported
			// market data — computed, never a setting somebody can tick.
			'stc_claim_generated' => array(
				'en' => 'A market that behaves like the real thing, rebuilt fresh every day.',
				'pt' => 'Um mercado que se comporta como o real, reconstruído de novo todos os dias.',
			),
			'stc_claim_real'      => array(
				'en' => 'A real historical chart, a different one every day.',
				'pt' => 'Um gráfico histórico real, um diferente todos os dias.',
			),
		);
	}

	/**
	 * The Reveal.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function reveal(): array {
		return array(
			'rev_name'          => array(
				'en' => 'The Reveal',
				'pt' => 'A Revelação',
			),
			'rev_tagline'       => array(
				'en' => 'A real company, its name withheld. Would you have put money behind it?',
				'pt' => 'Uma empresa real, com o nome tapado. Terias posto dinheiro atrás dela?',
			),
			'rev_dossier'       => array(
				'en' => 'Dossier no. %s',
				'pt' => 'Dossiê n.º %s',
			),
			'rev_unnamed'       => array(
				'en' => 'Company withheld',
				'pt' => 'Empresa por identificar',
			),
			'rev_confidential'  => array(
				'en' => 'Confidential',
				'pt' => 'Confidencial',
			),
			'rev_sector'        => array(
				'en' => 'Sector',
				'pt' => 'Setor',
			),
			'rev_revenue'       => array(
				'en' => 'Annual revenue',
				'pt' => 'Receita anual',
			),
			'rev_fundamentals'  => array(
				'en' => 'Fundamentals for the year',
				'pt' => 'Fundamentais do ano',
			),
			'rev_sector_avg'    => array(
				'en' => 'sector average',
				'pt' => 'média do setor',
			),
			'rev_headlines'     => array(
				'en' => 'Headlines from the time',
				'pt' => 'Manchetes da época',
			),
			'rev_index_label'   => array(
				'en' => 'Index',
				'pt' => 'Índice',
			),
			'rev_pass'          => array(
				'en' => 'Pass on this one',
				'pt' => 'Passar nesta',
			),
			'rev_invest'        => array(
				'en' => 'Put money behind it',
				'pt' => 'Pôr dinheiro atrás dela',
			),
			'rev_size_title'    => array(
				'en' => 'How much of the account?',
				'pt' => 'Quanto da conta?',
			),
			'rev_confirm'       => array(
				'en' => 'Commit %d%%',
				'pt' => 'Comprometer %d%%',
			),
			'rev_warn_5'        => array(
				'en' => 'Cautious. Even a total loss takes 5% — you would survive many mistakes.',
				'pt' => 'Prudente. Mesmo uma perda total leva 5% — sobreviverias a muitos erros.',
			),
			'rev_warn_10'       => array(
				'en' => 'The size of people who reach the end. It would take %d total losses to blow up.',
				'pt' => 'O tamanho de quem chega ao fim. Seriam precisas %d perdas totais para rebentar.',
			),
			'rev_warn_25'       => array(
				'en' => 'Concentrated. %d wrong calls in a row and the account is gone — and wrong calls happen.',
				'pt' => 'Concentrado. %d escolhas erradas seguidas e a conta acaba — e escolhas erradas acontecem.',
			),
			'rev_warn_50'       => array(
				'en' => 'This is not investing. One fraud and half the account goes at once.',
				'pt' => 'Isto não é investir. Uma fraude e metade da conta vai de uma vez.',
			),
			'rev_three_lines'   => array(
				'en' => 'The three lines',
				'pt' => 'As três linhas',
			),
			'rev_line_you'      => array(
				'en' => 'Your decision',
				'pt' => 'A tua decisão',
			),
			'rev_line_passed'   => array(
				'en' => 'If you had passed',
				'pt' => 'Se tivesses passado',
			),
			'rev_line_index'    => array(
				'en' => 'The index, over the same period',
				'pt' => 'O índice, no mesmo período',
			),
			'rev_line_index_ft' => array(
				'en' => 'five years, dividends included',
				'pt' => 'cinco anos, dividendos incluídos',
			),
			'rev_intact'        => array(
				'en' => 'capital intact',
				'pt' => 'capital intacto',
			),
			'rev_title_win'     => array(
				'en' => 'That one paid.',
				'pt' => 'Essa valeu a pena.',
			),
			'rev_title_loss'    => array(
				'en' => 'Expensive, but it teaches.',
				'pt' => 'Caro, mas ensina.',
			),
			'rev_title_pass_ok' => array(
				'en' => 'Well passed.',
				'pt' => 'Bem passado.',
			),
			'rev_title_pass'    => array(
				'en' => 'You stayed out.',
				'pt' => 'Ficaste de fora.',
			),
			'rev_source'        => array(
				'en' => 'Source',
				'pt' => 'Fonte',
			),
			'rev_source_note'   => array(
				'en' => 'Figures checked against a published source, accessed %s.',
				'pt' => 'Números verificados contra uma fonte publicada, consultada em %s.',
			),
			'rev_crowd_passed'  => array(
				'en' => 'Players who passed on this one',
				'pt' => 'Jogadores que passaram nesta',
			),
			'rev_crowd_in'      => array(
				'en' => 'Players who put money behind it',
				'pt' => 'Jogadores que puseram dinheiro atrás dela',
			),
			'rev_dead_title'    => array(
				'en' => 'Account blown',
				'pt' => 'Conta rebentada',
			),
			'rev_dead_avg'      => array(
				'en' => 'Average position',
				'pt' => 'Posição média',
			),
			'rev_dead_traps'    => array(
				'en' => 'Traps avoided',
				'pt' => 'Armadilhas evitadas',
			),
			'rev_dead_index'    => array(
				'en' => 'The index player',
				'pt' => 'O jogador índice',
			),
			'rev_historical'    => array(
				'en' => 'Educational simulation built on verified historical cases. Not a recommendation, and not a view on this company today.',
				'pt' => 'Simulação educativa construída sobre casos históricos verificados. Não é recomendação, nem é uma opinião sobre esta empresa hoje.',
			),
		);
	}

	/**
	 * Leaderboards, profile and sharing.
	 *
	 * The daily board ranks by a risk-normalised score, not raw profit, and
	 * the column says so — a board that rewarded raw profit next to a public
	 * average-risk chart would teach players to size up to climb it, which is
	 * the opposite of what the game is for.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function social(): array {
		return array(
			'board_title'      => array(
				'en' => 'Leaderboard',
				'pt' => 'Classificação',
			),
			'board_today'      => array(
				'en' => 'Today',
				'pt' => 'Hoje',
			),
			'board_survival'   => array(
				'en' => 'Survival',
				'pt' => 'Sobrevivência',
			),
			'board_score_head' => array(
				'en' => 'Result per 1% risked',
				'pt' => 'Resultado por 1% arriscado',
			),
			'board_score_note' => array(
				'en' => 'The daily board is scored per unit of risk taken, so a bigger position is not a shortcut up it.',
				'pt' => 'A tabela diária é pontuada por unidade de risco assumido, para que uma posição maior não seja um atalho para subir.',
			),
			'board_privacy'    => array(
				'en' => 'Nicknames only — no real names and no personal data on the boards.',
				'pt' => 'Só alcunhas — sem nomes reais e sem dados pessoais nas tabelas.',
			),
			'board_reset'      => array(
				'en' => "The daily board resets when the day does, at 00:00 IST.",
				'pt' => 'A tabela diária reinicia quando o dia vira, às 00:00 IST.',
			),
			'board_empty'      => array(
				'en' => 'Nobody has finished today yet',
				'pt' => 'Ainda ninguém terminou hoje',
			),
			'board_empty_body' => array(
				'en' => 'The board fills as players close their day. Play and you take the first slot.',
				'pt' => 'A tabela enche-se à medida que os jogadores fecham o dia. Joga e ficas com o primeiro lugar.',
			),
			'board_you'        => array(
				'en' => 'You',
				'pt' => 'Tu',
			),
			'profile_title'    => array(
				'en' => 'Your run',
				'pt' => 'A tua corrida',
			),
			'profile_risk'     => array(
				'en' => 'Your average risk per trade',
				'pt' => 'O teu risco médio por operação',
			),
			'profile_risk_hint' => array(
				'en' => 'This line trending down is the whole point of the game.',
				'pt' => 'Esta linha a descer é todo o objetivo do jogo.',
			),
			'profile_calendar' => array(
				'en' => 'Last 28 days',
				'pt' => 'Últimos 28 dias',
			),
			'profile_badges'   => array(
				'en' => 'Badges',
				'pt' => 'Distintivos',
			),
			'profile_win_rate' => array(
				'en' => 'Win rate',
				'pt' => 'Taxa de acerto',
			),
			'profile_win_note' => array(
				'en' => 'not the point',
				'pt' => 'não é o que interessa',
			),
			'share_no_spoiler' => array(
				'en' => 'no spoilers',
				'pt' => 'sem spoilers',
			),
			'share_day_done'   => array(
				'en' => 'Day %d done.',
				'pt' => 'Dia %d feito.',
			),
			'share_blown'      => array(
				'en' => 'Blew the account on day %d.',
				'pt' => 'Rebentei a conta no dia %d.',
			),
			'share_footer'     => array(
				'en' => 'A HowToInvest game · virtual money only',
				'pt' => 'Um jogo da HowToInvest · só dinheiro virtual',
			),
		);
	}

	/**
	 * Nickname, the magic link and the newsletter box.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function account(): array {
		return array(
			'nick_title'      => array(
				'en' => 'Pick a name for the board',
				'pt' => 'Escolhe um nome para a tabela',
			),
			'nick_note'       => array(
				'en' => 'Public on the boards. A handle, not your real name.',
				'pt' => 'Público nas tabelas. Uma alcunha, não o teu nome real.',
			),
			'nick_taken'      => array(
				'en' => 'That one is taken. Try another.',
				'pt' => 'Essa já está ocupada. Tenta outra.',
			),
			'nick_invalid'    => array(
				'en' => 'Three to twenty-four characters: letters, digits, hyphens and underscores.',
				'pt' => 'Entre três e vinte e quatro caracteres: letras, dígitos, hífenes e sublinhados.',
			),
			'link_title'      => array(
				'en' => 'Keep your streak across devices',
				'pt' => 'Manter a série entre dispositivos',
			),
			'link_body'       => array(
				'en' => 'We send you a link. No password. Your email is the only personal data stored, and you can delete it whenever you like.',
				'pt' => 'Enviamos-te um link. Sem palavra-passe. O email é o único dado pessoal guardado, e podes apagá-lo quando quiseres.',
			),
			'link_send'       => array(
				'en' => 'Send my link',
				'pt' => 'Enviar o meu link',
			),
			'link_sent'       => array(
				'en' => 'Check your inbox',
				'pt' => 'Vê a tua caixa de entrada',
			),
			'link_sent_body'  => array(
				'en' => 'If that address can receive it, a link is on its way. It works for fifteen minutes.',
				'pt' => 'Se esse endereço puder recebê-lo, vai um link a caminho. Funciona durante quinze minutos.',
			),
			'link_resend'     => array(
				'en' => 'Send it again',
				'pt' => 'Enviar outra vez',
			),
			'link_bad_email'  => array(
				'en' => "That address does not look right — check it and try again.",
				'pt' => 'Esse endereço não parece certo — verifica-o e tenta outra vez.',
			),
			'link_skip'       => array(
				'en' => 'Keep playing without an account',
				'pt' => 'Continuar a jogar sem conta',
			),
			'news_optin'      => array(
				'en' => 'Also send me the HowToInvest newsletter. Separate from the link above, and you can leave at any time.',
				'pt' => 'Enviem-me também a newsletter da HowToInvest. É separada do link acima, e podes sair quando quiseres.',
			),
			'forget_me'       => array(
				'en' => 'Delete my game data',
				'pt' => 'Apagar os meus dados de jogo',
			),
			'forget_note'     => array(
				'en' => 'Removes your run, your results and your nickname from this site. It cannot be undone.',
				'pt' => 'Remove a tua corrida, os teus resultados e a tua alcunha deste site. Não é reversível.',
			),
		);
	}

	/**
	 * Loading, offline, error and empty states.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function states(): array {
		return array(
			'st_loading'      => array(
				'en' => 'Loading',
				'pt' => 'A carregar',
			),
			'st_offline'      => array(
				'en' => 'Boards are offline',
				'pt' => 'As tabelas estão offline',
			),
			'st_offline_body' => array(
				'en' => 'Your run is safe on this device — only the ranking needs the network.',
				'pt' => 'A tua corrida está segura neste dispositivo — só a classificação precisa de rede.',
			),
			'st_retry'        => array(
				'en' => 'Try again',
				'pt' => 'Tentar outra vez',
			),
			'st_error'        => array(
				'en' => 'Something went wrong. Nothing was recorded — try again.',
				'pt' => 'Alguma coisa correu mal. Não ficou nada registado — tenta outra vez.',
			),
			'st_rate_limited' => array(
				'en' => 'That was a lot of requests at once. Give it a minute.',
				'pt' => 'Foram muitos pedidos de uma vez. Dá-lhe um minuto.',
			),
			'st_no_content'   => array(
				'en' => 'No challenge is published for today yet.',
				'pt' => 'Ainda não há desafio publicado para hoje.',
			),
		);
	}

	/**
	 * The eight badges.
	 *
	 * Every one of them rewards staying in the game rather than being right:
	 * days survived, days passed, trades kept small, and the average risk
	 * coming down week on week. There is deliberately no badge for a big win,
	 * because a badge is a suggestion about what to do next.
	 *
	 * "Blown once" is here on purpose and is not framed as a failure. Nearly
	 * every account dies; a game about survival that hid that would be teaching
	 * the wrong thing on the day it matters most.
	 *
	 * The keys match Scoring::badges(); the front end renders only badges whose
	 * name exists here, so a new badge stays invisible until it has words.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function badges(): array {
		return array(
			'badge_first_chart'      => array(
				'en' => 'First chart',
				'pt' => 'Primeiro gráfico',
			),
			'badge_first_chart_note' => array(
				'en' => 'You opened a day and made a call on it.',
				'pt' => 'Abriste um dia e tomaste uma decisão sobre ele.',
			),
			'badge_week'             => array(
				'en' => 'A week alive',
				'pt' => 'Uma semana vivo',
			),
			'badge_week_note'        => array(
				'en' => 'Seven days in a row with the account still open.',
				'pt' => 'Sete dias seguidos com a conta ainda aberta.',
			),
			'badge_month'            => array(
				'en' => 'A month alive',
				'pt' => 'Um mês vivo',
			),
			'badge_month_note'       => array(
				'en' => 'Twenty-eight days. Most accounts do not get here.',
				'pt' => 'Vinte e oito dias. A maioria das contas não chega aqui.',
			),
			'badge_patience'         => array(
				'en' => 'Sat it out',
				'pt' => 'Ficaste de fora',
			),
			'badge_patience_note'    => array(
				'en' => 'Passed often enough that passing is clearly a decision you make.',
				'pt' => 'Passaste vezes suficientes para passar ser claramente uma decisão tua.',
			),
			'badge_small_size'       => array(
				'en' => 'Small and steady',
				'pt' => 'Pequeno e constante',
			),
			'badge_small_size_note'  => array(
				'en' => 'A long run of decisions at one percent or under.',
				'pt' => 'Uma série longa de decisões a um por cento ou menos.',
			),
			'badge_de_risked'        => array(
				'en' => 'Sizing down',
				'pt' => 'A reduzir',
			),
			'badge_de_risked_note'   => array(
				'en' => 'Your average risk is lower than it was. This is the one that matters.',
				'pt' => 'O teu risco médio está mais baixo do que estava. É este que interessa.',
			),
			'badge_blown'            => array(
				'en' => 'Blown once',
				'pt' => 'Rebentaste uma vez',
			),
			'badge_blown_note'       => array(
				'en' => 'The account went. Almost every account does — what changes afterwards is the point.',
				'pt' => 'A conta foi-se. Quase todas vão — o que muda depois é que interessa.',
			),
			'badge_survivor'         => array(
				'en' => 'Still here',
				'pt' => 'Ainda aqui',
			),
			'badge_survivor_note'    => array(
				'en' => 'Above where you started, after a run long enough to mean something.',
				'pt' => 'Acima de onde começaste, ao fim de uma corrida longa o suficiente para significar alguma coisa.',
			),
			'badge_locked'           => array(
				'en' => 'Not yet',
				'pt' => 'Ainda não',
			),
		);
	}

	/**
	 * Structural labels: the no-JavaScript notice, the row headings of the
	 * chart's text equivalent, and the leaderboard column names.
	 *
	 * The five `lbl_*` rows are what a screen reader reads instead of the
	 * canvas, so they are part of the accessible result rather than decoration
	 * — the same words a sighted player sees on the outcome card.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function labels(): array {
		return array(
			'needs_js'     => array(
				'en' => 'This game needs JavaScript to run. The rules, the lesson and the disclaimer are on this page either way — turn JavaScript on to play today\'s challenge.',
				'pt' => 'Este jogo precisa de JavaScript para funcionar. As regras, a lição e o aviso estão nesta página de qualquer forma — ativa o JavaScript para jogar o desafio de hoje.',
			),
			'lbl_entry'    => array(
				'en' => 'Entry',
				'pt' => 'Entrada',
			),
			'lbl_stop'     => array(
				'en' => 'Stop',
				'pt' => 'Stop de perda',
			),
			'lbl_target'   => array(
				'en' => 'Target',
				'pt' => 'Alvo',
			),
			'lbl_outcome'  => array(
				'en' => 'Outcome',
				'pt' => 'Desfecho',
			),
			'lbl_pnl'      => array(
				'en' => 'Result in dollars',
				'pt' => 'Resultado em dólares',
			),
			'lbl_rank'     => array(
				'en' => 'Position',
				'pt' => 'Posição',
			),
			'lbl_player'   => array(
				'en' => 'Player',
				'pt' => 'Jogador',
			),
			'lbl_capital'  => array(
				'en' => 'Capital',
				'pt' => 'Capital',
			),
		);
	}
}
