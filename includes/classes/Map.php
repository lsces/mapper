<?php
/**
* @package mapper
* @author lsces <lester@lsces.co.uk>
* @version $Revision$
*/
namespace Bitweaver\Mapper;

use Bitweaver\Liberty\LibertyContent;
use Bitweaver\Liberty\LibertyMime;

define( 'MAPPER_CONTENT_TYPE_GUID', 'mapper' );

/**
* @package mapper
*
* A Map is a plain LibertyMime content item - title + one uploaded .map file. No own table:
* content_id is the sole identity (unlike FisheyeImage/StockAssembly's own image_id/assembly_id -
* those need an owned table for typed fields like width/height; Map's equivalent "extra fields"
* (EXTENT, SHAPEPATH, EXCL, per-layer LAYER rows) go through the generic liberty_xref tables
* instead - see admin/schema_inc.php and mapper/CLAUDE.md).
*/
class Map extends LibertyMime
{
	function __construct( $pContentId = null ) {
		parent::__construct();
		if( $this->verifyId( $pContentId ) ) {
			$this->mContentId = (int)$pContentId;
		}

		$this->registerContentType(
			MAPPER_CONTENT_TYPE_GUID, [
				'content_type_guid' => MAPPER_CONTENT_TYPE_GUID,
				'content_name'      => 'Map',
				'handler_class'     => 'Map',
				'handler_package'   => 'mapper',
				'handler_file'      => 'Map.php',
				'maintainer_url'    => 'https://www.bitweaver.org',
			],
		);

		$this->mViewContentPerm   = 'bit_p_view_mapper';
		$this->mUpdateContentPerm = 'bit_p_edit_mapper';
		$this->mAdminContentPerm  = 'bit_p_admin_mapper';
	}

	/**
	 * List query for list_maps.php - modeled on FisheyeImage::getList(), simplified since Map
	 * has no own table to join (no fisheye_image-equivalent - see class doc comment above).
	 */
	public function getList( &$pListHash ) {
		LibertyContent::prepGetList( $pListHash );
		$ret = $bindVars = [];
		$whereSql = '';
		$joinSql = '';

		if( @$this->verifyId( $pListHash['user_id'] ?? 0 ) ) {
			$whereSql .= " AND lc.`user_id` = ? ";
			$bindVars[] = $pListHash['user_id'];
		}

		if( !empty( $pListHash['search'] ) ) {
			$whereSql .= " AND UPPER(lc.`title`) LIKE ? ";
			$bindVars[] = '%'.strtoupper( $pListHash['search'] ).'%';
		}

		$this->getServicesSql( 'content_list_sql_function', $selectSql, $joinSql, $whereSql, $bindVars );

		$orderby = '';
		if( !empty( $pListHash['sort_mode'] ) ) {
			$orderby = " ORDER BY ".$this->mDb->convertSortmode( $pListHash['sort_mode'] )." ";
		}

		array_unshift( $bindVars, MAPPER_CONTENT_TYPE_GUID );
		$query = "SELECT lc.`content_id` AS `hash_key`, lc.*, la.`attachment_id`, lf.`file_name`, lf.`mime_type`, uu.`login`, uu.`real_name` $selectSql
				FROM `".BIT_DB_PREFIX."liberty_content` lc
					LEFT OUTER JOIN `".BIT_DB_PREFIX."liberty_attachments` la ON(la.`content_id`=lc.`content_id`)
					LEFT OUTER JOIN `".BIT_DB_PREFIX."liberty_files` lf ON(la.`foreign_id`=lf.`file_id`)
					INNER JOIN `".BIT_DB_PREFIX."users_users` uu ON(uu.`user_id` = lc.`user_id`) $joinSql
				WHERE lc.`content_type_guid` = ? $whereSql $orderby";

		if( $rows = $this->mDb->query( $query, $bindVars, $pListHash['max_records'], $pListHash['offset'], $pListHash['query_cache_time'] ) ) {
			foreach( $rows as $row ) {
				$row['hash_key'] = $row['content_id'];
				$ret[$row['hash_key']] = $row;
			}
		}
		return $ret;
	}

