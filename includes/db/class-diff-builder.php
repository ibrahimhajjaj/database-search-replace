<?php
/**
 * Compact database value diffs.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Db;

/**
 * Builds bounded before and after segment arrays for changed strings.
 */
class Diff_Builder {

	private const ANCHOR_LENGTH = 8;

	/**
	 * Context characters retained around changed regions.
	 *
	 * @var int
	 */
	private int $context_size;

	/**
	 * Maximum text bytes retained per side.
	 *
	 * @var int
	 */
	private int $max_bytes;

	/**
	 * Sets excerpt limits.
	 *
	 * @param int $context_size Context characters retained around changed regions.
	 * @param int $max_bytes    Maximum text bytes retained per side.
	 */
	public function __construct( int $context_size = 60, int $max_bytes = 10240 ) {
		$this->context_size = max( 0, $context_size );
		$this->max_bytes    = max( 1, $max_bytes );
	}

	/**
	 * Builds display segments for two values.
	 *
	 * @param string $old_value Original value.
	 * @param string $new_value Replacement value.
	 * @return array{before:array<int,array{t:string,k:string}>,after:array<int,array{t:string,k:string}>,truncated:bool,before_length:int,after_length:int}
	 */
	public function build( string $old_value, string $new_value ): array {
		$old         = $this->characters( $old_value );
		$replacement = $this->characters( $new_value );
		$ops         = $this->operations( $old, $replacement );

		$before = $this->segments_for_side( $ops, 'del' );
		$after  = $this->segments_for_side( $ops, 'add' );

		$before_capped = $this->cap_segments( $before['segments'] );
		$after_capped  = $this->cap_segments( $after['segments'] );

		return array(
			'before'        => $before_capped['segments'],
			'after'         => $after_capped['segments'],
			'truncated'     => $before['truncated'] || $after['truncated'] || $before_capped['truncated'] || $after_capped['truncated'],
			'before_length' => count( $old ),
			'after_length'  => count( $replacement ),
		);
	}

	/**
	 * Splits a valid UTF-8 string into code points and falls back to bytes.
	 *
	 * @param string $value Source string.
	 * @return string[]
	 */
	private function characters( string $value ): array {
		$characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );

