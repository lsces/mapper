{strip}
{* No $packageMenuTitle condition: the Administration page includes this with only
packageMenuClass set (the title is set just for the top-bar dropdown), so a title test hid the
entry there. *}
<ul class="{$packageMenuClass}">
	<li><a class="nosubmenu" href="{$smarty.const.KERNEL_PKG_URL}admin/index.php?page=mapper">{tr}Mapper Settings{/tr}</a></li>
</ul>
{/strip}
