{* $Header$ *}
{strip}
{if $modMap}
	{bitmodule title="$moduleTitle" name="navi" class="mapper-module"}
    	<!-- THIS DISPLAYS THE MAP NAVIGATION SELECTIONS -->
		{* height is just a starting fallback - html/navi.html resizes this
		   to fit the actual layer list once built, since the layer count
		   varies per mapset (see mapper/CLAUDE.md) *}
		<iframe class="NaviFrame" id="NaviFrame" name="NaviFrame" src="{$smarty.const.MAPPER_PKG_URL}html/navi_blank.html" scrolling="auto" style="width:100%; height:180px" frameborder="0">
		</iframe>
	{/bitmodule}
{/if}
{/strip}
