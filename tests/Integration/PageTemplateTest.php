<?php
/**
 * A page whose template the active theme does not have is refused before any write. When WordPress fails after it has
 * written the row, the caller is told the change was at least partly applied.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Covers invalid_page_template: the early refusal (steadhand_refused_invalid_template) and the honest error after a write
 * (steadhand_update_partial).
 */
final class PageTemplateTest extends PostWriteTestCase {

	/** A template file the active theme does not have. */
	private const MISSING = 'zz-missing-template.php';

	/**
	 * Creates a draft page whose stored template the theme does not have. Returns its ID.
	 */
	private function page_with_missing_template(): int {
		$page = $this->admin_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Old',
				'post_content' => 'p',
			)
		);
		update_post_meta( $page, '_wp_page_template', self::MISSING );

		return $page;
	}

	public function test_a_post_whose_template_the_theme_lacks_is_refused_before_any_write(): void {
		$page      = $this->page_with_missing_template();
		$agent     = $this->act_as_agent();
		$before    = $this->row( $page );
		$revisions = $this->revision_ids( $page );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $page,
				'title'   => 'New title',
				'content' => 'new content',
			)
		);

		$this->assertSame( 'steadhand_refused_invalid_template', $this->refusal_code( $result ) );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'An administrator must set a valid template', $result->get_error_message() );
		$this->assertSame( $before, $this->row( $page ), 'The post row is byte-identical.' );
		$this->assertSame( $revisions, $this->revision_ids( $page ), 'No revision is made.' );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'error', $row['result'] );
		$this->assertSame( $result->get_error_message(), $row['message'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	public function test_a_page_with_no_template_or_the_default_template_is_not_refused(): void {
		$page = $this->admin_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => 'p',
			)
		);
		update_post_meta( $page, '_wp_page_template', 'default' );
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $page,
				'title' => 'Still fine',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'Still fine', get_post( $page )->post_title );
	}

	public function test_when_wordpress_fails_after_it_wrote_the_row_the_caller_is_told_the_change_was_partly_applied(): void {
		$page = $this->page_with_missing_template();

		// The early check sees the template, so the call gets past it. The theme's list is taken away again during the
		// write, so that core's own check, which runs after the row is written, fails.
		$theme   = static fn( array $templates ): array => $templates + array( self::MISSING => 'Missing' );
		add_filter( 'theme_page_templates', $theme );
		$restore = null;
		$restore = static function ( array $data, array $postarr ) use ( $page, $theme, &$restore ): array {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === $page ) {
				remove_filter( 'theme_page_templates', $theme );
				remove_filter( 'wp_insert_post_data', $restore );
			}

			return $data;
		};
		add_filter( 'wp_insert_post_data', $restore, 10, 2 );

		$agent  = $this->act_as_agent();
		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $page,
				'title'   => 'New title',
				'content' => 'new content',
			)
		);

		$this->assertSame( 'steadhand_update_partial', $this->refusal_code( $result ) );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'at least partly applied', $result->get_error_message() );
		$this->assertStringNotContainsString( 'nothing was changed', $result->get_error_message() );
		$this->assertSame( 'New title', get_post( $page )->post_title, 'The row was written before WordPress reported the error.' );
		$this->assertSame( 'new content', get_post( $page )->post_content );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'error', $row['result'] );
		$this->assertSame( $result->get_error_message(), $row['message'] );
	}
}
