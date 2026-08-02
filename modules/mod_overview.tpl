{* $Header$ *}
{strip}
{if $modMap}
	{bitmodule title="$moduleTitle" name="overview" class="mapper-module"}
	    <!-- THIS DISPLAYS THE MAP THUMBNAIL OVERVIEW -->
		{* height is just a starting fallback - html/form.html resizes this
		   to fit the actual reference image once it loads, since that
		   image's aspect ratio follows the active mapset's EXTENT (see
		   mapper/CLAUDE.md) and isn't fixed across mapsets. 384px is the
		   measured real rendered height once a 320x320 reference image
		   (gb_overview.png and refmap_IOM1880.png are both 320x320 now)
		   has loaded, including surrounding form chrome - not just the
		   raw image height. *}
		<iframe class="FormFrame" id="FormFrame" name="FormFrame" src="{$smarty.const.MAPPER_PKG_URL}html/form_blank.html" scrolling="auto" style="width:100%; height:384px" frameborder="0">
		</iframe>
	{/bitmodule}
{/if}
{/strip}