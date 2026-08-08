{* $Header$ *}
<div class="floaticon">
	{bithelp}
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		<a title="{tr}Edit{/tr}" href="{$smarty.const.MAPPER_PKG_URL}edit.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
		<a title="{tr}Delete{/tr}" href="{$smarty.const.MAPPER_PKG_URL}edit.php?content_id={$gContent->mContentId}&amp;delete=1">{biticon ipackage="icons" iname="user-trash" iexplain="Delete"}</a>
	{/if}
</div>

<div class="display map">
	<div class="header">
		<h1>{$gContent->getTitle()|escape}</h1>
	</div>
	<div class="body">
		{if $gContent->mInfo.data}
			<p>{$gContent->mInfo.data|escape}</p>
		{/if}

		<div class="form-group">
			<a class="btn btn-default" href="{$smarty.const.MAPPER_PKG_URL}display_map.php?content_id={$gContent->mContentId}">{tr}Open (classic viewer){/tr}</a>
			<a class="btn btn-default" href="{$smarty.const.MAPPER_PKG_URL}display_map2.php?content_id={$gContent->mContentId}">{tr}Open (map2, Leaflet){/tr}</a>
		</div>

		{if $gXrefInfo->mGroups}
			{jstabs}
				{foreach $gXrefInfo->mGroups as $xrefGroup}
					{include file=$gContent->getXrefListTemplate($xrefGroup->mTemplate)
						xrefGroup=$xrefGroup
						allow_edit=false}
				{/foreach}
			{/jstabs}
		{/if}
	</div>
</div>
