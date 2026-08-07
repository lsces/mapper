{* $Header: /cvsroot/bitweaver/_bit_mapper/templates/header_inc.tpl,v 1.2 2007/07/28 09:16:04 lsces Exp $ *}
{if $gBitSystem->isPackageActive( 'mapper' )}
	{* map_blank.html's Load1() needs scriptURL; script.php reads
	   parent.styleURL right at its own start, before its own param1.js
	   has had a chance to load. Everything else (mapPath, layerList,
	   etc) is ScriptFrame's own separate copy, resolved by
	   html/script.php itself - see mapper/includes/mapsets_inc.php *}
	<script type="text/javascript">
	var scriptURL = "{$smarty.const.MAPPER_PKG_URL}html/script.php{if $contentId}?content_id={$contentId}{elseif $mapset}?mapset={$mapset}{/if}";
	var styleURL = "{$smarty.const.MAPPER_PKG_URL}styles/client.css";
	</script>
	{* the frameset iframes are sized to their content (see html/navi.html,
	   html/tool.html, theme/legend.html, html/form.html) - Bootstrap 3's
	   default 15px top/bottom .panel-body padding just wastes space each
	   of these boxes doesn't need; left/right stays default *}
	<style type="text/css">
	.mapper-module > .panel-body {
		padding-top: 0;
		padding-bottom: 0;
	}
	</style>
{/if}
