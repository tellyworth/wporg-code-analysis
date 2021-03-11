<?php

namespace WordPressDotOrg\Code_Analysis\sniffs;

use WordPressCS\WordPress\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use PHP_CodeSniffer\Util\Variables;
use PHPCSUtils\Utils\PassedParameters;

require_once( dirname( dirname( __DIR__ ) ) . '/vendor/autoload.php' );

/**
 * Check for buggy/insecure use of wp_verify_nonce()
 */
class VerifyNonceSniff extends Sniff {

	/**
	 * Helper function to return the next non-empty token starting at $stackPtr inclusive.
	 */
	protected function next_non_empty( $stackPtr, $local_only = true ) {
		return $this->phpcsFile->findNext( Tokens::$emptyTokens, $stackPtr , null, true, null, $local_only );
	}

	protected function previous_non_empty( $stackPtr, $local_only = true ) {
		return $this->phpcsFile->findPrevious( Tokens::$emptyTokens, $stackPtr , null, true, null, $local_only );
	}

	protected function tokens_as_string( $start, $end ) {
		return $this->phpcsFile->getTokensAsString( $start, $end - $start + 1 );
	}

	/**
	 * Is $stackPtr part of the conditional expression in an `if` statement?
	 */
	protected function is_conditional_expression( $stackPtr ) {
		if ( $this->tokens[ $stackPtr ][ 'nested_parenthesis' ] ) {
			$start = array_key_last( $this->tokens[ $stackPtr ][ 'nested_parenthesis' ] );
			#$end = $this->tokens[ $stackPtr ][ 'nested_parenthesis' ][ $start ];
			$ownerPtr = $this->tokens[ $start ][ 'parenthesis_owner' ];
			if ( in_array( $this->tokens[ $ownerPtr ][ 'code' ], [ \T_IF, \T_ELSEIF ] ) ) {
				#var_dump( 'scope_opener', $this->tokens[ $this->tokens[ $ownerPtr ][ 'scope_opener' ] ] );
				#var_dump( $ownerPtr, $this->tokens[ $ownerPtr ] );
				#var_dump( 'parens', $this->tokens_as_string( $this->tokens[ $ownerPtr ][ 'parenthesis_opener' ], $this->tokens[ $ownerPtr ][ 'parenthesis_closer' ] ) );
				#var_dump( 'scope', $this->tokens_as_string( $this->tokens[ $ownerPtr ][ 'scope_opener' ], $this->tokens[ $ownerPtr ][ 'scope_closer' ] ) );
				return $ownerPtr;
			}
		}

		return false;
	}

	/**
	 * Get the conditional expression part of an if/elseif statement.
	 */
	protected function get_expression_from_condition( $stackPtr ) {
		if ( isset( $this->tokens[ $stackPtr ][ 'parenthesis_opener' ] ) ) {
			return [ $this->tokens[ $stackPtr ][ 'parenthesis_opener' ], $this->tokens[ $stackPtr ][ 'parenthesis_closer' ] ];
		} else {
			var_dump( "no expression", $this->tokens[ $stackPtr ] );
		}
		return false;
	}

	/**
	 * Get the scope part of an if/else/elseif statement.
	 */
	protected function get_scope_from_condition( $stackPtr ) {
		if ( !in_array( $this->tokens[ $stackPtr ][ 'code' ], [ \T_IF, \T_ELSEIF, \T_ELSE ] ) ) {
			return false;
		}
		if ( isset( $this->tokens[ $stackPtr ][ 'scope_opener' ] ) ) {
			return [ $this->tokens[ $stackPtr ][ 'scope_opener' ], $this->tokens[ $stackPtr ][ 'scope_closer' ] ];
		} else {
			// if ( $foo ) bar();
			$start = $this->next_non_empty( $stackPtr + 1 );
			$end = $this->phpcsFile->findEndOfStatement( $start );
			return [ $start, $end ];
		}
		return false;
	}



	/**
	 * Does the given if statement have an 'else' or 'elseif' 
	 */
	protected function has_else( $stackPtr ) {
		if ( $this->tokens[ $stackPtr ][ 'scope_closer' ] ) {
			$nextPtr = $this->next_non_empty( $this->tokens[ $stackPtr ][ 'scope_closer' ] + 1 );
			#var_dump( __FUNCTION__,  $stackPtr, $this->tokens[ $stackPtr ], $nextPtr, $this->tokens[ $nextPtr ] );
			if ( $nextPtr && in_array( $this->tokens[ $nextPtr ][ 'code' ], [ \T_ELSE, \T_ELSEIF ] ) ) {
				return $nextPtr;
			}
		}
		return false;
	}

