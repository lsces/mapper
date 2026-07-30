{* $Header$ *}
{strip}
{if $modMap}
	{bitmodule title="$moduleTitle" name="legend" class="mapper-module"}
    	<!-- THIS DISPLAYS THE MAP LEGEND -->
		{* deliberately fixed height, not resize-to-content like the other mapper
		   panels - legend content changes size every time a layer is toggled, so
		   a constantly-resizing box would be more distracting than a scrollbar *}
		<iframe class="LegendFrame" id="LegendFrame" name="LegendFrame" src="{$smarty.const.MAPPER_PKG_URL}html/legend_blank.html" scrolling="auto" style="width:100%; height:90px" frameborder="0">
		</iframe>
	{/bitmodule}
{/if}
{/strip}
	