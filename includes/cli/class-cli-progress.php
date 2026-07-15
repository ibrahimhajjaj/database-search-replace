<?php
/**
 * WP-CLI progress adapter.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Cli;

/**
 * Wraps a WP-CLI progress bar without exposing it to command logic.
 */
class Cli_Progress {

	/**
	 * WP-CLI progress bar instance.
	 *
	 * @var object|null
	 */
	private $progress;

	/**
	 * Wraps WP-CLI's progress object.
	 *
	 * @param object|null $progress WP-CLI progress bar instance.
	 */
	public function __construct( $progress = null ) {
		$this->progress = $progress;
	}

	/**
	 * Advances the progress bar.
	 *
	 * @param int $increment Completed units.
	 * @return void
	 */
	public function tick( int $increment = 1 ): void {
		if ( null !== $this->progress && method_exists( $this->progress, 'tick' ) ) {
			$this->progress->tick( $increment );
		}
	}

	/**
	 * Completes the progress bar.
	 *
	 * @return void
	 */
	public function finish(): void {
		if ( null !== $this->progress && method_exists( $this->progress, 'finish' ) ) {
			$this->progress->finish();
		}
	}
}