	/**
	 * Store the uploaded .map file, extracting NAME/EXTENT/SHAPEPATH/LAYER details from its own
	 * plain-text content into the 'general'/'layers' xref groups (see admin/schema_inc.php)
	 * along the way. No MIME-plugin dispatch involved - a Map upload is always a .map file by
	 * construction (this package's own dedicated upload flow), and finfo/libmagic can't
	 * distinguish a mapfile's plain-text content from any other text file anyway (confirmed
	 * empirically - no distinguishing binary signature exists, unlike PDF's %PDF- magic bytes).
	 *
	 * Parsing happens BEFORE LibertyMime::store(), not after - liberty_process_generic() renames
	 * the uploaded tmp file to its final storage path as part of storing (see liberty_lib.php),
	 * but never updates the 'source_file' reference to match, so anything read from that
	 * reference after store() completes is reading a path that no longer exists (found live,
	 * not guessed - mime.pdf.php's own mime_pdf_text_extract() avoids this same trap by running
	 * before mime_default_store(), same reasoning applies here).
	 */
	function store( array &$pParamHash ): bool {
		$uploads = $pParamHash['_files_override'] ?? [];
		$upload = reset( $uploads );
		$parsed = ( $upload && !empty( $upload['tmp_name'] ) && is_readable( $upload['tmp_name'] ) )
			? $this->parseMapFile( $upload['tmp_name'] )
			: null;

		if( $parsed && !empty( $parsed['name'] ) && empty( $pParamHash['title'] ) ) {
			$pParamHash['title'] = $parsed['name'];
		}

		$this->StartTrans();
		if( LibertyMime::store( $pParamHash ) ) {
			$this->mContentId = $pParamHash['content_id'];
			$this->mInfo['content_id'] = $this->mContentId;
			if( $parsed ) {
				$this->storeParsedMapFileDetails( $parsed, $pParamHash );
			}
			$this->CompleteTrans();
			$this->load();
			return true;
		}
		$this->RollbackTrans();
		return false;
	}

	/**
	 * Parse a MapServer .map file's own block structure. Every real block-opening keyword
	 * (LAYER, METADATA, CLASS, STYLE, LABEL, PROJECTION, WEB, LEGEND, SCALEBAR, REFERENCE, ...)
	 * appears bare, alone on its own line, in MapServer's mapfile grammar - directives always
	 * carry an inline value (NAME foo, STATUS ON). That distinction is enough to track block
	 * depth correctly (including nested blocks inside a LAYER) without needing to know the
	 * full keyword set MapServer itself recognises. Pure parser - no DB access, no side effects.
	 */
	private function parseMapFile( string $pSourceFile ): array {
		$lines = file( $pSourceFile, FILE_IGNORE_NEW_LINES );
		if( !$lines ) {
			return [ 'name' => null, 'extent' => null, 'shapePath' => null, 'layers' => [] ];
		}

		$name = $extent = $shapePath = null;
		$layers = [];
		$depth = 0;
		$inLayer = false;
		$layerDepth = 0;
		$currentLayer = null;

		foreach( $lines as $line ) {
			$trim = trim( $line );
			if( $trim === '' || $trim[0] === '#' ) {
				continue;
			}

			if( preg_match( '/^([A-Z_]+)\s*$/', $trim, $m ) ) {
				$keyword = $m[1];
				if( $keyword === 'MAP' ) {
					// the file's own outermost wrapper - not a nested block in the sense we
					// care about tracking; top-level directives are everything inside it, so
					// depth 0 means "directly inside MAP", not "outside everything".
					continue;
				}
				if( $keyword === 'END' ) {
					if( $inLayer && $depth === $layerDepth ) {
						$layers[] = $currentLayer;
						$currentLayer = null;
						$inLayer = false;
					}
					if( $depth > 0 ) { $depth--; }
					continue;
				}
				$depth++;
				if( $keyword === 'LAYER' && !$inLayer ) {
					$inLayer = true;
					$layerDepth = $depth;
					$currentLayer = [ 'name' => null, 'type' => null, 'status' => null, 'group' => null ];
				}
				continue;
			}

			if( !preg_match( '/^([A-Z_]+)\s+(.+)$/', $trim, $m ) ) {
				continue;
			}
			[ , $keyword, $value ] = $m;
			$value = trim( $value, "\"' \t" );

			if( $inLayer && $depth === $layerDepth ) {
				switch( $keyword ) {
					case 'NAME':   $currentLayer['name']   = $value; break;
					case 'TYPE':   $currentLayer['type']   = $value; break;
					case 'STATUS': $currentLayer['status'] = $value; break;
					case 'GROUP':  $currentLayer['group']  = $value; break;
				}
			} elseif( $depth === 0 ) {
				if( $name === null && $keyword === 'NAME' )           { $name = $value; }
				if( $extent === null && $keyword === 'EXTENT' )       { $extent = $value; }
				if( $shapePath === null && $keyword === 'SHAPEPATH' ) { $shapePath = $value; }
			}
		}

		return [ 'name' => $name, 'extent' => $extent, 'shapePath' => $shapePath, 'layers' => $layers ];
	}

