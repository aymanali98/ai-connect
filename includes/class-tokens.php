<?php
/**
 * Connection tokens. Raw tokens are shown once; authentication stores
 * SHA-256 hashes. The newest token is also retained in a transient so the
 * Connect AI page can pre-fill its ready-made prompts (see last_for_prompts).
 *
 * Format: swt_<8 hex prefix>_<48 hex secret>
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token lifecycle: create, authenticate, revoke, cleanup.
 */
final class Tokens {

	/**
	 * Token family prefix.
	 */
	const TOKEN_PREFIX = 'swt';

	/**
	 * Transient holding the newest token so the Connect AI page can pre-fill
	 * its quick-connect prompts. Replaced by the next token, cleared on revoke
	 * and on uninstall; never exposed outside wp-admin.
	 */
	const LAST_TOKEN_TRANSIENT = 'ai_connect_last_token';

	/**
	 * Create a token and return the plaintext once.
	 *
	 * @param array $args {
	 *     @type string $name      Human label.
	 *     @type int    $user_id   WordPress user the token acts as.
	 *     @type int    $days      Lifetime in days, 0 = never expires.
	 *     @type bool   $read_only Token may only use read-risk tools.
	 * }
	 * @return array Token record including the plaintext 'token' key.
	 */
	public static function create( array $args ) {
		global $wpdb;

		$name      = trim( (string) ( isset( $args['name'] ) ? $args['name'] : '' ) );
		$name      = '' !== $name ? Util::truncate( $name, 120 ) : __( 'Connection', 'ai-connect' );
		$user_id   = (int) ( isset( $args['user_id'] ) ? $args['user_id'] : 0 );
		$days      = is_numeric( isset( $args['days'] ) ? $args['days'] : null ) ? (int) $args['days'] : (int) Settings::get( 'default_token_days' );
		$read_only = Util::to_bool( isset( $args['read_only'] ) ? $args['read_only'] : false );

		$prefix = bin2hex( random_bytes( 4 ) );
		$secret = bin2hex( random_bytes( 24 ) );
		$token  = self::TOKEN_PREFIX . '_' . $prefix . '_' . $secret;
		$hash   = hash( 'sha256', $token );

		$expires_at = null;
		if ( $days > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS_STUB ) );
		}

		$row = array(
			'token_prefix' => $prefix,
			'token_hash'   => $hash,
			'name'         => $name,
			'user_id'      => $user_id,
			'read_only'    => $read_only ? 1 : 0,
			'created_at'   => Util::now(),
			'expires_at'   => $expires_at,
			'last_used_at' => null,
			'revoked_at'   => null,
		);

		$wpdb->insert( DB::tokens_table(), $row, array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ) );

		$id = (int) $wpdb->insert_id;

		// Keep the newest token around so the Connect AI page can fill it into
		// the ready-made connection prompts (replaced by the next token, then
		// dropped on revoke or uninstall).
		set_transient( self::LAST_TOKEN_TRANSIENT, array( 'id' => $id, 'token' => $token ), 0 );

		return array(
			'id'         => $id,
			'token'      => $token,
			'prefix'     => $prefix,
			'name'       => $name,
			'read_only'  => $read_only,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Resolve a bearer token to its stored record, or null.
	 *
	 * @param string $bearer Raw Authorization value (without the "Bearer " part).
	 * @return array|null
	 */
	public static function authenticate( $bearer ) {
		global $wpdb;

		$bearer = trim( (string) $bearer );
		if ( ! preg_match( '/^' . self::TOKEN_PREFIX . '_([0-9a-f]{8})_([0-9a-f]{48})$/', $bearer, $matches ) ) {
			return null;
		}
		$prefix = $matches[1];

		$table = DB::tokens_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_prefix = %s LIMIT 1", $prefix ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( ! hash_equals( (string) $row['token_hash'], hash( 'sha256', $bearer ) ) ) {
			return null;
		}
		if ( ! empty( $row['revoked_at'] ) && '0000-00-00 00:00:00' !== $row['revoked_at'] ) {
			return null;
		}
		if ( self::is_expired( $row ) ) {
			return null;
		}

		$row['id']         = (int) $row['id'];
		$row['user_id']    = (int) $row['user_id'];
		$row['read_only']  = ! empty( $row['read_only'] );
		$row['token_hash'] = ''; // Never keep the hash in memory longer than needed.

		// Touch last-used at most once a minute.
		$last_used = isset( $row['last_used_at'] ) ? (string) $row['last_used_at'] : '';
		if ( Util::to_unix( $last_used ) < time() - 60 ) {
			$wpdb->update(
				$table,
				array( 'last_used_at' => Util::now() ),
				array( 'id' => $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
			$row['last_used_at'] = Util::now();
		}

		return $row;
	}

	/**
	 * Whether a stored row is past its expiry.
	 *
	 * @param array $row Token row.
	 * @return bool
	 */
	public static function is_expired( array $row ) {
		$expires = isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '';
		if ( '' === $expires || '0000-00-00 00:00:00' === $expires ) {
			return false;
		}
		return Util::to_unix( $expires ) < time();
	}

	/**
	 * Load a token record by id (hash stripped) for deferred re-validation,
	 * e.g. when a background job runs minutes after it was queued.
	 *
	 * @param int $id Token id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = DB::tokens_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id']         = (int) $row['id'];
		$row['user_id']    = (int) $row['user_id'];
		$row['read_only']  = ! empty( $row['read_only'] );
		$row['token_hash'] = '';
		return $row;
	}

	/**
	 * The most recently created token, while it is still active.
	 *
	 * Used only to pre-fill the quick-connect prompts on the Connect AI page.
	 * Returns '' (and drops the stored copy) when the token is gone, revoked
	 * or expired.
	 *
	 * @return string
	 */
	public static function last_for_prompts() {
		$stored = get_transient( self::LAST_TOKEN_TRANSIENT );
		if ( ! is_array( $stored ) || empty( $stored['id'] ) || empty( $stored['token'] ) ) {
			return '';
		}
		$row = self::get( (int) $stored['id'] );
		if (
			! is_array( $row )
			|| ( ! empty( $row['revoked_at'] ) && '0000-00-00 00:00:00' !== $row['revoked_at'] )
			|| self::is_expired( $row )
		) {
			delete_transient( self::LAST_TOKEN_TRANSIENT );
			return '';
		}
		return (string) $stored['token'];
	}

	/**
	 * Revoke one token.
	 *
	 * @param int $id Token id.
	 * @return bool
	 */
	public static function revoke( $id ) {
		global $wpdb;
		$updated = $wpdb->update(
			DB::tokens_table(),
			array( 'revoked_at' => Util::now() ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);

		$stored = get_transient( self::LAST_TOKEN_TRANSIENT );
		if ( is_array( $stored ) && isset( $stored['id'] ) && (int) $stored['id'] === (int) $id ) {
			delete_transient( self::LAST_TOKEN_TRANSIENT );
		}

		return false !== $updated && $updated > 0;
	}

	/**
	 * Revoke every active token.
	 *
	 * @return int Number of tokens revoked.
	 */
	public static function revoke_all() {
		global $wpdb;
		$table = DB::tokens_table();
		$count = $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET revoked_at = %s WHERE revoked_at IS NULL", Util::now() )
		);
		delete_transient( self::LAST_TOKEN_TRANSIENT );
		return max( 0, (int) $count );
	}

	/**
	 * All token records without hashes, newest first.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = DB::tokens_table();
		$rows  = $wpdb->get_results( "SELECT id, token_prefix, name, user_id, read_only, created_at, expires_at, last_used_at, revoked_at FROM {$table} ORDER BY id DESC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['id']        = (int) $row['id'];
			$row['user_id']   = (int) $row['user_id'];
			$row['read_only'] = ! empty( $row['read_only'] );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Number of usable (not revoked, not expired) tokens.
	 *
	 * @return int
	 */
	public static function active_count() {
		$count = 0;
		foreach ( self::all() as $row ) {
			if ( ! empty( $row['revoked_at'] ) && '0000-00-00 00:00:00' !== $row['revoked_at'] ) {
				continue;
			}
			if ( self::is_expired( $row ) ) {
				continue;
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Delete tokens that were revoked or expired more than 30 days ago.
	 *
	 * @return int
	 */
	public static function cleanup() {
		global $wpdb;
		$table  = DB::tokens_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS_STUB ) );
		$count  = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE (revoked_at IS NOT NULL AND revoked_at < %s) OR (expires_at IS NOT NULL AND expires_at < %s)",
				$cutoff,
				$cutoff
			)
		);
		return max( 0, (int) $count );
	}
}

/**
 * DAY_IN_SECONDS fallback for dependency-free contexts.
 */
if ( ! defined( 'DAY_IN_SECONDS_STUB' ) ) {
	define( 'DAY_IN_SECONDS_STUB', 86400 );
}
