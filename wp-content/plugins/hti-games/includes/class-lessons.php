<?php
/**
 * What each kind of day actually taught, in English and Portuguese.
 *
 * Not `__()`, for the same reason as HTI\Games\Strings: the site runs the
 * `pt_PT_ao90` locale against `pt_PT` translation files and WordPress does not
 * fall back between them, so a missing PT translation renders in English
 * without warning anybody. Both languages sit side by side in one table, and
 * tests/test-lessons.php fails if either is empty.
 *
 * WHAT A LESSON IS ALLOWED TO BE ABOUT. Position size and survival. Never
 * direction, and never the day's direction dressed up as a rule — "the trend
 * was your friend" is a sentence about a chart that has already happened, and
 * a game that hands it to the player teaches them to look for it next time.
 * The one thing the outcome window can honestly say is what the size did to
 * the account, because that part was decided before the candles moved and is
 * true whichever way they moved.
 *
 * So the awkward one is a win. A win at a heavy tier is the sizing getting
 * away with it, not a read being right, and the lesson says so plainly rather
 * than congratulating anybody. The alternative is a game that pays out on
 * recklessness and then explains that recklessness worked.
 *
 * TWENTY PER CLASS, AND THEY PROGRESS. A daily game shows the same class of
 * day roughly every third day, so eight sentences per class became furniture
 * inside a month and furniture is not read. Twenty per class is two months
 * before a repeat — and, more usefully, room for the rotation to walk a
 * curriculum instead of shuffling synonyms:
 *
 *   01–06  the mechanic. What a stop is, what one R is, why the levels are
 *          the same distance every day, why a bar that touches both is booked
 *          as a loss, what passing actually costs.
 *   07–13  the arithmetic. How many losses in a row each tier can absorb, why
 *          a six-loss streak is ordinary, what a halved account has to do to
 *          get level, why doubling the stake halves the runway.
 *   14–20  the behaviour. Revenge sizing after a loss, the tier that looks
 *          reasonable only because the last day paid, the win at a heavy tier
 *          being the most dangerous outcome in the game, and passing feeling
 *          like nothing while being the cheapest decision on the screen.
 *
 * The three classes run the same arc in parallel, because a player who only
 * ever meets ambiguous days should still be walked through all three stages.
 *
 * NUMBERS ARE NEVER TYPED. A lesson that needs a figure carries `%d` and
 * declares the risk tiers behind it in `risk`; all() fills them from
 * STC_Engine::losses_to_ruin(), which is the same function the tier screen
 * warns with. The prototype's hand-written counts were out by roughly a
 * factor of four in both directions, on a page whose entire argument is
 * arithmetic — so the arithmetic is computed, in one place, or it is not
 * written at all. table() is the raw table for anyone who needs to see the
 * placeholders; everything else reads all().
 *
 * STC_Engine is loaded before this file in the plugin's class map, and the
 * require below is for the pure-PHP test harness, which loads classes one at
 * a time. Same guarded-require pattern as class-stc-generator.php.
 *
 * The voice is the site's — calm, second person, conditional, no urgency, no
 * promises, no imperatives, nothing that reads as an instruction to trade, and
 * nothing that suggests the outcome could have been called in advance. The
 * game is built so that direction is frequently unknowable; a lesson that
 * implies otherwise is teaching the opposite of the thing it sits under.
 * See .claude/skills/brand-voice/SKILL.md.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-stc-engine.php';

/**
 * The bilingual lesson library, keyed by lesson id. Pure.
 */
class Lessons {

	/**
	 * Languages this table is complete in.
	 *
	 * Mirrors Strings::LANGS deliberately rather than importing it: this file
	 * is loaded only by the CLI generator and has no business pulling the
	 * whole copy table in behind it. tests/test-lessons.php asserts the two
	 * lists agree, so the duplication cannot drift.
	 */
	public const LANGS = array( 'en', 'pt' );

	/**
	 * The class a lesson is drawn from when the caller asks for one that does
	 * not exist.
	 *
	 * `ambiguous` and not `reasonable`, because the ambiguous lessons are the
	 * ones that are true on any day at all — they are about the size, and the
	 * size was chosen before the chart resolved into anything.
	 */
	public const FALLBACK_CLASS = 'ambiguous';

