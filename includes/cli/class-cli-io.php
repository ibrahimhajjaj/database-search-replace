<?php
/**
 * WP-CLI input and output adapter.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Cli;

/**
 * Contains every direct call into WP-CLI's static API.
 */
class Cli_IO {

	/**
	 * Registers a root command.
	 *
	 * @param string $name    Command name.
	 * @param object $command Command handler.
	 * @return void
	 */
	public function add_command( string $name, object $command ): void {
		\WP_CLI::add_command( $name, $command );
	}

	/**
	 * Requests destructive-operation confirmation.
	 *
	 * @param string              $message    Prompt text.
	 * @param array<string,mixed> $assoc_args Command options.
	 * @return void
	 */
	public function confirm( string $message, array $assoc_args ): void {
		\WP_CLI::confirm( $message, $assoc_args );
	}

	/**
	 * Prints an informational line.
	 *
	 * @param string $message Output text.
	 * @return void
	 */
	public function log( string $message ): void {
		\WP_CLI::log( $message );
	}

	/**
	 * Prints a success line.
	 *
	 * @param string $message Output text.
	 * @return void
	 */
	public function success( string $message ): void {
		\WP_CLI::success( $message );
	}

	/**
	 * Stops execution with a clean command error.
	 *
	 * @param string $message Error text.
	 * @return void
	 */
	public function error( string $message ): void {
		\WP_CLI::error( $message );
	}

	/**
	 * Prints structured rows in a supported WP-CLI format.
	 *
	 * @param string                         $format Output format.
	 * @param array<int,array<string,mixed>> $items  Output rows.
	 * @param string[]                       $fields Ordered columns.
	 * @return void
	 */
	public function format_items( string $format, array $items, array $fields ): void {
		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Starts a WP-CLI progress bar.
	 *
	 * @param string $message Progress label.
	 * @param int    $count   Total units.
	 * @return Cli_Progress
	 */
	public function make_progress_bar( string $message, int $count ): Cli_Progress {
		return new Cli_Progress( \WP_CLI\Utils\make_progress_bar( $message, $count ) );
	}
}
