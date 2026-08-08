{strip}
<div class="edit mapper">
	<header>
		<div class="floaticon"></div>
		<h1>{tr}Edit Map{/tr}: {$gContent->getTitle()|escape}</h1>
		<small><a href="{$smarty.const.MAPPER_PKG_URL}view.php?content_id={$gContent->mContentId}">{$gContent->getTitle()|escape}</a></small>
	</header>

	<section class="body">
		{form id="editMapForm" ipackage="mapper" ifile="edit.php"}
			{formfeedback error=$errors}

			<input type="hidden" name="content_id" value="{$gContent->mContentId|escape}"/>

			<div class="form-group">
				{formlabel label="Title" for="map-title" mandatory="y"}
				{forminput}
					<input type="text" class="form-control" name="title" id="map-title" value="{$gContent->getTitle()|escape}" maxlength="160" size="50"/>
				{/forminput}
			</div>

			{textarea edit=$gContent->mInfo.data rows=5 label="Description"}

			<div class="form-group">
				<div class="col-sm-offset-2 col-sm-6">
					<div class="checkbox">
						<label>
							<input type="checkbox" name="excl" value="1" {if $mapExcl}checked{/if}>
							{tr}Exclusive layer selection (radio buttons, not checkboxes){/tr}
						</label>
						{formhelp note="Set automatically from the mapfile's own `# MAPPER: EXCL=...` comment when present - this only applies when that comment is absent."}
					</div>
				</div>
			</div>

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
