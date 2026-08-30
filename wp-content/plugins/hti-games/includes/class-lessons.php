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
 * EIGHT PER CLASS, not one. A daily game shows the same class of day roughly
 * every third day; a single sentence per class would be furniture inside a
 * fortnight, and furniture is not read. Eight rotate far enough apart that
 * each one is still a sentence when it arrives.
 *
 * The voice is the site's — calm, second person, conditional, no urgency, no
 * promises, no imperatives, nothing that reads as an instruction to trade.
 * See .claude/skills/brand-voice/SKILL.md.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

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
	 * Every lesson, class => list of { id, en, pt }.
	 *
	 * @return array<string,array<int,array{id:string,en:string,pt:string}>>
	 */
	public static function all(): array {
		return array(
			'reasonable' => self::reasonable(),
			'ambiguous'  => self::ambiguous(),
			'trap'       => self::trap(),
		);
	}

	/**
	 * One lesson for a class, chosen deterministically.
	 *
	 * The index wraps, and a negative index wraps the same way a positive one
	 * does, so a caller counting backwards through a library gets a lesson
	 * rather than a warning.
	 *
	 * @param string $class 'reasonable', 'ambiguous' or 'trap'; anything else falls back to FALLBACK_CLASS.
	 * @param int    $index Rotation position.
	 * @return array{id:string,en:string,pt:string}
	 */
	public static function for_class( string $class, int $index ): array {
		$all  = self::all();
		$list = $all[ $class ] ?? $all[ self::FALLBACK_CLASS ];
		$n    = count( $list );

		// PHP's % keeps the sign of the dividend, so -1 % 8 is -1 and would
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

		foreach ( self::all() as $list ) {
			foreach ( $list as $lesson ) {
				$out[] = $lesson['id'];
			}
		}

		return $out;
	}

	/**
	 * Days where the chart was legible and the legible reading was paid.
	 *
	 * The hardest set to write, because the player just won and the honest
	 * thing to say is that winning proved nothing about the size. Every one of
	 * these separates the two: the read is a comment on the chart, the tier is
	 * a comment on the account, and only one of them was tested today.
	 *
	 * @return array<int,array{id:string,en:string,pt:string}>
	 */
	private static function reasonable(): array {
		return array(
			array(
				'id' => 'stc_lesson_reasonable_01',
				'en' => 'The chart was legible and the legible reading was paid. That is a comment on the chart, not on the size — the same call with four times the money behind it would have been the same call.',
				'pt' => 'O gráfico era legível e a leitura legível foi paga. Isso diz alguma coisa sobre o gráfico, não sobre o tamanho — a mesma leitura com quatro vezes o dinheiro atrás teria sido a mesma leitura.',
			),
			array(
				'id' => 'stc_lesson_reasonable_02',
				'en' => 'A win at a heavy tier is the sizing getting away with it, not a read being right. The account survived the call today; the size was never tested.',
				'pt' => 'Um ganho num escalão pesado é o tamanho a safar-se, não a leitura a acertar. Hoje a conta sobreviveu à leitura; o tamanho não chegou a ser testado.',
			),
			array(
				'id' => 'stc_lesson_reasonable_03',
				'en' => 'A day that works is the cheapest day to look at what you risked, because looking costs nothing while nothing hurts.',
				'pt' => 'Um dia que corre bem é o dia mais barato para olhar para o que arriscaste, porque olhar não custa nada enquanto nada dói.',
			),
			array(
				'id' => 'stc_lesson_reasonable_04',
				'en' => 'Winning days are not evidence that the size was sensible. They are evidence that the size was not tested.',
				'pt' => 'Dias de ganho não são prova de que o tamanho era sensato. São prova de que o tamanho não foi testado.',
			),
			array(
				'id' => 'stc_lesson_reasonable_05',
				'en' => 'The target was worth one and a half times what the stop would have cost. That arithmetic only helps an account that is still around for enough of these.',
				'pt' => 'O alvo valia uma vez e meia o que o stop teria custado. Essa aritmética só ajuda uma conta que ainda cá esteja para ter muitas destas.',
			),
			array(
				'id' => 'stc_lesson_reasonable_06',
				'en' => 'Passing a readable day costs you the trade and nothing else. It is a small price, and it is the same small price every single time.',
				'pt' => 'Passar um dia legível custa-te a operação e mais nada. É um preço pequeno, e é o mesmo preço pequeno de todas as vezes.',
			),
			array(
				'id' => 'stc_lesson_reasonable_07',
				'en' => 'A trend that keeps going is the most ordinary thing a chart does, and still not something an account can lean on. How hard you lean is what the tier decides.',
				'pt' => 'Uma tendência que continua é a coisa mais banal que um gráfico faz, e mesmo assim não é coisa em que uma conta se possa encostar. O quanto te encostas é o que o escalão decide.',
			),
			array(
				'id' => 'stc_lesson_reasonable_08',
				'en' => 'Same chart, same call, one percent instead of twenty-five: the story is identical and the account is not.',
				'pt' => 'Mesmo gráfico, mesma leitura, um por cento em vez de vinte e cinco: a história é igual e a conta não é.',
			),
		);
	}

	/**
	 * Days that resolved nothing in either direction.
	 *
	 * The class the whole game is really about. Nobody was right, nobody was
	 * punished, and the account still moved — by exactly as much as the tier
	 * said it would before the first candle.
	 *
	 * @return array<int,array{id:string,en:string,pt:string}>
	 */
	private static function ambiguous(): array {
		return array(
			array(
				'id' => 'stc_lesson_ambiguous_01',
				'en' => 'Forty candles and nothing resolved. Whichever side you took, direction was never the variable today — the size was.',
				'pt' => 'Quarenta velas e nada se resolveu. Qualquer que fosse o lado, hoje a direção nunca foi a variável — o tamanho foi.',
			),
			array(
				'id' => 'stc_lesson_ambiguous_02',
				'en' => 'Most days look like this one. Not much happened, and the only number that moved the account was the one you chose before it started.',
				'pt' => 'A maioria dos dias é assim. Não aconteceu grande coisa, e o único número que mexeu com a conta foi o que escolheste antes de isto começar.',
			),
			array(
				'id' => 'stc_lesson_ambiguous_03',
				'en' => 'A market that answers nothing still charges you for standing in it. What it charges is the size.',
				'pt' => 'Um mercado que não responde a nada continua a cobrar-te por estares lá dentro. O que cobra é o tamanho.',
			),
			array(
				'id' => 'stc_lesson_ambiguous_04',
				'en' => 'You did not read this one wrong. There was nothing to read. What is left of the day is how much of the account was in the room.',
				'pt' => 'Não leste isto mal. Não havia nada para ler. O que sobra do dia é quanto da conta estava na sala.',
			),
			array(
				'id' => 'stc_lesson_ambiguous_05',
				'en' => 'Right and wrong barely showed up here. The account still noticed the difference between half a percent and ten.',
				'pt' => 'Certo e errado quase não apareceram aqui. A conta, essa, notou a diferença entre meio por cento e dez.',
			),
			array(
				'id' => 'stc_lesson_ambiguous_06',
				'en' => 'Sitting a day like this out costs nothing and gives up nothing, which is why it is easy to forget it was on the table.',
				'pt' => 'Ficar de fora num dia destes não custa nada e não abdica de nada, e é por isso que é fácil esquecer que estava em cima da mesa.',
			),
			array(
				'id' => 'stc_lesson_ambiguous_07',
				'en' => 'No edge, no punishment, no reward. The sizing decision was the entire day.',
				'pt' => 'Sem vantagem, sem castigo, sem recompensa. A decisão de tamanho foi o dia inteiro.',
			),
			array(
				'id' => 'stc_lesson_ambiguous_08',
				'en' => 'On a day that goes nowhere a small position is boring and a large one is still large. Only one of those becomes a problem later.',
				'pt' => 'Num dia que não vai a lado nenhum uma posição pequena é aborrecida e uma grande continua a ser grande. Só uma delas se torna um problema mais à frente.',
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
	 * arithmetic to do the arguing.
	 *
	 * @return array<int,array{id:string,en:string,pt:string}>
	 */
	private static function trap(): array {
		return array(
			array(
				'id' => 'stc_lesson_trap_01',
				'en' => 'A clean trend, gone within a few candles — and the other side of it was no better. Passing was the whole answer today.',
				'pt' => 'Uma tendência limpa, desfeita ao fim de poucas velas — e o outro lado também não estava melhor. Hoje, passar era a resposta inteira.',
			),
			array(
				'id' => 'stc_lesson_trap_02',
				'en' => 'Passing was right, and passing feels like nothing at all. That is the part that makes it hard to do twice in a row.',
				'pt' => 'Passar era o certo, e passar não sabe a nada. É essa parte que torna difícil fazê-lo duas vezes seguidas.',
			),
			array(
				'id' => 'stc_lesson_trap_03',
				'en' => 'Both directions were stopped out. There was no read on offer today; there was only a size.',
				'pt' => 'Os dois lados levaram stop. Hoje não havia leitura nenhuma disponível; havia só um tamanho.',
			),
			array(
				'id' => 'stc_lesson_trap_04',
				'en' => 'It looked like a continuation right up to the candle where it was not. Nothing in the visible window could have warned you, which leaves the size as the only part of the day you actually chose.',
				'pt' => 'Parecia uma continuação até à vela em que deixou de ser. Nada na janela visível te podia ter avisado, o que deixa o tamanho como a única parte do dia que escolheste mesmo.',
			),
			array(
				'id' => 'stc_lesson_trap_05',
				'en' => 'Days that turn without notice arrive a few times a month. What they cost is settled before the chart is even on the screen.',
				'pt' => 'Dias que viram sem aviso aparecem umas quantas vezes por mês. O que custam fica decidido antes de o gráfico estar sequer no ecrã.',
			),
			array(
				'id' => 'stc_lesson_trap_06',
				'en' => 'Nothing here was readable. At half a percent that is a story; at twenty-five it is a different account.',
				'pt' => 'Não havia nada legível aqui. A meio por cento isto é uma história; a vinte e cinco é outra conta.',
			),
			array(
				'id' => 'stc_lesson_trap_07',
				'en' => 'The gap had no interest in the trend behind it. What it took out of the account was chosen at the tier screen, not on the chart.',
				'pt' => 'O gap não teve interesse nenhum na tendência que vinha atrás. O que tirou da conta foi escolhido no ecrã dos escalões, não no gráfico.',
			),
			array(
				'id' => 'stc_lesson_trap_08',
				'en' => 'Not trading is a decision, and it has an outcome like any other. Today its outcome was the best one on the board.',
				'pt' => 'Não operar é uma decisão, e tem um resultado como qualquer outra. Hoje o resultado dela foi o melhor que havia.',
			),
		);
	}
}
