{* $Header$ *}
{strip}
{if $modMap}
	{bitmodule title="$moduleTitle" name="tools" class="mapper-module"}
		<!-- THIS DISPLAYS THE MAP TOOL BAR -->
		{* height is just a starting fallback - html/tool.html resizes this to fit
		   the toolbar + coordinate display once built *}
		<iframe class="ToolFrame" id="ToolFrame" name="ToolFrame" src="{$smarty.const.MAPPER_PKG_URL}html/tool_blank.html" scrolling="auto" style="width:100%; height:100px;"frameborder="0">
		</iframe>
	{/bitmodule}
{/if}
{/strip}