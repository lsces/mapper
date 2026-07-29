{* $Header: /cvsroot/bitweaver/_bit_mapper/templates/header_inc.tpl,v 1.2 2007/07/28 09:16:04 lsces Exp $ *}
{if $gBitSystem->isPackageActive( 'mapper' )}
	{* map_blank.html's Load1() only ever needs scriptURL from this page -
	   everything else (mapPfad, layerList, etc) is ScriptFrame's own
	   separate copy, loaded independently by html/script.php *}
	<script type="text/javascript">var scriptURL = "{$smarty.const.MAPPER_PKG_URL}html/script.php";</script>
{/if}
