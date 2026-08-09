{strip}
<div class="listing mapper">
	<div class="header">
		<div class="floaticon">
			{if $gBitUser->hasPermission( 'bit_p_create_mapper' )}
				<a title="{tr}Upload Map{/tr}" href="{$smarty.const.MAPPER_PKG_URL}upload_map.php">{biticon ipackage="icons" iname="go-up" iexplain="Upload Map"}</a>
			{/if}
		</div>
		<h1>{tr}Map Archive Listing{/tr}</h1>
	</div>

	<div class="body">
		{include file="bitpackage:mapper/list_map_inc.tpl"}
	</div><!-- end .body -->
</div><!-- end .mapper -->
{/strip}