	/**
	 * Does the given scope contain an exit, die, wp_send_json_error(), or similar statement that's sufficient to handle a nonce failure?
	 */
	protected function scope_contains_error_terminator( $start, $end ) {

		$stackPtr = $this->phpcsFile->findNext( Tokens::$functionNameTokens + [ \T_RETURN => \T_RETURN ], $start, $end, false, null, false );
		while ( $stackPtr <= $end && $stackPtr ) {
			if ( in_array( $this->tokens[ $stackPtr ][ 'content' ], array(
					'exit',
					'die',
					'wp_send_json_error',
					'wp_nonce_ays',
					'return',
				) ) ) {
					return $stackPtr;
			}

			$stackPtr = $this->phpcsFile->findNext( Tokens::$functionNameTokens, $stackPtr + 1, $end, false, null, false );
		}

		return false;
	}

	/**
	 * Does the given expression contain multiple 'and' clauses like `$foo && bar()` or `foo() and $bar`?
	 */
	protected function expression_contains_and( $start, $end ) {
		$tokens = [
			\T_BOOLEAN_AND => \T_BOOLEAN_AND,
			\T_LOGICAL_AND => \T_LOGICAL_AND,
			
		];
		#var_dump( __FUNCTION__, $this->phpcsFile->getTokensAsString( $start, $end - $start + 1 ) );
		return $this->phpcsFile->findNext( $tokens, $start, $end, false, null, false );
	}

	/**
	 * Is the expression immediately preceded by a boolean not `!`?
	 */
	protected function expression_is_negated( $stackPtr ) {
		$previous = $this->previous_non_empty( $stackPtr - 1 );
		if ( \T_BOOLEAN_NOT === $this->tokens[ $previous ][ 'code' ] ) {
			return $previous;
		}

		return false;
	}

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @return array
	 */
	public function register() {
		return Tokens::$functionNameTokens;
	}

	/**
	 * Processes this test, when one of its tokens is encountered.
	 *
	 * @param int $stackPtr The position of the current token in the stack.
	 *
	 * @return int|void Integer stack pointer to skip forward or void to continue
	 *                  normal file processing.
	 */
	public function process_token( $stackPtr ) {
		if ( 'wp_verify_nonce' === $this->tokens[ $stackPtr ][ 'content' ] ) {
			#var_dump( $this->tokens[ $stackPtr ] );
			#foreach ( $this->tokens[ $stackPtr ][ 'nested_parenthesis' ] as $a => $b ) {
			#	var_dump( 'nested', $this->tokens[ $a ], $this->tokens[ $b ] );
			#}
			if ( $ifPtr = $this->is_conditional_expression( $stackPtr ) ) {
				// We're in a conditional, something like if ( wp_verify_nonce() )

				if ( $this->expression_is_negated( $stackPtr ) ) {
					// if ( !wp_verify_nonce() )
					var_dump( "negated wp_verify_nonce" );

					list( $expression_start, $expression_end ) = $this->get_expression_from_condition( $ifPtr );
					list( $scope_start, $scope_end ) = $this->get_scope_from_condition( $ifPtr );
					var_dump( "checking negated", $this->tokens_as_string( $expression_start, $expression_end ), $this->tokens_as_string( $scope_start, $scope_end ) );

					if ( $this->expression_contains_and( $expression_start, $expression_end ) && $this->scope_contains_error_terminator( $scope_start, $scope_end ) ) {
						var_dump( "error in", $this->tokens_as_string( $scope_start, $scope_end ) );
						$this->phpcsFile->addError( 'Unsafe use of wp_verify_nonce() in expression %s.',
							$stackPtr,
							'UnsafeVerifyNonceNegatedAnd',
							[ $this->tokens_as_string( $expression_start, $expression_end ) ], //[ $unsafe_expression, $method, $methodParam[ 'clean' ], rtrim( "\n" . join( "\n", $extra_context ) ) ],
							0,
							false
						);

					}
				} else {
					// if ( wp_verify_nonce() )
					// In this case we want the else {} part
					$elsePtr = $this->has_else( $ifPtr );
					if ( $elsePtr ) {
						var_dump( "else", $this->tokens[ $elsePtr ] );
						list( $expression_start, $expression_end ) = $this->get_expression_from_condition( $ifPtr );
						list( $scope_start, $scope_end ) = $this->get_scope_from_condition( $elsePtr );

						// In this case it doesn't matter if there's a logical AND, but the else clause must contain a terminator.
						if ( ! $this->scope_contains_error_terminator( $scope_start, $scope_end ) ) {
							var_dump( "error in", $this->tokens_as_string( $scope_start, $scope_end ) );
							$this->phpcsFile->addError( 'Unsafe use of wp_verify_nonce() in expression %s.',
								$stackPtr,
								'UnsafeVerifyNonceElse',
								[ $this->tokens_as_string( $expression_start, $expression_end ) ], // [ $unsafe_expression, $method, $methodParam[ 'clean' ], rtrim( "\n" . join( "\n", $extra_context ) ) ],
								0,
								false
							);
	
						}
	
					}
				}

			} else {
				// wp_verify_nonce() used as an unconditional statement - most likely mistaken for check_admin_referer()
				$this->phpcsFile->addError( 'Unconditional call to wp_verify_nonce().',
				$stackPtr,
				'UnsafeVerifyNonceStatement',
				[],
				0,
				false
			);

			}


		}
	}
	
}