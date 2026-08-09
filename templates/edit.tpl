{strip}
<div class="edit mapper">
	<header>
		<div class="floaticon"></div>
		<h1>{tr}Edit Map{/tr}: {$gContent->getTitle()|escape}</h1>
		<small><a href="{$smarty.const.MAPPER_PKG_URL}view.php?content_id={$gContent->mContentId}">{$gContent->getTitle()|escape}</a></small>
	</header>

	<section class="body">
		{form id="editMapForm" ipackage="mapper" ifile="edit.php" enctype="multipart/form-data"}
			{formfeedback error=$errors}

			<input type="hidden" name="content_id" value="{$gContent->mContentId|escape}"/>

			<div class="form-group">
				{formlabel label="Title" for="map-title" mandatory="y"}
				{forminput}
					<input type="text" class="form-control" name="title" id="map-title" value="{$gContent->getTitle()|escape}" maxlength="160" size="50"/>
				{/forminput}
			</div>

			<div class="form-group">
				{formfeedback warning="{tr}Uploading a new .map file here replaces the current one and re-parses its EXTENT/SHAPEPATH/layers - any manually set per-layer queryable/visible flags will need re-setting afterward.{/tr}"}
				{formlabel label="Replace Map File" for="map-file"}
				{forminput}
					<input type="file" class="form-control" name="map_file" id="map-file"/>
					{formhelp note="Leave blank to keep the current file unchanged."}
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Current Map File" for="map-file-view"}
				{forminput}
					<textarea class="form-control" id="map-file-view" rows="15" readonly style="font-family:monospace; font-size:0.85em;">{$mapFileContent|escape}</textarea>
				{/forminput}
			</div>

			{textarea edit=$gContent->mInfo.data rows=5 label="Description"}

			{* Exclusive-layer-selection (EXCL) is a registered xref item (template 'value') now
			- editable through the generic xref group tabs below, no dedicated form field here.
			Still settable from the mapfile's own "# MAPPER: EXCL=..." comment at upload time,
			same as before - only the manual edit-page checkbox moved. *}

			<div class="form-group">
				{formlabel label="Overview box height (px)" for="map-overview-height"}
				{forminput}
					<input type="number" class="form-control" name="overview_height" id="map-overview-height" value="{$mapOverviewHeight|escape}" min="1" style="width:8em">
					{formhelp note="Leave blank for the default 150px square box. Taller GB-scale/portrait extents usually need more - see mapper/CLAUDE.md."}
				{/forminput}
			</div>

			{include file="bitpackage:liberty/edit_services_inc.tpl" serviceFile="content_edit_mini_tpl"}

			{if $gXrefInfo->mGroups}
				{jstabs}
					{foreach $gXrefInfo->mGroups as $xrefGroup}
						{include file=$gContent->getXrefListTemplate($xrefGroup->mTemplate)
							xrefGroup=$xrefGroup
							allow_add=true
							allow_edit=true}
					{/foreach}
				{/jstabs}
			{/if}

			{include file="bitpackage:liberty/edit_content_owner_inc.tpl"}

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="cancel" value="{tr}Cancel{/tr}"/>
				<input type="submit" class="btn btn-primary" name="save" value="{tr}Save{/tr}"/>
			</div>
		{/form}
	</section>
</div>
{/strip}