	/**
	 * Every lesson with its figures filled in, class => list of
	 * { id, risk, en, pt }.
	 *
	 * This is what ships. Memoised because losses_to_ruin() walks a
	 * compounding balance down to the floor — 460 iterations at the smallest
	 * tier — and the generator asks for the whole library once per scenario.
	 *
	 * @return array<string,array<int,array{id:string,risk:array<int,int>,en:string,pt:string}>>
	 */
	public static function all(): array {
		static $filled = null;

		if ( null === $filled ) {
			$filled = array();
			foreach ( self::table() as $class => $list ) {
				$filled[ $class ] = array_map( array( __CLASS__, 'fill' ), $list );
			}
		}

		return $filled;
	}

	/**
	 * The library exactly as it is written, `%d` placeholders and all.
	 *
	 * Only tests and anyone auditing the copy should need this — a caller
	 * that renders it puts a literal "%d" under somebody's chart.
	 *
	 * @return array<string,array<int,array{id:string,risk:array<int,int>,en:string,pt:string}>>
	 */
	public static function table(): array {
		return array(
			'reasonable' => self::reasonable(),
			'ambiguous'  => self::ambiguous(),
			'trap'       => self::trap(),
		);
	}

	/**
	 * One lesson for a class, chosen deterministically, figures filled.
	 *
	 * The index wraps, and a negative index wraps the same way a positive one
	 * does, so a caller counting backwards through a library gets a lesson
	 * rather than a warning.
	 *
	 * @param string $class 'reasonable', 'ambiguous' or 'trap'; anything else falls back to FALLBACK_CLASS.
	 * @param int    $index Rotation position.
	 * @return array{id:string,risk:array<int,int>,en:string,pt:string}
	 */
	public static function for_class( string $class, int $index ): array {
		$all  = self::all();
		$list = $all[ $class ] ?? $all[ self::FALLBACK_CLASS ];
		$n    = count( $list );

		// PHP's % keeps the sign of the dividend, so -1 % 20 is -1 and would
		// index off the front of the list.
		$at = ( ( $index % $n ) + $n ) % $n;

		return $list[ $at ];
	}

	/**
	 * Every lesson id in the library.
	 *
	 * @return array<int,string>
	 */
	public static function ids(): array {
		$out = array();

		foreach ( self::table() as $list ) {
			foreach ( $list as $lesson ) {
				$out[] = $lesson['id'];
			}
		}

		return $out;
	}

	/**
	 * How many losing trades in a row a tier absorbs before the floor.
	 *
	 * The one number the whole library argues from, and it is asked of the
	 * engine rather than remembered: 460 / 230 / 114 / 45 / 22 / 9 for the six
	 * offered tiers, and whatever they become if a tier or the floor is ever
	 * changed in Config.
	 *
	 * @param int $risk_bp Risk tier in basis points.
	 */
	public static function ruin( int $risk_bp ): int {
		static $cache = array();

		if ( ! isset( $cache[ $risk_bp ] ) ) {
			$cache[ $risk_bp ] = STC_Engine::losses_to_ruin( $risk_bp );
		}

		return $cache[ $risk_bp ];
	}

	/**
	 * Substitute a lesson's `%d` slots with the ruin count of each tier it
	 * declares, in order.
	 *
	 * A lesson with no `risk` is returned untouched rather than run through
	 * vsprintf(), so a stray percent sign in ordinary prose can never be read
	 * as a format specifier. (No lesson has one — the copy spells percentages
	 * out in words — and tests/test-lessons.php keeps it that way.)
	 *
	 * @param array{id:string,risk?:array<int,int>,en:string,pt:string} $lesson One raw lesson.
	 * @return array{id:string,risk:array<int,int>,en:string,pt:string}
	 */
	private static function fill( array $lesson ): array {
		$risk = array_map( 'intval', (array) ( $lesson['risk'] ?? array() ) );

		$lesson['risk'] = $risk;

		if ( array() === $risk ) {
			return $lesson;
		}

		$numbers = array_map( array( __CLASS__, 'ruin' ), $risk );

		foreach ( self::LANGS as $lang ) {
			$lesson[ $lang ] = vsprintf( (string) $lesson[ $lang ], $numbers );
		}

		return $lesson;
	}

