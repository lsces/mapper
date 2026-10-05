{* $Header$ *}
<div class="floaticon">{bithelp}</div>

<div class="display map">
	<div class="header">
		<h1>{tr}Load Maps{/tr}</h1>
	</div>
	<div class="body">
		{if $result.created}
			<div class="alert alert-success">
				{tr}Imported:{/tr}
				{foreach $result.created as $made}
					<a href="{$smarty.const.MAPPER_PKG_URL}view.php?content_id={$made.content_id}">{$made.title|escape}</a>{if !$made@last}, {/if}
				{/foreach}
			</div>
		{/if}
		{if $result.errors}
			<div class="alert alert-danger">
				<ul>
					{foreach $result.errors as $path => $error}
						<li>{$path|escape}: {$error|escape}</li>
					{/foreach}
				</ul>
			</div>
		{/if}
		{if $result.skipped}
			<div class="alert alert-warning">{tr}{$result.skipped} more were ticked than the batch limit and were left for the next go.{/tr}</div>
		{/if}

		{if !$baseDir}
			<div class="alert alert-warning">{tr}No maps folder is configured for this site, so nothing is listed. Set one in the mapper admin settings (Maps folder).{/tr}</div>
		{/if}
		<p>
			{tr}Maps in{/tr} <code>{$baseDir|escape}</code> {tr}that have not been imported yet.{/tr}
			{if $outstanding > $batchSize}{tr}Showing the first{/tr} {$batchSize} {tr}of{/tr} {$outstanding} {tr}outstanding - import these and the next batch appears.{/tr}{/if}
			<a href="{$smarty.const.MAPPER_PKG_URL}upload_map.php">{tr}Upload a single map file instead{/tr}</a>
		</p>

		{if $candidates}
			<form action="{$smarty.const.MAPPER_PKG_URL}load_map.php" method="post">
				<table class="table table-condensed">
					<thead>
						<tr><th></th><th>{tr}Map{/tr}</th><th>{tr}File{/tr}</th><th>{tr}Description{/tr}</th></tr>
					</thead>
					<tbody>
						{foreach $candidates as $c}
							<tr>
								<td><input type="checkbox" name="import[]" value="{$c.path|escape}" id="imp{$c@iteration}"></td>
								<td><label for="imp{$c@iteration}">{$c.title|escape}</label></td>
								<td><code>{$c.path|escape}</code></td>
								<td>{if $c.description}{$c.description}{else}<span class="text-muted">{tr}No DESCRIPTION comment in the file{/tr}</span>{/if}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
				<button type="submit" class="btn btn-primary">{tr}Import ticked maps{/tr}</button>
			</form>
		{else}
			<p>{tr}Nothing outstanding - every map in that folder has been imported.{/tr}</p>
		{/if}
	</div>
</div>
