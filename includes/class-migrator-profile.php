<?php
/**
 * Profile — describes what to include in an export.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrator_Profile {

	public bool $include_database   = true;
	public bool $include_uploads    = true;
	public bool $include_themes     = false;
	public bool $include_plugins    = false;
	public bool $include_mu_plugins = false;

	public bool $db_skip_spam       = false;
	public bool $db_skip_revisions  = false;
	public bool $db_skip_trash      = false;
	public bool $db_skip_transients = false;

	/** @var string[] glob patterns (e.g. "uploads/cache/", "uploads/backup-*") */
	public array $file_excludes = array();

	/** @var string[] fnmatch patterns matched against full table names (e.g. "*wfFileMods", "*woocommerce_sessions") */
	public array $table_skip_patterns = array();

	public static function from_post( array $post ): self {
		$p = new self();

		$p->include_database   = ! empty( $post['include_database'] );
		$p->include_uploads    = ! empty( $post['include_uploads'] );
		$p->include_themes     = ! empty( $post['include_themes'] );
		$p->include_plugins    = ! empty( $post['include_plugins'] );
		$p->include_mu_plugins = ! empty( $post['include_mu_plugins'] );

		$p->db_skip_spam       = ! empty( $post['db_skip_spam'] );
		$p->db_skip_revisions  = ! empty( $post['db_skip_revisions'] );
		$p->db_skip_trash      = ! empty( $post['db_skip_trash'] );
		$p->db_skip_transients = ! empty( $post['db_skip_transients'] );

		if ( ! empty( $post['file_excludes'] ) ) {
			$raw = is_array( $post['file_excludes'] )
				? implode( "\n", $post['file_excludes'] )
				: (string) $post['file_excludes'];

			$lines = preg_split( '/\r?\n/', $raw );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$p->file_excludes[] = $line;
				}
			}
		}

		if ( ! empty( $post['table_skip_patterns'] ) ) {
			$raw = is_array( $post['table_skip_patterns'] )
				? implode( "\n", $post['table_skip_patterns'] )
				: (string) $post['table_skip_patterns'];

			$lines = preg_split( '/\r?\n/', $raw );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$p->table_skip_patterns[] = $line;
				}
			}
		}

		return $p;
	}

	/**
	 * Returns true if the (already-prefixed) table name matches any skip pattern.
	 * Patterns use fnmatch syntax (* ? [abc]). Matching is case-insensitive
	 * because Wordfence-style tables use camelCase suffixes (wfFileMods).
	 */
	public function table_matches_skip( string $table_name ): bool {
		foreach ( $this->table_skip_patterns as $pattern ) {
			if ( fnmatch( $pattern, $table_name, FNM_CASEFOLD ) ) {
				return true;
			}
		}
		return false;
	}

	public static function from_array( array $data ): self {
		$p = new self();
		foreach ( get_object_vars( $p ) as $key => $default ) {
			if ( array_key_exists( $key, $data ) ) {
				$p->$key = $data[ $key ];
			}
		}
		return $p;
	}

	public function to_array(): array {
		return get_object_vars( $this );
	}

	public function file_matches_exclude( string $relative_path ): bool {
		foreach ( $this->file_excludes as $pattern ) {
			if ( fnmatch( $pattern, $relative_path ) ) {
				return true;
			}
			// Also match if pattern is a directory prefix (no wildcards).
			if ( false === strpbrk( $pattern, '*?[' ) && 0 === strpos( $relative_path, rtrim( $pattern, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the table → WHERE clause map for paginated dump.
	 * Tables not listed → no WHERE filter.
	 *
	 * @return array<string,string> Map of table-suffix (without prefix) to WHERE clause.
	 */
	public function db_where_clauses(): array {
		global $wpdb;
		$prefix  = $wpdb->prefix;
		$clauses = array();

		// Skip-posts subquery used by both posts and postmeta.
		$skip_post_conditions = array();
		if ( $this->db_skip_revisions ) {
			$skip_post_conditions[] = "post_type = 'revision'";
		}
		if ( $this->db_skip_trash ) {
			$skip_post_conditions[] = "post_status = 'trash'";
		}

		if ( ! empty( $skip_post_conditions ) ) {
			$skip_sql              = '(SELECT ID FROM `' . $prefix . 'posts` WHERE ' . implode( ' OR ', $skip_post_conditions ) . ')';
			$clauses['posts']      = 'ID NOT IN ' . $skip_sql;
			$clauses['postmeta']   = 'post_id NOT IN ' . $skip_sql;
		}

		if ( $this->db_skip_spam ) {
			$clauses['comments']    = "comment_approved != 'spam'";
			// commentmeta references comments — skip orphans.
			$clauses['commentmeta'] = "comment_id NOT IN (SELECT comment_ID FROM `{$prefix}comments` WHERE comment_approved = 'spam')";
		}

		if ( $this->db_skip_transients ) {
			$clauses['options'] = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		}

		return $clauses;
	}

	/**
	 * Top-level wp-content directories to include based on profile.
	 *
	 * @return array<string,string> Map of label → absolute path.
	 */
	public function content_dirs(): array {
		$dirs = array();
		if ( $this->include_uploads ) {
			$dirs['uploads'] = wp_upload_dir()['basedir'];
		}
		if ( $this->include_themes ) {
			$dirs['themes'] = WP_CONTENT_DIR . '/themes';
		}
		if ( $this->include_plugins ) {
			$dirs['plugins'] = WP_PLUGIN_DIR;
		}
		if ( $this->include_mu_plugins && defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$dirs['mu-plugins'] = WPMU_PLUGIN_DIR;
		}
		return $dirs;
	}
}