	/**
	 * Days where the chart was legible and the legible reading was paid.
	 *
	 * The hardest set to write, because the player just won and the honest
	 * thing to say is that winning proved nothing about the size. Every one of
	 * these separates the two: the read is a comment on the chart, the tier is
	 * a comment on the account, and only one of them was tested today. The
	 * back half of the list is the reason the set exists at all — a win at a
	 * heavy tier is the moment a player learns the wrong thing, and these are
	 * the only sentences that get to interrupt it.
	 *
	 * @return array<int,array{id:string,risk:array<int,int>,en:string,pt:string}>
	 */
	private static function reasonable(): array {
		return array(
			// 01–06 · the mechanic.
			array(
				'id'   => 'stc_lesson_reasonable_01',
				'risk' => array(),
				'en'   => 'The chart was legible and the legible reading was paid. That is a comment on the chart, not on the size — the same call with four times the money behind it would have been the same call.',
				'pt'   => 'O gráfico era legível e a leitura legível foi paga. Isso diz alguma coisa sobre o gráfico, não sobre o tamanho — a mesma leitura com quatro vezes o dinheiro atrás teria sido a mesma leitura.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_02',
				'risk' => array(),
				'en'   => 'The stop sat one ATR from the entry and the target one and a half the other way, the same distances as every other day. Nothing on this chart moved them; the only number you set was the size.',
				'pt'   => 'O stop ficou a um ATR da entrada e o alvo a um e meio para o outro lado, as mesmas distâncias de todos os outros dias. Nada neste gráfico as mexeu; o único número que definiste foi o tamanho.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_03',
				'risk' => array(),
				'en'   => 'One R is whatever the stop would have taken — the amount you put at risk. A day that reaches the target gives back one and a half of it, on whatever base the tier set.',
				'pt'   => 'Um R é aquilo que o stop teria levado — o valor que puseste em risco. Um dia que chega ao alvo devolve uma vez e meia isso, sobre a base que o escalão definiu.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_04',
				'risk' => array(),
				'en'   => 'A candle that touches both levels is booked as a stop, because nothing in a bar says which price came first. It did not come up today, and the size was still the part of the day you decided.',
				'pt'   => 'Uma vela que toca nos dois níveis é registada como stop, porque nada numa barra diz que preço veio primeiro. Hoje não chegou a acontecer, e o tamanho continuou a ser a parte do dia que decidiste.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_05',
				'risk' => array(),
				'en'   => 'A day that works is the cheapest day to look at what you risked, because looking costs nothing while nothing hurts.',
				'pt'   => 'Um dia que corre bem é o dia mais barato para olhar para o que arriscaste, porque olhar não custa nada enquanto nada dói.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_06',
				'risk' => array(),
				'en'   => 'Passing a readable day costs you the trade and nothing else. It is a small price, and it is the same small price every single time.',
				'pt'   => 'Passar um dia legível custa-te a operação e mais nada. É um preço pequeno, e é o mesmo preço pequeno de todas as vezes.',
			),

			// 07–13 · the arithmetic.
			array(
				'id'   => 'stc_lesson_reasonable_07',
				'risk' => array(),
				'en'   => 'The target was worth one and a half times what the stop would have cost. That arithmetic only helps an account that is still around for enough of these.',
				'pt'   => 'O alvo valia uma vez e meia o que o stop teria custado. Essa aritmética só ajuda uma conta que ainda cá esteja para ter muitas destas.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_08',
				'risk' => array( 50, 2500 ),
				'en'   => 'At half a percent an account absorbs %d losing trades in a row before it reaches the floor; at twenty-five percent it absorbs %d. Today\'s result moved neither number.',
				'pt'   => 'A meio por cento, uma conta absorve %d operações perdedoras seguidas antes de chegar ao chão; a vinte e cinco por cento absorve %d. O resultado de hoje não mexeu em nenhum destes números.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_09',
				'risk' => array( 100 ),
				'en'   => 'One percent a trade leaves room for %d losses in a row. A day that pays adds none of that room back — it only postpones the day you find out how much of it is left.',
				'pt'   => 'Um por cento por operação deixa espaço para %d perdas seguidas. Um dia que paga não devolve espaço nenhum — só adia o dia em que descobres quanto dele sobra.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_10',
				'risk' => array(),
				'en'   => 'The tier is a share of the balance, and the balance just went up, so the same button now puts more dollars at risk than it did last week. The percentage held still; the money behind it did not.',
				'pt'   => 'O escalão é uma parte do saldo, e o saldo acabou de subir, por isso o mesmo botão põe agora mais dólares em risco do que na semana passada. A percentagem ficou quieta; o dinheiro atrás dela não.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_11',
				'risk' => array(),
				'en'   => 'Same chart, same call, one percent instead of twenty-five: the story is identical and the account is not.',
				'pt'   => 'Mesmo gráfico, mesma leitura, um por cento em vez de vinte e cinco: a história é igual e a conta não é.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_12',
				'risk' => array( 500, 100 ),
				'en'   => 'Five percent a trade survives %d losses in a row; one percent survives %d. Both tiers were on this screen, and only the second one is a plan for a bad month.',
				'pt'   => 'Cinco por cento por operação aguenta %d perdas seguidas; um por cento aguenta %d. Os dois escalões estavam neste ecrã, e só o segundo é um plano para um mês mau.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_13',
				'risk' => array(),
				'en'   => 'Winning days are not evidence that the size was sensible. They are evidence that the size was not tested.',
				'pt'   => 'Dias de ganho não são prova de que o tamanho era sensato. São prova de que o tamanho não foi testado.',
			),

			// 14–20 · the behaviour.
			array(
				'id'   => 'stc_lesson_reasonable_14',
				'risk' => array(),
				'en'   => 'A win at a heavy tier is the sizing getting away with it, not a read being right. The account survived the call today; the size was never tested.',
				'pt'   => 'Um ganho num escalão pesado é o tamanho a safar-se, não a leitura a acertar. Hoje a conta sobreviveu à leitura; o tamanho não chegou a ser testado.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_15',
				'risk' => array(),
				'en'   => 'A heavy tier that pays is the most expensive good day in the game, because what it leaves behind is the belief that the tier works. It got away with it once, in front of you.',
				'pt'   => 'Um escalão pesado que paga é o dia bom mais caro do jogo, porque o que deixa atrás é a ideia de que o escalão resulta. Safou-se uma vez, à tua frente.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_16',
				'risk' => array(),
				'en'   => 'After a win the tier above looks reasonable, and it looks reasonable for the same reason it looked heavy yesterday: nothing about it changed except the mood you are reading it in.',
				'pt'   => 'Depois de um ganho, o escalão acima parece razoável, e parece razoável pela mesma razão por que ontem parecia pesado: nada nele mudou tirando a disposição com que o lês.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_17',
				'risk' => array(),
				'en'   => 'A trend that keeps going is the most ordinary thing a chart does, and still not something an account can lean on. How hard you lean is what the tier decides.',
				'pt'   => 'Uma tendência que continua é a coisa mais banal que um gráfico faz, e mesmo assim não é coisa em que uma conta se possa encostar. O quanto te encostas é o que o escalão decide.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_18',
				'risk' => array(),
				'en'   => 'A run of good days makes an account look like proof of something. It is a sample, and a short one; the size is the part of it still open to a decision tomorrow.',
				'pt'   => 'Uma série de dias bons faz uma conta parecer prova de alguma coisa. É uma amostra, e curta; o tamanho é a parte dela que ainda está aberta a uma decisão amanhã.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_19',
				'risk' => array(),
				'en'   => 'Sizing up while it is working is the ordinary way a good month becomes a bad one: the heavier tier arrives just as the run of readable days ends, and the account meets it at full stretch.',
				'pt'   => 'Aumentar o tamanho enquanto está a resultar é a forma banal de um mês bom se tornar mau: o escalão mais pesado chega mesmo quando a série de dias legíveis acaba, e a conta encontra-o esticada ao máximo.',
			),
			array(
				'id'   => 'stc_lesson_reasonable_20',
				'risk' => array(),
				'en'   => 'Looking back, passing on a day like this cost one trade. That is the whole price, and it is the same price on the days the chart turns instead — which is why the size, and not the call, is what carries a run.',
				'pt'   => 'Olhando para trás, passar num dia destes custou uma operação. É esse o preço todo, e é o mesmo preço nos dias em que o gráfico vira — e é por isso que é o tamanho, e não a leitura, que aguenta uma corrida.',
			),
		);
	}

