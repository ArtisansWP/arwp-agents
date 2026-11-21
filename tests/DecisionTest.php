<?php
/**
 * Unit tests for spend, retention, transcript, and notice decisions.
 *
 * @package ARWP_Agents
 */

use PHPUnit\Framework\TestCase;

/**
 * Pure decision coverage. No database and no WordPress bootstrap.
 */
class DecisionTest extends TestCase {

	/**
	 * A ceiling of zero never blocks.
	 */
	public function test_zero_ceilings_do_not_block() {
		$this->assertSame( '', ARWP_Usage::block_reason( 5000, 9000000, 0, 0 ) );
	}

	/**
	 * A token ceiling already met blocks, and it wins when the dollar ceiling is met too.
	 */
	public function test_token_ceiling_blocks_and_wins() {
		$this->assertSame( 'tokens', ARWP_Usage::block_reason( 100, 0, 100, 0 ) );
		$this->assertSame( 'tokens', ARWP_Usage::block_reason( 100, 2000000, 100, '1.00' ) );
	}

	/**
	 * A dollar ceiling blocks only from reported cost.
	 */
	public function test_dollar_ceiling_blocks() {
		$this->assertSame( '', ARWP_Usage::block_reason( 0, 999999, 0, '1.00' ) );
		$this->assertSame( 'dollars', ARWP_Usage::block_reason( 0, 1000000, 0, '1.00' ) );
	}

	/**
	 * Dollar amounts become integer micro-dollars.
	 */
	public function test_dollars_to_micro() {
		$this->assertSame( 1250000, ARWP_Usage::dollars_to_micro( '1.25' ) );
		$this->assertSame( 0, ARWP_Usage::dollars_to_micro( 'nope' ) );
		$this->assertSame( 10000, ARWP_Usage::dollars_to_micro( 0.01 ) );
	}

	/**
	 * Alerts fire once: near at 80 percent, stop when blocked, and never again after the flag.
	 */
	public function test_alert_kind_once() {
		$this->assertSame( '', ARWP_Usage::alert_kind( 7, 0, 10, 0, false, false ) );
		$this->assertSame( 'near', ARWP_Usage::alert_kind( 8, 0, 10, 0, false, false ) );
		$this->assertSame( '', ARWP_Usage::alert_kind( 8, 0, 10, 0, true, false ) );
		$this->assertSame( 'stop', ARWP_Usage::alert_kind( 10, 0, 10, 0, true, false ) );
		$this->assertSame( '', ARWP_Usage::alert_kind( 10, 0, 10, 0, true, true ) );
	}

	/**
	 * A dollar ceiling also has an 80 percent alert.
	 */
	public function test_dollar_near_alert() {
		$this->assertSame( 'near', ARWP_Usage::alert_kind( 0, 800000, 0, '1.00', false, false ) );
		$this->assertSame( '', ARWP_Usage::alert_kind( 0, 799999, 0, '1.00', false, false ) );
	}

	/**
	 * Retention of zero keeps transcripts. A positive count moves the cutoff back.
	 */
	public function test_retention_cutoff() {
		$now = 1700000000;

		$this->assertSame( '', ARWP_Privacy::cutoff_mysql( 0, $now ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now - ( 30 * 86400 ) ), ARWP_Privacy::cutoff_mysql( 30, $now ) );
	}

	/**
	 * Transcript text keeps the title and the visitor and assistant turns.
	 */
	public function test_transcript_skips_system_rows() {
		$text = ARWP_Privacy::format_transcript(
			'Hello there',
			array(
				array(
					'role'    => 'system',
					'content' => 'secret prompt',
				),
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Hello',
				),
			)
		);

		$this->assertStringContainsString( "Hello there\n", $text );
		$this->assertStringContainsString( 'user: Hi', $text );
		$this->assertStringContainsString( 'assistant: Hello', $text );
		$this->assertStringNotContainsString( 'secret prompt', $text );
	}

	/**
	 * A blank notice setting falls back to the default sentence.
	 */
	public function test_notice_fallback() {
		$default = 'Your message is sent to OpenRouter to generate a reply. It leaves this site. Do not include passwords or anything you do not want shared.';

		$this->assertSame( $default, ARWP_Privacy::notice_text( '' ) );
		$this->assertSame( $default, ARWP_Privacy::notice_text( '   ' ) );
		$this->assertSame( 'Custom notice.', ARWP_Privacy::notice_text( 'Custom notice.' ) );
	}
}
