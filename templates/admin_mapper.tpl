{strip}

<div class="mapper">
	{if $refreshResult}
		{if $refreshResult.ok}
			<div class="alert alert-success">{tr}Refreshed from the maps folder:{/tr} {$refreshResult.title|escape}</div>
		{else}
			<div class="alert alert-danger">{tr}Could not refresh{/tr} {$refreshResult.title|escape}: {foreach $refreshResult.errors as $error}{$error|escape} {/foreach}</div>
		{/if}
	{/if}

	{if $refreshAllResult}
		<div class="alert alert-{if $refreshAllResult.failed}warning{else}success{/if}">
			{tr}Refreshed from the maps folder:{/tr} {$refreshAllResult.done|@count}{if $refreshAllResult.failed}; {tr}could not refresh:{/tr} {foreach $refreshAllResult.failed as $title}{$title|escape} {/foreach}{/if}
		</div>
	{/if}

	{jstabs}
		{jstab title="Mapper Settings"}
			{form legend="Mapper Settings"}
				<input type="hidden" name="page" value="{$page}" />

			{if $gBitSystem->isPackageActive('mapper')}
				<div class="form-group">
					{formlabel label="Font" for="font"}
					{forminput}
						<input type="text" name="font" id="font" size="50" value="{$mapperSettings.font|escape}" />
					{/forminput}
				</div>

				<div class="form-group">
					{formlabel label="Maps folder" for="maps_dir"}
					{forminput}
						<input type="text" name="maps_dir" id="maps_dir" size="50" value="{$mapperSettings.maps_dir|escape}" />
						{formhelp note="Folder holding one subfolder per map, each with its .map file. 'Load maps from folder' lists the ones not yet imported. Leave blank to switch that page off - set it per site, to only the maps that site should see."}
					{/forminput}
				</div>

				<div class="form-group">
					{formlabel label="Automatic XY Tracker" for="autotrack"}
					{forminput}
						<input type="checkbox" {if $mapperSettings.autotrack eq 'on'}checked="checked"{/if} name="autotrack" id="autotrack" />
					{/forminput}
				</div>
			{/if}

				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="save" value="{tr}Apply Settings{/tr}" />
				</div>
			{/form}
		{/jstab}

		{jstab title="Mapper Archive"}
			<h2>{tr}Available Maps{/tr}</h2>

			{if !$mapperSettings.maps_dir}
				<div class="alert alert-warning">{tr}No maps folder is configured for this site, so only maps already loaded are listed. Set one under Mapper Settings (Maps folder).{/tr}</div>
			{else}
				<p>{tr}Maps in{/tr} <code>{$mapperSettings.maps_dir|escape}</code> {tr}and the maps already loaded here.{/tr}
					<a class="btn btn-default btn-sm" href="{$smarty.const.MAPPER_PKG_URL}load_map.php">{tr}Load maps from folder{/tr}</a>
					<form method="post" action="{$smarty.server.SCRIPT_NAME}" style="display:inline">
						<input type="hidden" name="page" value="{$page}" />
						<button type="submit" class="btn btn-default btn-sm" name="refresh_all" value="1" onclick="return confirm('{tr}Replace the stored mapfile of every loaded map from its folder? Layers are re-read, so per-layer queryable flags are reset.{/tr}');">{tr}Refresh all from folder{/tr}</button>
					</form></p>
			{/if}

			{if $archiveRows}
				<form method="post" action="{$smarty.server.SCRIPT_NAME}">
					<input type="hidden" name="page" value="{$page}" />
					<table class="table table-condensed">
						<thead>
							<tr>
								<th>{tr}Map{/tr}</th>
								<th>{tr}Folder file{/tr}</th>
								<th>{tr}Status{/tr}</th>
								<th>{tr}Description{/tr}</th>
								<th>{tr}Folder comment{/tr}</th>
								<th>{tr}Reference image{/tr}</th>
								<th>{tr}Folder rule{/tr}</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{foreach $archiveRows as $row}
								<tr>
									<td>{if $row.content_id}<a href="{$smarty.const.MAPPER_PKG_URL}view.php?content_id={$row.content_id}">{$row.title|escape}</a>{else}{$row.title|escape}{/if}</td>
									<td>{if $row.folder_file}<code>{$row.folder_file|escape}</code>{else}<span class="text-muted">-</span>{/if}</td>
									<td>
										{if $row.status == 'loaded'}{tr}Loaded{/tr}
										{elseif $row.status == 'not_loaded'}<a href="{$smarty.const.MAPPER_PKG_URL}load_map.php">{tr}Not loaded{/tr}</a>
										{else}<span class="text-warning">{tr}Not in folder{/tr}</span>{/if}
									</td>
									<td>{if $row.status == 'not_loaded'}<span class="text-muted">-</span>{elseif $row.db_description}{tr}Yes{/tr}{else}<span class="text-warning">{tr}No{/tr}</span>{/if}</td>
									<td>{if $row.file_description === null}<span class="text-muted">-</span>{elseif $row.file_description}{tr}Yes{/tr}{else}{tr}No{/tr}{/if}</td>
									<td>
										{if $row.reference_state == 'ok'}{tr}OK{/tr}
										{elseif $row.reference_state == 'missing'}<span class="text-danger" title="{$row.reference_path|escape}">{tr}Missing{/tr}</span>
										{elseif $row.reference_state == 'unknown'}<span class="text-muted" title="{$row.reference_path|escape}">{tr}Relative path{/tr}</span>
										{else}<span class="text-muted">{tr}None{/tr}</span>{/if}
									</td>
									<td>
										{if $row.folder_issues === null}<span class="text-muted">-</span>
										{elseif $row.folder_issues}<span class="text-warning" title="{foreach $row.folder_issues as $issue}{$issue|escape}; {/foreach}">{$row.folder_issues|@count} {tr}to fix{/tr}</span>
										{elseif $row.folder_note}<span class="text-muted" title="{tr}An accepted exception to the folder rule{/tr}">{tr}OK{/tr} ({$row.folder_note|escape})</span>
										{else}{tr}OK{/tr}{/if}
									</td>
									<td>{if $row.content_id && $row.folder_file}<button type="submit" class="btn btn-default btn-xs" name="refresh_map" value="{$row.content_id}" title="{tr}Replace the stored mapfile from its folder{/tr}">{tr}Refresh{/tr}</button>{/if}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</form>
				<p class="help-block">{tr}Description is whether the loaded record has one. Folder comment is whether the .map file carries a DESCRIPTION comment that a fresh load would use. Reference image is checked on the copy the viewer actually uses, so Missing means that map will fail to draw here. Folder rule is whether the folder's mapfile is self-contained: a reference image at tiles/reference.png, and SHAPEPATH and CONNECTION relative to the folder (hover for what needs fixing). Refresh replaces a loaded map's stored mapfile from its folder, keeping the record and description; the layers are re-read, so their queryable flags are reset.{/tr}</p>
			{else}
				<p>{tr}No maps found.{/tr}</p>
			{/if}
		{/jstab}
	{/jstabs}
</div><!-- end mapper -->

{/strip}