	/**
	 * Days that resolved nothing in either direction.
	 *
	 * The class the whole game is really about, and the one a daily player
	 * meets most often. Nobody was right, nobody was punished, and the account
	 * still moved — by exactly as much as the tier said it would before the
	 * first candle. Neither level was reached inside the window, so the day
	 * settled at the last close for a fraction of an R; the fraction is small
	 * and the base is not, which is the whole of what these say.
	 *
	 * @return array<int,array{id:string,risk:array<int,int>,en:string,pt:string}>
	 */
	private static function ambiguous(): array {
		return array(
			// 01–06 · the mechanic.
			array(
				'id'   => 'stc_lesson_ambiguous_01',
				'risk' => array(),
				'en'   => 'Forty candles and nothing resolved. Whichever side you took, direction was never the variable today — the size was.',
				'pt'   => 'Quarenta velas e nada se resolveu. Qualquer que fosse o lado, hoje a direção nunca foi a variável — o tamanho foi.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_02',
				'risk' => array(),
				'en'   => 'Neither the stop nor the target was reached inside the window, so the day settled where the last candle closed — a fraction of an R, against whatever base the tier set.',
				'pt'   => 'Nem o stop nem o alvo foram alcançados dentro da janela, por isso o dia fechou onde fechou a última vela — uma fração de um R, sobre a base que o escalão definiu.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_03',
				'risk' => array(),
				'en'   => 'You did not read this one wrong. There was nothing to read. What is left of the day is how much of the account was in the room.',
				'pt'   => 'Não leste isto mal. Não havia nada para ler. O que sobra do dia é quanto da conta estava na sala.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_04',
				'risk' => array(),
				'en'   => 'Most days look like this one. Not much happened, and the only number that moved the account was the one you chose before it started.',
				'pt'   => 'A maioria dos dias é assim. Não aconteceu grande coisa, e o único número que mexeu com a conta foi o que escolheste antes de isto começar.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_05',
				'risk' => array(),
				'en'   => 'A market that answers nothing still charges you for standing in it. What it charges is the size.',
				'pt'   => 'Um mercado que não responde a nada continua a cobrar-te por estares lá dentro. O que cobra é o tamanho.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_06',
				'risk' => array(),
				'en'   => 'Sitting a day like this out costs nothing and gives up nothing, which is why it is easy to forget it was on the table.',
				'pt'   => 'Ficar de fora num dia destes não custa nada e não abdica de nada, e é por isso que é fácil esquecer que estava em cima da mesa.',
			),

			// 07–13 · the arithmetic.
			array(
				'id'   => 'stc_lesson_ambiguous_07',
				'risk' => array( 200 ),
				'en'   => 'Two percent a trade leaves room for %d losses in a row, and a day like this spends a sliver of that room whichever way it drifted. The room is the account; the tier is the rate you spend it at.',
				'pt'   => 'Dois por cento por operação deixa espaço para %d perdas seguidas, e um dia destes gasta uma lasca desse espaço para o lado para que tenha derivado. O espaço é a conta; o escalão é o ritmo a que o gastas.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_08',
				'risk' => array( 100, 1000 ),
				'en'   => 'One percent leaves %d losses of runway; ten percent leaves %d. On a day that decides nothing, that gap is still the only thing separating two players.',
				'pt'   => 'Um por cento deixa %d perdas de margem; dez por cento deixa %d. Num dia que não decide nada, essa diferença continua a ser a única coisa a separar dois jogadores.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_09',
				'risk' => array(),
				'en'   => 'Six losing trades in a row is not a rare thing to meet across a hundred days; it is the sort of run any long sequence throws up. The tier decides whether meeting it is an inconvenience or the end.',
				'pt'   => 'Seis operações perdedoras seguidas não é coisa rara de encontrar ao longo de cem dias; é o tipo de série que qualquer sequência longa produz. O escalão decide se encontrá-la é um incómodo ou o fim.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_10',
				'risk' => array(),
				'en'   => 'An account that has halved has to double what is left to stand where it started. Losing and recovering are not the same arithmetic, and the tier is what sets the distance between them.',
				'pt'   => 'Uma conta que caiu para metade tem de duplicar o que resta para ficar onde começou. Perder e recuperar não são a mesma aritmética, e é o escalão que define a distância entre as duas.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_11',
				'risk' => array(),
				'en'   => 'The floor sits at a tenth of the starting balance, so a run ends long before the money does. What decides how fast that gap closes is the share of the account you put out each day.',
				'pt'   => 'O chão fica a um décimo do saldo inicial, por isso uma corrida acaba muito antes do dinheiro. O que decide a rapidez com que essa distância se fecha é a parte da conta que pões lá fora todos os dias.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_12',
				'risk' => array(),
				'en'   => 'Doubling the stake does not double the damage. It halves the number of losses the account can take before the floor, which is a different and much worse thing. The tier row is a scale of runway, not of enthusiasm.',
				'pt'   => 'Duplicar a aposta não duplica o estrago. Corta para metade o número de perdas que a conta aguenta antes do chão, o que é coisa diferente e bastante pior. A fila de escalões é uma escala de margem, não de entusiasmo.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_13',
				'risk' => array( 2500 ),
				'en'   => 'At twenty-five percent the whole runway is %d losing trades. A day like this does not spend a full loss, it spends a fraction of one — but it is a fraction of something very short.',
				'pt'   => 'A vinte e cinco por cento, a margem toda são %d operações perdedoras. Um dia destes não gasta uma perda inteira, gasta uma fração dela — mas é uma fração de uma coisa muito curta.',
			),

			// 14–20 · the behaviour.
			array(
				'id'   => 'stc_lesson_ambiguous_14',
				'risk' => array(),
				'en'   => 'On a day that goes nowhere a small position is boring and a large one is still large. Only one of those becomes a problem later.',
				'pt'   => 'Num dia que não vai a lado nenhum uma posição pequena é aborrecida e uma grande continua a ser grande. Só uma delas se torna um problema mais à frente.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_15',
				'risk' => array(),
				'en'   => 'A day that decides nothing leaves you nothing to talk about, which is the usual reason a player sizes up on the next one. Boredom is a feeling about the chart; the tier is a decision about the account.',
				'pt'   => 'Um dia que não decide nada não te deixa nada para contar, e é essa a razão habitual por que um jogador aumenta o tamanho no seguinte. O tédio é um sentimento sobre o gráfico; o escalão é uma decisão sobre a conta.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_16',
				'risk' => array(),
				'en'   => 'The tier that follows a loss is the one worth watching. Going up a step to win it back is the same account making the same bet twice, with less of itself left the second time.',
				'pt'   => 'O escalão que vem a seguir a uma perda é o que vale a pena vigiar. Subir um degrau para recuperar é a mesma conta a fazer a mesma aposta duas vezes, com menos de si própria na segunda.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_17',
				'risk' => array(),
				'en'   => 'No edge, no punishment, no reward. The sizing decision was the entire day.',
				'pt'   => 'Sem vantagem, sem castigo, sem recompensa. A decisão de tamanho foi o dia inteiro.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_18',
				'risk' => array(),
				'en'   => 'Right and wrong barely showed up here. The account still noticed the difference between half a percent and ten.',
				'pt'   => 'Certo e errado quase não apareceram aqui. A conta, essa, notou a diferença entre meio por cento e dez.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_19',
				'risk' => array(),
				'en'   => 'A tier settled once and left alone is a decision taken in the calm. A tier chosen fresh each morning is a decision taken in whatever mood the last result left behind.',
				'pt'   => 'Um escalão definido uma vez e deixado em paz é uma decisão tomada com calma. Um escalão escolhido de novo cada manhã é uma decisão tomada na disposição que o último resultado deixou.',
			),
			array(
				'id'   => 'stc_lesson_ambiguous_20',
				'risk' => array(),
				'en'   => 'Passing is the cheapest decision on the screen and the one that feels like the least, because nothing happens afterwards to prove it was worth anything. On a day this quiet, nothing happening was the entire result.',
				'pt'   => 'Passar é a decisão mais barata do ecrã e a que sabe a menos, porque depois não acontece nada que prove que valeu alguma coisa. Num dia tão parado, não acontecer nada foi o resultado todo.',
			),
		);
	}

