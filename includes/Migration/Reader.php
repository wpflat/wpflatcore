<?php
namespace BizyCore\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only access to an old BizyDash backup (a .bizydash ZIP: manifest.json, options.json, data/<table>.ndjson,
 * files/<kind>/…). Nothing is extracted; rows are streamed one line at a time, so even a big backup stays light.
 * Modules use it to pick up their own old tables: `$reader->rows( 'invoices' )`.
 */
class Reader {

	/** @var \ZipArchive */
	private $zip;
	public $manifest = array();
	public $options  = array();
	public $entries  = array();
	public $path;

	/** Only these entries may exist in an old backup; anything else means the file is not what it claims. */
	public static function entry_ok( $name ) {
		if ( '' === $name || strlen( $name ) > 300 || false !== strpos( $name, '..' ) || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) || '/' === $name[0] ) {
			return false;
		}
		if ( in_array( $name, array( 'manifest.json', 'options.json' ), true ) || preg_match( '#^data/[a-z_]{1,60}\.ndjson$#', $name ) ) {
			return true;
		}
		if ( ! preg_match( '#^files/(contract-pdfs|uploads|message-files|public)/[A-Za-z0-9_\-. ()+%/]+$#', $name ) || '/' === substr( $name, -1 ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $seg ) {
			if ( '' === $seg || '.' === $seg[0] ) {
				return false;
			}
		}
		return ! preg_match( '/\.(php\d?|phtml|phar|pht|phps|htaccess|htpasswd|ini|sh|cgi|pl|py|exe)(\.|$)/i', $name );
	}

	/** @return Reader|\WP_Error */
	public static function open( $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'bizycore_zip', 'This server has no zip support (PHP ZipArchive), so backups cannot be read.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'bizycore_format', 'This file is not a valid BizyDash backup (it could not be opened as an archive).' );
		}
		$r          = new self();
		$r->zip     = $zip;
		$r->path    = $path;
		$total      = 0;
		if ( $zip->numFiles > 20000 ) {
			$zip->close();
			return new \WP_Error( 'bizycore_limits', 'This backup has too many files to be a normal BizyDash backup.' );
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$st = $zip->statIndex( $i );
			if ( '/' === substr( $st['name'], -1 ) ) {
				continue;
			}
			if ( ! self::entry_ok( $st['name'] ) ) {
				$zip->close();
				return new \WP_Error( 'bizycore_unsafe', 'This backup contains an entry that is not allowed (“' . substr( $st['name'], 0, 80 ) . '”), so it was not opened.' );
			}
			$total                     += (int) $st['size'];
			$r->entries[ $st['name'] ] = (int) $st['size'];
		}
		if ( $total > 4294967296 ) {
			$zip->close();
			return new \WP_Error( 'bizycore_limits', 'This backup is larger than the size this plugin will read.' );
		}
		$man = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		if ( ! is_array( $man ) || empty( $man['format_version'] ) || ! isset( $man['modules'] ) ) {
			$zip->close();
			return new \WP_Error( 'bizycore_format', 'This archive is not a BizyDash backup (its manifest is missing or unreadable).' );
		}
		$r->manifest = $man;
		$opt         = json_decode( (string) $zip->getFromName( 'options.json' ), true );
		$r->options  = is_array( $opt ) ? $opt : array();
		return $r;
	}

	public function close() {
		if ( $this->zip ) {
			$this->zip->close();
			$this->zip = null;
		}
	}

	/** Every table in the backup with its row count. */
	public function tables() {
		$out = array();
		foreach ( $this->manifest['modules'] as $m ) {
			foreach ( (array) $m['tables'] as $t => $info ) {
				$out[ $t ] = (int) $info['rows'];
			}
		}
		return $out;
	}

	public function count( $table ) {
		$t = $this->tables();
		return isset( $t[ $table ] ) ? $t[ $table ] : 0;
	}

	/** Rows of one old table, in id order, as arrays of strings. @return \Generator */
	public function rows( $table ) {
		if ( ! preg_match( '/^[a-z_]{1,60}$/', $table ) || ! isset( $this->entries[ 'data/' . $table . '.ndjson' ] ) ) {
			return;
		}
		$h = $this->zip->getStream( 'data/' . $table . '.ndjson' );
		if ( ! $h ) {
			return;
		}
		while ( false !== ( $line = fgets( $h ) ) ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $k => $v ) {
				if ( is_array( $v ) && isset( $v['__b64'] ) ) {
					$row[ $k ] = base64_decode( $v['__b64'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				}
			}
			yield $row;
		}
		fclose( $h );
	}

	public function has_file( $entry ) {
		return isset( $this->entries[ $entry ] );
	}

	/** Copy one file out of the archive. */
	public function extract_file( $entry, $dest ) {
		if ( ! $this->has_file( $entry ) ) {
			return false;
		}
		$in  = $this->zip->getStream( $entry );
		$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			return false;
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		return true;
	}

	/** Entries under a folder, e.g. 'files/message-files/'. */
	public function files_in( $prefix ) {
		return array_values( array_filter( array_keys( $this->entries ), function ( $n ) use ( $prefix ) {
			return 0 === strpos( $n, $prefix );
		} ) );
	}
}