		return false === $characters ? str_split( $value ) : $characters;
	}

	/**
	 * Finds equal, deleted, and added runs using nearby stable anchors.
	 *
	 * @param string[] $old Original characters.
	 * @param string[] $replacement Replacement characters.
	 * @return array<int,array{k:string,t:string}>
	 */
	private function operations( array $old, array $replacement ): array {
		$operations = array();
		$old_index  = 0;
		$new_index  = 0;
		$old_count  = count( $old );
		$new_count  = count( $replacement );

		while ( $old_index < $old_count || $new_index < $new_count ) {
			$equal = array();
			while ( $old_index < $old_count && $new_index < $new_count && $old[ $old_index ] === $replacement[ $new_index ] ) {
				$equal[] = $old[ $old_index ];
				++$old_index;
				++$new_index;
			}
			$this->append_operation( $operations, 'ctx', implode( '', $equal ) );

			if ( $old_index >= $old_count && $new_index >= $new_count ) {
				break;
			}

			$anchor  = $this->find_anchor( $old, $replacement, $old_index, $new_index );
			$old_end = null === $anchor ? $old_count : $anchor[0];
			$new_end = null === $anchor ? $new_count : $anchor[1];
			$this->append_operation( $operations, 'del', implode( '', array_slice( $old, $old_index, $old_end - $old_index ) ) );
			$this->append_operation( $operations, 'add', implode( '', array_slice( $replacement, $new_index, $new_end - $new_index ) ) );
			$old_index = $old_end;
			$new_index = $new_end;
		}

		return $operations;
	}

	/**
	 * Locates the nearest shared character run after a mismatch.
	 *
	 * @param string[] $old       Original characters.
	 * @param string[] $replacement Replacement characters.
	 * @param int      $old_start Original search offset.
	 * @param int      $new_start Replacement search offset.
	 * @return array{int,int}|null
	 */
	private function find_anchor( array $old, array $replacement, int $old_start, int $new_start ): ?array {
		$old_limit = min( count( $old ) - self::ANCHOR_LENGTH, $old_start + 256 );
		$new_limit = min( count( $replacement ) - self::ANCHOR_LENGTH, $new_start + 256 );
		$best      = null;
		$best_cost = PHP_INT_MAX;

		for ( $old_index = $old_start; $old_index <= $old_limit; ++$old_index ) {
			if ( $old_index - $old_start > $best_cost ) {
				break;
			}
			for ( $new_index = $new_start; $new_index <= $new_limit; ++$new_index ) {
				$cost = ( $old_index - $old_start ) + ( $new_index - $new_start );
				if ( $cost >= $best_cost ) {
					continue;
				}
				if ( array_slice( $old, $old_index, self::ANCHOR_LENGTH ) === array_slice( $replacement, $new_index, self::ANCHOR_LENGTH ) ) {
					$best      = array( $old_index, $new_index );
					$best_cost = $cost;
				}
			}
		}

		return $best;
	}

	/**
	 * Appends a nonempty operation and merges adjacent runs of the same kind.
	 *
	 * @param array<int,array{k:string,t:string}> $operations Operations built so far.
	 * @param string                              $kind       Segment kind.
	 * @param string                              $text       Segment text.
	 * @return void
	 */
	private function append_operation( array &$operations, string $kind, string $text ): void {
		if ( '' === $text ) {
			return;
		}

		$last = count( $operations ) - 1;
		if ( 0 <= $last && $kind === $operations[ $last ]['k'] ) {
			$operations[ $last ]['t'] .= $text;
			return;
		}

		$operations[] = array(
			'k' => $kind,
			't' => $text,
		);
	}

	/**
	 * Selects shared context and the changed runs for one side.
	 *
	 * @param array<int,array{k:string,t:string}> $operations Diff operations.
	 * @param string                              $change_kind del or add.
	 * @return array{segments:array<int,array{t:string,k:string}>,truncated:bool}
	 */
	private function segments_for_side( array $operations, string $change_kind ): array {
		$segments  = array();
		$truncated = false;
		$count     = count( $operations );

		foreach ( $operations as $index => $operation ) {
			if ( $change_kind === $operation['k'] ) {
				$segments[] = array(
					't' => $operation['t'],
					'k' => $change_kind,
				);
				continue;
			}
			if ( 'ctx' !== $operation['k'] ) {
				continue;
			}

			$characters = $this->characters( $operation['t'] );
			$length     = count( $characters );
			$left_edge  = 0 === $index;
			$right_edge = $index === $count - 1;
			$start      = $left_edge ? max( 0, $length - $this->context_size ) : 0;
			$take       = $length;

			if ( ! $left_edge && ! $right_edge && ( 2 * $this->context_size ) < $length ) {
				$segments[] = array(
					't' => implode( '', array_slice( $characters, 0, $this->context_size ) ),
					'k' => 'ctx',
				);
				$segments[] = array(
					't' => implode( '', array_slice( $characters, -$this->context_size ) ),
					'k' => 'ctx',
				);
				$truncated  = true;
				continue;
			}
			if ( $right_edge && ! $left_edge ) {
				$take = min( $length, $this->context_size );
			}
			if ( $start > 0 || $take < $length ) {
				$truncated = true;
			}
			$segments[] = array(
				't' => implode( '', array_slice( $characters, $start, $take ) ),
				'k' => 'ctx',
			);
		}

		return array(
			'segments'  => array_values( array_filter( $segments, static fn( array $segment ): bool => '' !== $segment['t'] ) ),
			'truncated' => $truncated,
		);
	}

	/**
	 * Caps segment text without splitting a UTF-8 code point.
	 *
	 * @param array<int,array{t:string,k:string}> $segments Display segments.
	 * @return array{segments:array<int,array{t:string,k:string}>,truncated:bool}
	 */
	private function cap_segments( array $segments ): array {
		$result    = array();
		$remaining = $this->max_bytes;
		$truncated = false;

		foreach ( $segments as $segment ) {
			if ( strlen( $segment['t'] ) <= $remaining ) {
				$result[]   = $segment;
				$remaining -= strlen( $segment['t'] );
				continue;
			}

			$text = '';
			foreach ( $this->characters( $segment['t'] ) as $character ) {
				if ( strlen( $character ) > $remaining ) {
					break;
				}
				$text      .= $character;
				$remaining -= strlen( $character );
			}
			if ( '' !== $text ) {
				$segment['t'] = $text;
				$result[]     = $segment;
			}
			$truncated = true;
			break;
		}

		return array(
			'segments'  => $result,
			'truncated' => $truncated,
		);
	}
}