	/**
	 * Days where the legible reading was punished and the opposite one was not
	 * rewarded either.
	 *
	 * Passing was the best available answer, which is the least satisfying
	 * thing a game can tell anybody — so none of these congratulate a pass or
	 * scold an entry. They say what happened and what it cost, and leave the
	 * arithmetic to do the arguing. None of them claims the turn was there to
	 * be seen: the scenario is built so that it was not.
	 *
	 * @return array<int,array{id:string,risk:array<int,int>,en:string,pt:string}>
	 */
	private static function trap(): array {
		return array(
			// 01–06 · the mechanic.
			array(
				'id'   => 'stc_lesson_trap_01',
				'risk' => array(),
				'en'   => 'A clean trend, gone within a few candles — and the other side of it was no better. Passing was the whole answer today.',
				'pt'   => 'Uma tendência limpa, desfeita ao fim de poucas velas — e o outro lado também não estava melhor. Hoje, passar era a resposta inteira.',
			),
			array(
				'id'   => 'stc_lesson_trap_02',
				'risk' => array(),
				'en'   => 'One candle reached both levels, and a bar records no order between its own prices, so it was booked as a stop. The pessimistic reading is the honest one, and it costs whatever the tier said it would.',
				'pt'   => 'Uma vela chegou aos dois níveis, e uma barra não regista ordem nenhuma entre os seus próprios preços, por isso ficou registada como stop. A leitura pessimista é a honesta, e custa aquilo que o escalão disse que custaria.',
			),
			array(
				'id'   => 'stc_lesson_trap_03',
				'risk' => array(),
				'en'   => 'Both directions were stopped out. There was no read on offer today; there was only a size.',
				'pt'   => 'Os dois lados levaram stop. Hoje não havia leitura nenhuma disponível; havia só um tamanho.',
			),
			array(
				'id'   => 'stc_lesson_trap_04',
				'risk' => array(),
				'en'   => 'It looked like a continuation right up to the candle where it was not. Nothing in the visible window could have warned you, which leaves the size as the only part of the day you actually chose.',
				'pt'   => 'Parecia uma continuação até à vela em que deixou de ser. Nada na janela visível te podia ter avisado, o que deixa o tamanho como a única parte do dia que escolheste mesmo.',
			),
			array(
				'id'   => 'stc_lesson_trap_05',
				'risk' => array(),
				'en'   => 'The stop is one ATR from the entry whatever the chart is doing, so a move that turns and comes back through it costs exactly one R. How many dollars that R was worth was settled at the tier screen.',
				'pt'   => 'O stop fica a um ATR da entrada faça o gráfico o que fizer, por isso um movimento que vira e volta a passar por ele custa exatamente um R. Quantos dólares valia esse R ficou decidido no ecrã dos escalões.',
			),
			array(
				'id'   => 'stc_lesson_trap_06',
				'risk' => array(),
				'en'   => 'Passing was right, and passing feels like nothing at all. That is the part that makes it hard to do twice in a row.',
				'pt'   => 'Passar era o certo, e passar não sabe a nada. É essa parte que torna difícil fazê-lo duas vezes seguidas.',
			),

			// 07–13 · the arithmetic.
			array(
				'id'   => 'stc_lesson_trap_07',
				'risk' => array(),
				'en'   => 'Not trading is a decision, and it has an outcome like any other. Today its outcome was the best one on the board.',
				'pt'   => 'Não operar é uma decisão, e tem um resultado como qualquer outra. Hoje o resultado dela foi o melhor que havia.',
			),
			array(
				'id'   => 'stc_lesson_trap_08',
				'risk' => array( 1000, 2500 ),
				'en'   => 'Ten percent a trade leaves room for %d losses in a row; twenty-five leaves %d. Days that turn like this one are not rare enough for either number to be comfortable.',
				'pt'   => 'Dez por cento por operação deixa espaço para %d perdas seguidas; vinte e cinco deixa %d. Dias que viram como este não são raros o suficiente para nenhum destes números ser confortável.',
			),
			array(
				'id'   => 'stc_lesson_trap_09',
				'risk' => array( 50, 500 ),
				'en'   => 'The same stopped-out day costs one loss out of %d at half a percent and one out of %d at five. The chart was identical; only the denominator was a decision.',
				'pt'   => 'O mesmo dia com stop custa uma perda em %d a meio por cento e uma em %d a cinco. O gráfico foi igual; só o denominador é que foi uma decisão.',
			),
			array(
				'id'   => 'stc_lesson_trap_10',
				'risk' => array(),
				'en'   => 'Nothing here was readable. At half a percent that is a story; at twenty-five it is a different account.',
				'pt'   => 'Não havia nada legível aqui. A meio por cento isto é uma história; a vinte e cinco é outra conta.',
			),
			array(
				'id'   => 'stc_lesson_trap_11',
				'risk' => array( 2500 ),
				'en'   => 'Being right more often is worth less than it sounds. At twenty-five percent a trade, one stretch of %d losses ends the account whatever the hit rate around it looked like; the same stretch at one percent costs less than a tenth of it.',
				'pt'   => 'Acertar mais vezes vale menos do que parece. A vinte e cinco por cento por operação, uma série de %d perdas acaba com a conta seja qual for a taxa de acerto à volta; a mesma série a um por cento custa menos de um décimo dela.',
			),
			array(
				'id'   => 'stc_lesson_trap_12',
				'risk' => array(),
				'en'   => 'A single stop at twenty-five percent takes a quarter of the account, and getting level again from there asks a third more on top of what is left. It was the same stop everybody else took.',
				'pt'   => 'Um único stop a vinte e cinco por cento leva um quarto da conta, e voltar ao ponto de partida a partir daí exige mais um terço sobre o que resta. Foi o mesmo stop que toda a gente levou.',
			),
			array(
				'id'   => 'stc_lesson_trap_13',
				'risk' => array( 200 ),
				'en'   => 'Two percent a trade — the ceiling of the old rule of thumb — leaves room for %d losses in a row. It is a large number precisely because days like this one are not.',
				'pt'   => 'Dois por cento por operação — o teto da velha regra prática — deixa espaço para %d perdas seguidas. É um número grande precisamente porque dias como este não são.',
			),

			// 14–20 · the behaviour.
			array(
				'id'   => 'stc_lesson_trap_14',
				'risk' => array(),
				'en'   => 'Days that turn without notice arrive a few times a month. What they cost is settled before the chart is even on the screen.',
				'pt'   => 'Dias que viram sem aviso aparecem umas quantas vezes por mês. O que custam fica decidido antes de o gráfico estar sequer no ecrã.',
			),
			array(
				'id'   => 'stc_lesson_trap_15',
				'risk' => array(),
				'en'   => 'The gap had no interest in the trend behind it. What it took out of the account was chosen at the tier screen, not on the chart.',
				'pt'   => 'O gap não teve interesse nenhum na tendência que vinha atrás. O que tirou da conta foi escolhido no ecrã dos escalões, não no gráfico.',
			),
			array(
				'id'   => 'stc_lesson_trap_16',
				'risk' => array(),
				'en'   => 'The pull after a day like this is to make it back on the next one, and the only lever that makes it back faster is the tier. It is also the only lever that makes the following loss bigger.',
				'pt'   => 'A tentação depois de um dia destes é recuperar no seguinte, e a única alavanca que recupera mais depressa é o escalão. É também a única alavanca que torna a perda a seguir maior.',
			),
			array(
				'id'   => 'stc_lesson_trap_17',
				'risk' => array(),
				'en'   => 'The doubled stake is the same decision taken twice at once. On a day that whipsaws it changes nothing about what the chart did — only how much of the account was standing in front of it.',
				'pt'   => 'A aposta dobrada é a mesma decisão tomada duas vezes de uma só vez. Num dia que chicoteia não muda nada do que o gráfico fez — muda só quanto da conta estava à frente dele.',
			),
			array(
				'id'   => 'stc_lesson_trap_18',
				'risk' => array(),
				'en'   => 'Passing leaves nothing to show and nothing to tell, which is what makes it the hardest decision to keep taking. On this kind of day it was worth exactly one avoided loss, at whatever size you would have used.',
				'pt'   => 'Passar não deixa nada para mostrar nem nada para contar, e é isso que faz dela a decisão mais difícil de continuar a tomar. Num dia destes valeu exatamente uma perda evitada, no tamanho que terias usado.',
			),
			array(
				'id'   => 'stc_lesson_trap_19',
				'risk' => array(),
				'en'   => 'There is no filter that keeps days like this off the screen, so an account is built to meet them rather than to dodge them. Being built for it is a number, and the number is the tier.',
				'pt'   => 'Não há filtro que mantenha dias destes fora do ecrã, por isso uma conta constrói-se para os encontrar e não para lhes fugir. Estar construída para isso é um número, e o número é o escalão.',
			),
			array(
				'id'   => 'stc_lesson_trap_20',
				'risk' => array(),
				'en'   => 'A day like this is an inconvenience at a small tier and a serious dent at a large one, and nothing in the day itself decides which. That part was settled before the first candle was drawn.',
				'pt'   => 'Um dia destes é um incómodo num escalão pequeno e um rombo sério num grande, e nada no próprio dia decide qual dos dois. Essa parte ficou decidida antes de a primeira vela ser desenhada.',
			),
		);
	}
}
