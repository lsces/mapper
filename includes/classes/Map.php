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
	/**
	 * getNewObject() (the generic factory behind getLibertyObject(), used by index.php's
	 * content_id-based dispatch among others) always calls `new $class( null, $contentId )` -
	 * a two-argument convention shared by every content type, even ones like Map that have no
	 * separate "own id" field. $pContentId falling back to $pMapId (unused otherwise) is what
	 * makes both `new Map( $contentId )` (this package's own direct calls) and the generic
	 * `new Map( null, $contentId )` dispatch resolve correctly - found live: without this,
	 * the generic path silently dropped content_id entirely, matching StockAssembly's own
	 * `$pContentId = $pContentId ?? $pAssemblyId;` fallback for the same reason.
	 */
	function __construct( $pMapId = null, $pContentId = null ) {
		parent::__construct();
		$pContentId = $pContentId ?? $pMapId;
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

	/** view.php is the metadata/info page (title, description, layers, extent) - separate from
	 * actually rendering the interactive map (display_map.php/display_map2.php), which still
	 * only resolve mapsets from the old registry's string key, not a content_id yet. */
	public function getDisplayUrl() {
		return MAPPER_PKG_URL.'view.php?content_id='.$this->mContentId;
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
	 * Modeled on FisheyeImage::load() (a confirmed-fixed protector call site, not StockAssembly's
	 * pattern), simplified since Map has no own table to join. Wiring getServicesSql( ...,
	 * 'content_load_sql_function', ..., $this ) in here is what makes per-map role protection
	 * (via the protector package) actually enforced rather than just stored and silently
	 * ignored - see protector/CLAUDE.md's "Permission check was unreachable" writeup; the base
	 * LibertyContent::load()/LibertyMime::load() never call this hook themselves, only a
	 * subclass's own load() override does.
	 */
	public function load( $pContentId = null, $pPluginParams = null ) {
		if( $this->verifyId( $pContentId ) ) {
			$this->mContentId = (int)$pContentId;
		}
		if( !$this->verifyId( $this->mContentId ) ) {
			return false;
		}

		$selectSql = $joinSql = '';
		$whereSql = " WHERE lc.`content_id` = ? AND lc.`content_type_guid` = ?";
		$bindVars = [ $this->mContentId, MAPPER_CONTENT_TYPE_GUID ];

		$this->getServicesSql( 'content_load_sql_function', $selectSql, $joinSql, $whereSql, $bindVars, $this );

		$sql = "SELECT lc.* $selectSql
					, uue.`login` AS `modifier_user`, uue.`real_name` AS `modifier_real_name`
					, uuc.`login` AS `creator_user`, uuc.`real_name` AS `creator_real_name`
				FROM `".BIT_DB_PREFIX."liberty_content` lc
					LEFT JOIN `".BIT_DB_PREFIX."users_users` uue ON (uue.`user_id` = lc.`modifier_user_id`)
					LEFT JOIN `".BIT_DB_PREFIX."users_users` uuc ON (uuc.`user_id` = lc.`user_id`) $joinSql
				$whereSql";

		if( !( $this->mInfo = $this->mDb->getRow( $sql, $bindVars ) ) ) {
			return false;
		}
		$this->mContentId = $this->mInfo['content_id'];
		$this->mInfo['creator'] = $this->mInfo['creator_real_name'] ?? $this->mInfo['creator_user'];
		$this->mInfo['editor']  = $this->mInfo['modifier_real_name'] ?? $this->mInfo['modifier_user'];

		// LibertyMime loads attachment details into $this->mStorage
		parent::load();

		if( !empty( $this->mStorage ) ) {
			$this->mInfo['map_file'] = current( $this->mStorage );
		}

		return true;
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

		if( $parsed && empty( $pParamHash['title'] ) ) {
			// "Demo" is a real, pre-existing leftover in several already-deployed private
			// mapfiles (copy-paste template artifact, not something this parser invented -
			// confirmed against the actual files) - falls back to the uploaded filename
			// (without extension) instead of propagating a meaningless shared title into
			// every object that happens to still carry it.
			$name = $parsed['name'];
			if( !empty( $name ) && strtolower( $name ) !== 'demo' ) {
				$pParamHash['title'] = $name;
			} elseif( !empty( $upload['name'] ) ) {
				$pParamHash['title'] = pathinfo( $upload['name'], PATHINFO_FILENAME );
			}
		}

		$this->StartTrans();
		if( LibertyMime::store( $pParamHash ) ) {
			$this->mContentId = $pParamHash['content_id'];
			$this->mInfo['content_id'] = $this->mContentId;
			if( $parsed ) {
				$this->storeParsedMapFileDetails( $parsed, $pParamHash );
			} elseif( array_key_exists( 'excl', $pParamHash ) ) {
				// Plain edit, no new file - EXCL still needs to be independently toggleable
				// from the edit page, not just settable via upload (file comment or checkbox).
				$this->upsertSingleXref( 'general', 'EXCL', $pParamHash['excl'] ? '1' : '0' );
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
			return [ 'name' => null, 'extent' => null, 'shapePath' => null, 'excl' => null, 'layers' => [] ];
		}

		$name = $extent = $shapePath = $excl = null;
		$layers = [];
		$depth = 0;
		$inLayer = false;
		$layerDepth = 0;
		$currentLayer = null;

		foreach( $lines as $line ) {
			$trim = trim( $line );
			if( $trim === '' ) {
				continue;
			}
			if( $trim[0] === '#' ) {
				// Not real MapServer syntax - an optional, namespaced convention this parser
				// specifically recognises so EXCL can travel with the file itself rather than
				// depending on a per-upload form checkbox (which can't sensibly apply one
				// value across a whole batch-archive import - see mapper/CLAUDE.md).
				if( $excl === null && preg_match( '/^#\s*MAPPER:\s*EXCL\s*=\s*(true|false|1|0)\s*$/i', $trim, $m ) ) {
					$excl = in_array( strtolower( $m[1] ), [ 'true', '1' ], true );
				}
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
					$currentLayer = [ 'name' => null, 'type' => null, 'status' => null, 'group' => null, 'data' => null ];
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
					// DATA identifies XYZ/GDAL-WMS-backed layers (ends in _gdalwms.xml) vs
					// classic vector/raster ones reading straight off SHAPEPATH - see
					// display_map2.php's content_id resolution path.
					case 'DATA':   $currentLayer['data']   = $value; break;
				}
			} elseif( $depth === 0 ) {
				if( $name === null && $keyword === 'NAME' )           { $name = $value; }
				if( $extent === null && $keyword === 'EXTENT' )       { $extent = $value; }
				if( $shapePath === null && $keyword === 'SHAPEPATH' ) { $shapePath = $value; }
			}
		}

		return [ 'name' => $name, 'extent' => $extent, 'shapePath' => $shapePath, 'excl' => $excl, 'layers' => $layers ];
	}

	/** Write the already-parsed details (see parseMapFile()) into the xref tables and retag the
	 * stored file's mime type - pure DB work, no file access, safe to run after store(). */
	private function storeParsedMapFileDetails( array $pParsed, array $pParamHash ): void {
		[ 'name' => $name, 'extent' => $extent, 'shapePath' => $shapePath, 'excl' => $excl, 'layers' => $layers ] = $pParsed;

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

		// The mapfile's own "# MAPPER: EXCL=..." comment (if present) wins over the upload
		// form's checkbox - it's the only signal that works sensibly for a batch-archive
		// import (one checkbox can't apply correctly across many unrelated mapfiles at once).
		if( $excl !== null ) {
			$this->upsertSingleXref( 'general', 'EXCL', $excl ? '1' : '0' );
		} elseif( array_key_exists( 'excl', $pParamHash ) ) {
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
					'data'      => $layer['data'],
					'visible'   => ( $layer['status'] === 'ON' ) ? 1 : 0,
					'queryable' => false,
					'link'      => 0,
				] ),
				'xorder'     => $i,
			];
			$this->storeXref( $xrefHash );
		}

		// Retag the stored file's mime type, and fix its name back to .map - both now that it's
		// confirmed to be a real mapfile. finfo/libmagic can only ever sniff plain-text content
		// as 'text/plain' (see class doc comment above), and liberty_process_generic() rewrites
		// the extension to match that sniffed type since it doesn't match the (unregistered)
		// .map extension's own looked-up type - the same class of correction mime.pdf.php makes
		// for its own files (attachment_plugin_guid, thumbnails, etc), just done directly here
		// since Map has no MIME plugin of its own (see store()'s own doc comment for why).
		if( !empty( $name ) || $extent !== null ) {
			$fileRow = $this->mDb->getRow(
				"SELECT lf.`file_id`, lf.`file_name` FROM `".BIT_DB_PREFIX."liberty_files` lf INNER JOIN `".BIT_DB_PREFIX."liberty_attachments` la ON(la.`foreign_id`=lf.`file_id`) WHERE la.`content_id` = ?",
				[ $this->mContentId ]
			);
			if( $fileRow ) {
				$this->mDb->query( "UPDATE `".BIT_DB_PREFIX."liberty_files` SET `mime_type` = ? WHERE `file_id` = ?", [ 'text/x-mapfile', $fileRow['file_id'] ] );

				$uploadedFiles = $pParamHash['upload_store']['files'] ?? [];
				$uploadedFile = reset( $uploadedFiles );
				$destBranch = $uploadedFile['upload']['dest_branch'] ?? null;
				if( $destBranch ) {
					$finalName = $fileRow['file_name'];
					if( !preg_match( '/\.map$/i', $finalName ?? '' ) ) {
						$correctedName = preg_replace( '/\.[^.]+$/', '.map', $finalName );
						$oldPath = STORAGE_PKG_PATH.$destBranch.$finalName;
						$newPath = STORAGE_PKG_PATH.$destBranch.$correctedName;
						if( is_file( $oldPath ) && rename( $oldPath, $newPath ) ) {
							$this->mDb->query( "UPDATE `".BIT_DB_PREFIX."liberty_files` SET `file_name` = ? WHERE `file_id` = ?", [ $correctedName, $fileRow['file_id'] ] );
							$finalName = $correctedName;
						}
					}
					// SYMBOLSET/FONTSET use relative paths in every mapfile in this project
					// ("../symbols/font.list"), resolved by MapServer against wherever the file
					// physically sits - fine for the old registry (always mapper/map/, a fixed
					// offset from mapper/symbols/), broken for a Map's attachment storage
					// location (varies per content_id, no stable relative offset back).
					// Rewritten to absolute paths so the stored copy is self-sufficient
					// regardless of where it physically ends up - same allowed correction as the
					// filename/mimetype fixes above.
					$this->fixSymbolPaths( STORAGE_PKG_PATH.$destBranch.$finalName );
				}
			}
		}
	}

	/** Rewrite relative SYMBOLSET/FONTSET directives to absolute paths pointing at the real,
	 * fixed mapper/symbols/ location - see storeParsedMapFileDetails()'s call site. */
	private function fixSymbolPaths( string $pFilePath ): void {
		$content = file_get_contents( $pFilePath );
		if( $content === false ) {
			return;
		}
		$fixed = preg_replace_callback(
			'/^(\s*(?:SYMBOLSET|FONTSET)\s+")([^"]+)(")/mi',
			function( $m ) {
				$path = $m[2];
				if( $path === '' || $path[0] === '/' ) {
					return $m[0];
				}
				return $m[1].MAPPER_PKG_PATH.'symbols/'.basename( $path ).$m[3];
			},
			$content
		);
		if( $fixed !== null && $fixed !== $content ) {
			file_put_contents( $pFilePath, $fixed );
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
