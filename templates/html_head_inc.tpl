{* $Header: /cvsroot/bitweaver/_bit_mapper/templates/header_inc.tpl,v 1.2 2007/07/28 09:16:04 lsces Exp $ *}
{if $gBitSystem->isPackageActive( 'mapper' )}
	{* map_blank.html's Load1() needs scriptURL; script.php reads
	   parent.styleURL right at its own start, before its own param1.js
	   has had a chance to load. Everything else (mapPath, layerList,
	   etc) is ScriptFrame's own separate copy, resolved by
	   html/script.php itself - see mapper/includes/mapsets_inc.php *}
	<script type="text/javascript">
	var scriptURL = "{$smarty.const.MAPPER_PKG_URL}html/script.php";
	var styleURL = "{$smarty.const.MAPPER_PKG_URL}styles/client.css";
	</script>
{/if}