	/** Write the already-parsed details (see parseMapFile()) into the xref tables and retag the
	 * stored file's mime type - pure DB work, no file access, safe to run after store(). */
	private function storeParsedMapFileDetails( array $pParsed, array $pParamHash ): void {
		[ 'name' => $name, 'extent' => $extent, 'shapePath' => $shapePath, 'layers' => $layers ] = $pParsed;

		if( $extent !== null ) {
			$parts = preg_split( '/\s+/', $extent );
			if( count( $parts ) === 4 ) {
				$this->upsertSingleXref( 'general', 'EXTENT', json_encode( [
					'minX' => (float)$parts[0], 'minY' => (float)$parts[1],
					'maxX' => (float)$parts[2], 'maxY' => (float)$parts[3],
				] ) );
			}
		}

		if( $shapePath !== null ) {
			$this->upsertSingleXref( 'general', 'SHPPATH', $shapePath );
		}

		if( array_key_exists( 'excl', $pParamHash ) ) {
			$this->upsertSingleXref( 'general', 'EXCL', $pParamHash['excl'] ? '1' : '0' );
		} else {
			// Only set a default the first time - don't clobber an admin's later edit on re-upload
			$existing = $this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'EXCL'", [ $this->mContentId ] );
			if( empty( $existing ) ) {
				$this->upsertSingleXref( 'general', 'EXCL', '1' );
			}
		}

		// Layers are wholly replaced on (re-)upload - a new mapfile's layer set may differ in
		// membership/order entirely, unlike the single-value 'general' items which upsert in place.
		$this->mDb->query( "DELETE FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'LAYER'", [ $this->mContentId ] );
		foreach( $layers as $i => $layer ) {
			if( empty( $layer['name'] ) ) {
				continue;
			}
			$xrefHash = [
				'content_id' => $this->mContentId,
				'item'       => 'LAYER',
				'xkey'       => $layer['name'],
				'xkey_ext'   => $layer['name'],
				'edit'       => json_encode( [
					'type'      => $layer['type'],
					'status'    => $layer['status'],
					'group'     => $layer['group'],
					'visible'   => ( $layer['status'] === 'ON' ) ? 1 : 0,
					'queryable' => false,
					'link'      => 0,
				] ),
				'xorder'     => $i,
			];
			$this->storeXref( $xrefHash );
		}

		// Retag the stored file's mime type now that it's confirmed to be a real mapfile -
		// finfo/libmagic can only ever call plain-text content 'text/plain' (see class doc
		// comment above); this is the only point anything can usefully tag it more precisely.
		if( !empty( $name ) || $extent !== null ) {
			$fileId = $this->mDb->getOne(
				"SELECT lf.`file_id` FROM `".BIT_DB_PREFIX."liberty_files` lf INNER JOIN `".BIT_DB_PREFIX."liberty_attachments` la ON(la.`foreign_id`=lf.`file_id`) WHERE la.`content_id` = ?",
				[ $this->mContentId ]
			);
			if( $fileId ) {
				$this->mDb->query( "UPDATE `".BIT_DB_PREFIX."liberty_files` SET `mime_type` = ? WHERE `file_id` = ?", [ 'text/x-mapfile', $fileId ] );
			}
		}
	}

	/** Update-in-place for a multiple=0 xref item - looks up any existing row for
	 * (content_id, item) first so a re-upload updates rather than duplicates it. */
	private function upsertSingleXref( string $pGroup, string $pItem, string $pValue ): void {
		$existingXrefId = $this->mDb->getOne( "SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ?", [ $this->mContentId, $pItem ] );
		$xrefHash = [
			'content_id' => $this->mContentId,
			'item'       => $pItem,
			'xkey'       => $pItem,
			'edit'       => $pValue,
			'xorder'     => 0,
		];
		if( $existingXrefId ) {
			$xrefHash['xref_id'] = $existingXrefId;
		}
		$this->storeXref( $xrefHash );
	}
}
