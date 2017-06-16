<?php


class BlockAdminSubsitesExtension extends Extension
{
	
	/**
	 * Sets the theme to that used by the current subsite. Needed for filtering block areas.
	 */
	public function onBeforeInit()
    {
		$subsite = Subsite::currentSubsite();
        if ($subsite && $subsite->Theme) {
            Config::inst()->update('SSViewer', 'theme', Subsite::currentSubsite()->Theme);
        }
	}

}