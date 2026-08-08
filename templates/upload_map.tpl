{* $Header$ *}
<div class="floaticon">{bithelp}</div>

<div class="display map">
	<div class="header">
		<h1>{tr}Upload Map{/tr}</h1>
	</div>
	<div class="body">
		{if $errors}
			<div class="alert alert-danger">
				<ul>
					{foreach $errors as $error}
						<li>{$error|escape}</li>
					{/foreach}
				</ul>
			</div>
		{/if}

		<form action="{$smarty.const.MAPPER_PKG_URL}upload_map.php" method="post" enctype="multipart/form-data" class="form-horizontal">
			<div class="form-group">
				<label class="control-label col-sm-2" for="map_file">{tr}Map file{/tr}</label>
				<div class="col-sm-6">
					<input type="file" class="form-control" name="map_file" id="map_file" accept=".map" required>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-2" for="title">{tr}Title{/tr}</label>
				<div class="col-sm-6">
					<input type="text" class="form-control" name="title" id="title" placeholder="{tr}Leave blank to use the mapfile's own NAME{/tr}">
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-2" for="data">{tr}Description{/tr}</label>
				<div class="col-sm-6">
					<textarea class="form-control" name="data" id="data" rows="3"></textarea>
				</div>
			</div>

			{* Exclusive-layer-selection (EXCL) isn't set here any more - the mapfile's own
			"# MAPPER: EXCL=..." comment still wins at upload time (works for both single-file
			and batch-archive imports, unlike a form checkbox which can't apply correctly across
			many unrelated mapfiles at once), and it's editable afterwards through the standard
			xref group tabs on the edit page regardless. *}

			<div class="form-group">
				<div class="col-sm-offset-2 col-sm-6">
					<button type="submit" class="btn btn-primary">{tr}Upload{/tr}</button>
				</div>
			</div>
		</form>
	</div>
</div>
