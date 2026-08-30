<?php
/**
* @package mapper
* @author lsces <lester@lsces.co.uk>
* @version $Revision$
*/
namespace Bitweaver\Mapper;

use Bitweaver\Liberty\LibertyContent;
use Bitweaver\Liberty\LibertyMime;
use Bitweaver\KernelTools;

define( 'MAPPER_CONTENT_TYPE_GUID', 'mapper' );

/**
* @package mapper
*
* A Map is a plain LibertyMime content item - title + one uploaded .map file. No own table:
* content_id is the sole identity (unlike FisheyeImage/StockAssembly's own image_id/assembly_id -
* those need an owned table for typed fields like width/height; Map's equivalent "extra fields"
* (EXTENT, SHAPEPATH, EXCL, per-layer LAYER rows) go through the generic Xref system
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
		return static::getDisplayUrlFromHash( $this->mInfo );
	}

	/**
	 * Static hash-driven equivalent of getDisplayUrl() - used by getContentList()'s generic
	 * list-building (see LibertyContent::getContentList(), which calls
	 * $type['handler_class']::getDisplayLinkFromHash() per row rather than loading a full Map
	 * object for each one). Without this override, list_maps.php's links fell back to the
	 * generic LibertyContent::getDisplayLinkFromHash() default (a plain /index.php?content_id=
	 * top-level dispatch) instead of going straight to view.php - same pattern FisheyeImage
	 * already uses for its own getDisplayUrlFromHash()/getDisplayLinkFromHash() pair.
	 */
	public static function getDisplayUrlFromHash( &$pParamHash ) {
		// Pretty /mapper/view/<name> URL (see bitweaver_rewrites.conf), matching the existing
		// /mapper/map/<name> and /mapper/map2/<name> pattern - falls back to the plain
		// ?content_id= form only if the title can't slugify to anything (empty title).
		$slug = static::slugify( (string)( $pParamHash['title'] ?? '' ) );
		if( $slug !== '' ) {
			return MAPPER_PKG_URL.'view/'.$slug;
		}
		return MAPPER_PKG_URL.'view.php?content_id='.( $pParamHash['content_id'] ?? '' );
	}

	public static function getDisplayLinkFromHash( &$pParamHash, $pTitle = '', $pAnchor = null ) {
		$pTitle = trim( (string)$pTitle );
		if( empty( $pTitle ) ) {
			$pTitle = $pParamHash['title'] ?? KernelTools::tra( 'No Title' );
		}
		return '<a title="'.htmlspecialchars( $pTitle ).'" href="'.static::getDisplayUrlFromHash( $pParamHash ).'">'.htmlspecialchars( $pTitle ).'</a>';
	}

	/**
	 * Lowercase, alnum+underscore slug derived from a title - used for pretty mapset= URLs
	 * (/mapper/map/<name>, see webstack's nginx/conf.d/bitweaver_rewrites.conf). Computed on
	 * the fly from title rather than stored as its own field - simplest option while this is
	 * still actively under development (see mapper/CLAUDE.md); worth revisiting only if link
	 * stability across later title edits becomes a real concern.
	 */
	public static function slugify( string $pTitle ): string {
		$slug = strtolower( trim( $pTitle ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '_', $slug );
		return trim( $slug, '_' );
	}

	/**
	 * Resolve a slug back to a content_id - a small linear scan over every Map's title, fine
	 * at this project's real scale (dozens of mapsets, not thousands - see mapper/CLAUDE.md).
	 * No explicit collision handling against the old registry's own keys needed: this is only
	 * ever tried before the registry fallback (see resolve_mapset_inc.php), so a real Map
	 * object naturally supersedes a stale array entry sharing the same name - exactly the
	 * intended migration behaviour, not something to guard against.
	 */
	public static function lookupBySlug( string $pSlug ): ?int {
		global $gBitDb;
		if( $pSlug === '' ) {
			return null;
		}
		$rows = $gBitDb->getAssoc( "SELECT `content_id`, `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_type_guid` = ?", [ MAPPER_CONTENT_TYPE_GUID ] );
		foreach( $rows as $contentId => $title ) {
			if( static::slugify( (string)$title ) === $pSlug ) {
				return (int)$contentId;
			}
		}
		return null;
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

		// Populates $this->mXrefInfo (EXTENT/SHPPATH/EXCL/OVERVIEWHEIGHT/LAYER all live in
		// normal, sort_order>0 display groups - unlike Contact's type-marker items, these
		// belong in the normal loaded-object state) so xref reads/writes below can go
		// through it instead of querying Xref directly.
		$this->loadXrefInfo();

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
	/**
	 * LibertyMime::expunge() only cleans up attachments - it deliberately does NOT call
	 * parent::expunge(), so calling it alone leaves the liberty_content row (and its history,
	 * xrefs, permissions, favorites - everything LibertyContent::expunge() actually handles)
	 * behind. Found live: edit.php's delete=1 flow reported success (redirected cleanly) but the
	 * content object was still there afterwards. Stock's own components get this for free since
	 * StockComponent extends LibertyContent directly (no attachment involved) - Map needs both
	 * halves explicitly since it extends LibertyMime.
	 */
	public function expunge(): bool {
		LibertyMime::expunge();
		return LibertyContent::expunge();
	}

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
			}

			// overview_height - display_map2.php's per-mapset overview-box height override (see
			// that file's own doc comment on overviewHeight; the old registry array had this as
			// a static per-mapset key, real Map objects never got an equivalent until now, so
			// every one silently fell back to the fixed 150px default). No mapfile-comment
			// equivalent (unlike EXCL) - always a plain edit-form field, independent of upload.
			if( array_key_exists( 'overview_height', $pParamHash ) ) {
				$height = (int)$pParamHash['overview_height'];
				if( $height > 0 ) {
					$this->upsertSingleXref( 'general', 'OVERVIEWHEIGHT', (string)$height, true );
				} else {
					// Blank/zero means "use the default" - clear any existing override rather
					// than storing a meaningless 0.
					$this->removeXrefItem( 'OVERVIEWHEIGHT' );
				}
			}
			$this->CompleteTrans();
			$this->load();
			return true;
		}
		$this->RollbackTrans();
		return false;
	}

	/**
	 * Re-parse whatever's currently on disk at this Map's own attachment path and re-sync the
	 * xref rows from it - for when the file itself was edited directly on the server (SSH, not
	 * through this edit page), which is routine for a real Map's mapfile since MapServer paths/
	 * layers/styling are hand-tuned far more often than they're re-uploaded wholesale. Without
	 * this, the database silently drifts from the file: layers added by hand never appear in the
	 * xref-driven layer toggle list (see mapper/CLAUDE.md, found live 2026-08-09 building the
	 * deleted-content mapset). Shares parseMapFile()/storeParsedMapFileDetails() with store()'s
	 * own upload path, so it has the exact same "layers wholly replaced, queryable flags reset"
	 * behaviour as a normal re-upload - not a new limitation, just reachable without a file input.
	 */
	function reloadFromDisk(): bool {
		$sourceFile = $this->getSourceFile( $this->mInfo['map_file'] ?? [] );
		if( !$sourceFile || !is_readable( $sourceFile ) ) {
			$this->mErrors[] = KernelTools::tra( 'Map file not found on disk or not readable.' );
			return false;
		}
		$parsed = $this->parseMapFile( $sourceFile );
		$this->StartTrans();
		$this->storeParsedMapFileDetails( $parsed, [ 'content_id' => $this->mContentId ] );
		$this->CompleteTrans();
		$this->load();
		return true;
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
			return [ 'name' => null, 'extent' => null, 'shapePath' => null, 'excl' => null, 'projection' => null, 'layers' => [] ];
		}

		$name = $extent = $shapePath = $excl = $projection = null;
		$layers = [];
		$depth = 0;
		$inLayer = false;
		$layerDepth = 0;
		$currentLayer = null;
		$inProjection = false;
		$projectionDepth = 0;

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

			// PROJECTION's own body is bare quoted PROJ.4 param strings, one per line
			// ("init=epsg:27700") - these match neither the bare-keyword nor KEYWORD-value
			// patterns below (no leading A-Z identifier), so they need catching here, before
			// falling through to the keyword checks. Only the first PROJECTION block found (the
			// MAP-level one, not a per-layer override) is captured - see !$inLayer below.
			if( $inProjection && $depth === $projectionDepth && $trim[0] === '"' ) {
				$projLine = trim( $trim, "\"' \t" );
				$projection = $projection === null ? $projLine : $projection.' '.$projLine;
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
					if( $inProjection && $depth === $projectionDepth ) {
						$inProjection = false;
					}
					if( $depth > 0 ) { $depth--; }
					continue;
				}
				$depth++;
				if( $keyword === 'LAYER' && !$inLayer ) {
					$inLayer = true;
					$layerDepth = $depth;
					$currentLayer = [ 'name' => null, 'type' => null, 'status' => null, 'group' => null, 'data' => null ];
				} elseif( $keyword === 'PROJECTION' && !$inLayer && $projection === null ) {
					$inProjection = true;
					$projectionDepth = $depth;
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

		return [ 'name' => $name, 'extent' => $extent, 'shapePath' => $shapePath, 'excl' => $excl, 'projection' => $projection, 'layers' => $layers ];
	}

	/** Write the already-parsed details (see parseMapFile()) into the xref tables and retag the
	 * stored file's mime type - pure DB work, no file access, safe to run after store(). */
	private function storeParsedMapFileDetails( array $pParsed, array $pParamHash ): void {
		[ 'name' => $name, 'extent' => $extent, 'shapePath' => $shapePath, 'excl' => $excl, 'projection' => $projection, 'layers' => $layers ] = $pParsed;

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

		if( $projection !== null ) {
			// "init=" is PROJ.4's own load-from-database syntax, not the projection's identity -
			// strips down to a clean "epsg:27700" for XKEY(32). A raw, fully-spelled-out PROJ.4
			// string (no "init=" shorthand - e.g. "+proj=tmerc +lat_0=49 +lon_0=-2 +k=0.9996...
			// +ellps=airy +datum=OSGB36 +units=m +no_defs") has no short form and can easily run
			// 100+ chars, well past XKEY's limit - XKEY stays blank in that case rather than
			// truncating something meaningless, and the full original string always goes in
			// XKEY_EXT(250) instead, comfortable for even a complex explicit definition.
			$shortProjection = preg_match( '/^init=(.+)$/i', $projection, $m ) ? $m[1] : '';
			$this->upsertSingleXref( 'general', 'PROJECTION', $shortProjection, true, substr( $projection, 0, 250 ) );
		}

		// The mapfile's own "# MAPPER: EXCL=..." comment (if present) wins over the upload
		// form's checkbox - it's the only signal that works sensibly for a batch-archive
		// import (one checkbox can't apply correctly across many unrelated mapfiles at once).
		if( $excl !== null ) {
			$this->upsertSingleXref( 'general', 'EXCL', $excl ? '1' : '0', true );
		} elseif( array_key_exists( 'excl', $pParamHash ) ) {
			$this->upsertSingleXref( 'general', 'EXCL', $pParamHash['excl'] ? '1' : '0', true );
		} else {
			// Only set a default the first time - don't clobber an admin's later edit on re-upload.
			// Default is non-exclusive (independent overlay checkboxes) - exclusive (radio-button,
			// pick-one-of-several-editions, matching over_gb/iom_years) is opt-in via the mapfile's
			// own comment, not assumed. Found live: batch-imported meridian_2014 defaulted to
			// exclusive, collapsing all 12 independent thematic layers into a single-choice radio
			// group in display_map2.php - only the last one added stayed visible.
			if( empty( $this->mXrefInfo?->findByItem( 'EXCL' ) ) ) {
				$this->upsertSingleXref( 'general', 'EXCL', '0', true );
			}
		}

		// Layers are wholly replaced on (re-)upload - a new mapfile's layer set may differ in
		// membership/order entirely, unlike the single-value 'general' items which upsert in place.
		$this->removeXrefItem( 'LAYER' );
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
					// SYMBOLSET/FONTSET/TEMPLATE/HEADER/FOOTER/EMPTY/IMAGE all use relative
					// paths in every mapfile in this project ("../symbols/font.list",
					// "../html/form.html", "../theme/noFeature.html", ...), resolved by
					// MapServer against wherever the file physically sits - fine for the old
					// registry (always mapper/map/, a fixed one-level offset from mapper/ itself
					// for every one of these), broken for a Map's attachment storage location
					// (varies per content_id, no stable relative offset back - found live:
					// osmcarto_gb.map's WEB TEMPLATE "../html/form.html" failed with
					// "msReturnPage(): Unable to access file" the same way SYMBOLSET/FONTSET
					// did). Rewritten to absolute paths so the stored copy is self-sufficient
					// regardless of where it physically ends up - same allowed correction as the
					// filename/mimetype fixes above.
					$this->fixRelativePaths( STORAGE_PKG_PATH.$destBranch.$finalName );
				}
			}
		}
	}

	/** Rewrite every relative "../..." path this project's mapfiles reference (symbols, HTML
	 * templates, theme fragments, reference images, per-layer DATA sources) to an absolute path
	 * anchored at MAPPER_PKG_PATH - every one of these is one level up from wherever the mapfile
	 * itself normally sits (mapper/map/), so stripping the leading ../ and prefixing
	 * MAPPER_PKG_PATH reconstructs the correct real location regardless of where the file's own
	 * copy is stored. DATA added after test_rlp.map's own IOM1880 raster layer ("../data/
	 * IOM1880bw.tif", a real ~4MB file living at mapper/data/) fatally broke MapServer rendering
	 * once the .map file itself moved to a real Map's attachment storage path - the fix points
	 * back at the package's own bundled data/ rather than duplicating the raster into every
	 * attachment.
	 * See storeParsedMapFileDetails()'s call site. */
	/** Public (not private) and safe to call repeatedly, on every resolve - not just once at
	 * upload time. Matches either a relative "../..." path OR an already-absolute
	 * "/srv/website/<domain>/mapper/..." one (any domain, including the current one) and
	 * rewrites both to the CURRENT server's MAPPER_PKG_PATH. The absolute case matters because
	 * a real Map's stored file bakes in whichever server did the uploading - fine until that
	 * same DB+storage state gets synced to a different server (desktop's bitweaver5 split vs a
	 * real server's own lsces docroot - found live via firebird-restore pulling srv10-baked
	 * paths onto desktop, breaking content_id=7390's TEMPLATE resolution: "/srv/website/lsces/
	 * mapper/html/form.html doesn't look like a MapServer template" - the file's fine, desktop
	 * just isn't lsces here). See resolve_mapset_inc.php's call site. */
	public function fixRelativePaths( string $pFilePath ): void {
		$content = file_get_contents( $pFilePath );
		if( $content === false ) {
			return;
		}
		$fixed = preg_replace_callback(
			'/^(\s*(?:SYMBOLSET|FONTSET|TEMPLATE|HEADER|FOOTER|EMPTY|IMAGE)\s+")(?:\.\.\/|\/srv\/website\/[^\/"]+\/mapper\/)([^"]+)(")/mi',
			function( $m ) {
				return $m[1].MAPPER_PKG_PATH.$m[2].$m[3];
			},
			$content
		);
		// DATA is commonly left unquoted in hand-written mapfiles when the path has no spaces
		// (confirmed live: test_rlp.map's "DATA ../data/IOM1880bw.tif", no quotes at all) -
		// separate pass, since the quoted directives above need the quote characters preserved
		// either side of the rewritten path and DATA here has none to preserve.
		$fixed = preg_replace_callback(
			'/^(\s*DATA\s+)(?:\.\.\/|\/srv\/website\/[^\/\s]+\/mapper\/)([^\s"]+)\s*$/mi',
			function( $m ) {
				return $m[1].MAPPER_PKG_PATH.$m[2];
			},
			$fixed ?? $content
		);
		// SHAPEPATH references the site's own storage/mapper/ tree (the Maps archive symlink
		// scheme), not the mapper package itself - same cross-site-copy problem as the directives
		// above (a private per-site .map file, like iom_years' lsces-authored original, bakes in
		// whichever domain it was written for) but anchored at STORAGE_PKG_PATH instead of
		// MAPPER_PKG_PATH. Confirmed live: iom_years.map's SHAPEPATH still said /srv/website/
		// lsces/storage/... after being registered on rdmcloud, even though every mapper/-relative
		// directive above had already self-healed correctly. Absolute-cross-site form only -
		// SHAPEPATH's correct value genuinely varies per mapset, unlike IMAGEPATH below, so a
		// relative value here (test_rlp.map's own SHAPEPATH is ".", meaning "next to the mapfile
		// itself") is left alone rather than guessed at.
		$fixed = preg_replace_callback(
			'/^(\s*SHAPEPATH\s+")\/srv\/website\/[^\/"]+\/storage\/([^"]+)(")/mi',
			function( $m ) {
				return $m[1].STORAGE_PKG_PATH.$m[2].$m[3];
			},
			$fixed ?? $content
		);
		// IMAGEPATH (where MapServer writes generated overview/legend/scalebar images) only ever
		// has one correct value site-wide, unlike SHAPEPATH - so rewrite it outright whenever it
		// isn't already exactly that, absolute or relative alike. Confirmed live: test_rlp.map's
		// own IMAGEPATH is a *relative* "../../storage/maps/" - one directory too shallow for its
		// actual attachment-storage depth (storage/attachments/<id%1000>/<id>/), producing a
		// doubled storage/attachments/storage/maps/ path and the same write failure the absolute
		// cross-site form already caused for iom_years. Relative paths depend on exactly how deep
		// the attachment scheme nests a given file, which varies by mime plugin, so there's no
		// single relative form that's ever reliably correct - always normalising to the absolute
		// canonical value sidesteps needing to get that right.
		$correctImagePath = STORAGE_PKG_PATH.'maps/';
		$fixed = preg_replace_callback(
			'/^(\s*IMAGEPATH\s+")([^"]+)(")/mi',
			function( $m ) use ( $correctImagePath ) {
				return $m[2] === $correctImagePath ? $m[0] : $m[1].$correctImagePath.$m[3];
			},
			$fixed ?? $content
		);
		if( $fixed !== null && $fixed !== $content ) {
			file_put_contents( $pFilePath, $fixed );
		}
	}

	/** Update-in-place for a multiple=0 xref item - looks up any existing row for
	 * (content_id, item) first so a re-upload updates rather than duplicates it.
	 * $pUseXkey: short scalar values (EXCL, OVERVIEWHEIGHT, PROJECTION - template 'value' in
	 * schema_inc.php) go straight in XKEY (VARCHAR(32), avoids a blob read entirely) instead of
	 * the DATA blob - only safe for values that actually fit; EXTENT's JSON and SHPPATH's path
	 * both exceed 32 chars, so those keep using DATA (the default, $pUseXkey=false).
	 * $pXkeyExt: optional XKEY_EXT(250) value alongside XKEY - the 'value' template's own
	 * "Notes" field, used by PROJECTION to hold the full original string (a raw multi-param
	 * PROJ.4 definition can easily exceed XKEY's 32 chars even when XKEY itself holds a clean
	 * short form, or is left blank because there's no short form at all). */
	private function upsertSingleXref( string $pGroup, string $pItem, string $pValue, bool $pUseXkey = false, ?string $pXkeyExt = null ): void {
		// EXTENT/SHPPATH/EXCL/OVERVIEWHEIGHT/PROJECTION all live in normal, sort_order>0
		// display groups (unlike Contact's type-marker items), so they're genuinely in
		// $this->mXrefInfo once loaded - no need to query Xref directly here.
		$existingXrefId = $this->mXrefInfo?->findByItem( $pItem )[0] ?? null;
		$xrefHash = [
			'content_id' => $this->mContentId,
			'item'       => $pItem,
			'xkey'       => $pUseXkey ? $pValue : $pItem,
			'xorder'     => 0,
		];
		if( $pXkeyExt !== null ) {
			$xrefHash['xkey_ext'] = $pXkeyExt;
		}
		if( !$pUseXkey ) {
			$xrefHash['edit'] = $pValue;
		}
		if( $existingXrefId ) {
			$xrefHash['xref_id'] = $existingXrefId;
		}
		$this->storeXref( $xrefHash );
	}

	/**
	 * Remove every xref row this content item currently has for one item code,
	 * through the xref class (stepXref with expunge=3) rather than a raw
	 * DELETE - works whether the item is single-cardinality (OVERVIEWHEIGHT) or
	 * multiple=1 (LAYER, several rows sharing the item code); a plain rebuild
	 * (delete-all-then-recreate) is the correct, deliberate behaviour for LAYER
	 * specifically (a re-upload's layer membership genuinely replaces the old
	 * set wholesale, not an incremental diff like Contact's checkbox items) -
	 * this only changes how the delete is done, not when or why.
	 *
	 * @param string $pItem
	 */
	private function removeXrefItem( string $pItem ): void {
		foreach( $this->mXrefInfo?->findByItem( $pItem ) ?? [] as $xrefId ) {
			$stepHash = [ 'xref_id' => $xrefId, 'expunge' => 3 ];
			$this->stepXref( $stepHash );
		}
	}
}
