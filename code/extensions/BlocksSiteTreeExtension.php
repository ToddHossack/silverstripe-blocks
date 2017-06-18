<?php
/**
 * BlocksSiteTreeExtension.
 *
 * @author Shea Dawson <shea@silverstripe.com.au>
 */
class BlocksSiteTreeExtension extends SiteTreeExtension
{
	private static $db = array(
		'InheritBlockSets' => 'Boolean',
	);
	private static $many_many = array(
		'Blocks' => 'Block',
		'DisabledBlocks' => 'Block',
	);
	public static $many_many_extraFields = array(
		'Blocks' => array(
			'Sort' => 'Int',
			'BlockArea' => 'Varchar',
		),
	);
	private static $defaults = array(
		'InheritBlockSets' => 1,
	);
	private static $dependencies = array(
		'blockManager' => '%$blockManager',
	);
	public $blockManager;

	/**
	 * Replace the content field with blocks
	 * @config
	 * @var boolean
	 */
	private static $replace_content = false;

	/**
	 * Check if the Blocks CMSFields should be displayed for this Page
	 *
	 * @return boolean
	 **/
	public function showBlocksFields()
	{
		$whiteList = $this->blockManager->getWhiteListedPageTypes();
		$blackList = $this->blockManager->getBlackListedPageTypes();

		if (in_array($this->owner->ClassName, $blackList)) {
			return false;
		}

		if (count($whiteList) && !in_array($this->owner->ClassName, $whiteList)) {
			return false;
		}

		if (!Permission::check('BLOCK_EDIT')) {
			return false;
		}

		return true;
	}

	/**
	 * Determines whether blocks should replace the content field for this page type.
	 * The content field is replaced if: 
	 * a) The configuration value $replace_content is true (inherited)
	 * b) The Content field is not in use (ie. it is empty).
	 * @return bool
	 */
	protected function blocksReplaceContent()
	{
		return (bool) (Config::inst()->get(get_class($this->owner),'replace_content')
			&& empty($this->owner->Content)
		);
	}
	
	/**
	 * Block manager for Pages.
	 * */
	public function updateCMSFields(FieldList $fields)
	{
		
		if ($fields->fieldByName('Root.Blocks') || !$this->showBlocksFields()) {
			return;
		}
		$replaceContent = $this->blocksReplaceContent();
		
		if($replaceContent) {
			$fields->removeByName('Content');
			$tabName = 'Root.Main';
		} else {
			$tabName = 'Root.Blocks';
		}
		
		$areas = $this->blockManager->getAreasForPageType($this->owner->ClassName);

		if ($areas && count($areas)) {
			if(!$replaceContent) {
				$fields->addFieldToTab($tabName, new Tab('Blocks', _t('Block.PLURALNAME', 'Blocks')));
			}
			
			if (BlockManager::config()->get('block_area_preview')) {
				$fields->addFieldToTab($tabName,
						LiteralField::create('PreviewLink', $this->areasPreviewButton()));
			}

			// Blocks related directly to this Page
			$gridConfig = GridFieldConfig_BlockManager::create(true, true, true, true)
				->addExisting($this->owner->class)
				//->addBulkEditing()
				->addComponent(new GridFieldOrderableRows())
				;

			// TODO it seems this sort is not being applied...
			$gridSource = $this->owner->Blocks();
				// ->sort(array(
				// 	"FIELD(SiteTree_Blocks.BlockArea, '" . implode("','", array_keys($areas)) . "')" => '',
				// 	'SiteTree_Blocks.Sort' => 'ASC',
				// 	'Name' => 'ASC'
				// ));

			$fields->addFieldToTab($tabName, GridField::create('Blocks', _t('Block.PLURALNAME', 'Blocks'), $gridSource, $gridConfig));

			// Blocks inherited from BlockSets
			if ($this->blockManager->getUseBlockSets()) {
				$inheritedBlocks = $this->getBlocksFromAppliedBlockSets(null, true);

				if ($inheritedBlocks->count()) {
					$activeInherited = $this->getBlocksFromAppliedBlockSets(null, false);

					if ($activeInherited->count()) {
						$fields->addFieldsToTab($tabName, array(
							GridField::create('InheritedBlockList', _t('BlocksSiteTreeExtension.BlocksInheritedFromBlockSets', 'Blocks Inherited from Block Sets'), $activeInherited,
								GridFieldConfig_BlockManager::create(false, false, false)),
							LiteralField::create('InheritedBlockListTip', "<p class='message'>"._t('BlocksSiteTreeExtension.InheritedBlocksEditLink', 'Tip: Inherited blocks can be edited in the {link_start}Block Admin area{link_end}', '', array('link_start' => '<a href="admin/block-admin">', 'link_end' => '</a>')).'<p>'),
						));
					}

					$fields->addFieldToTab($tabName,
							ListBoxField::create('DisabledBlocks', _t('BlocksSiteTreeExtension.DisableInheritedBlocks', 'Disable Inherited Blocks'),
									$inheritedBlocks->map('ID', 'Title'), null, null, true)
									->setDescription(_t('BlocksSiteTreeExtension.DisableInheritedBlocksDescription', 'Select any inherited blocks that you would not like displayed on this page.'))
					);
				} else {
					$fields->addFieldToTab($tabName,
							ReadonlyField::create('DisabledBlocksReadOnly', _t('BlocksSiteTreeExtension.DisableInheritedBlocks', 'Disable Inherited Blocks'),
									_t('BlocksSiteTreeExtension.NoInheritedBlocksToDisable','This page has no inherited blocks to disable.')));
				}

				$fields->addFieldToTab($tabName,
					CheckboxField::create('InheritBlockSets', _t('BlocksSiteTreeExtension.InheritBlocksFromBlockSets', 'Inherit Blocks from Block Sets')));
			}
		}
	}

	/**
	 * Called from templates to get rendered blocks for the given area.
	 *
	 * @param string $area
	 * @param int    $limit Limit the items to this number, or null for no limit
	 */
	public function BlockArea($area, $limit = null)
	{
		if ($this->owner->ID <= 0) {
			return;
		} // blocks break on fake pages ie Security/login

		$list = $this->getBlockList($area);

		foreach ($list as $block) {
			if (!$block->canView()) {
				$list->remove($block);
			}
		}

		if ($limit !== null) {
			$list = $list->limit($limit);
		}

		$data = array();
		$data['HasBlockArea'] = ($this->owner->canEdit() && isset($_REQUEST['block_preview']) && $_REQUEST['block_preview']) || $list->Count() > 0;
		$data['BlockArea'] = $list;
		$data['AreaID'] = $area;

		$data = $this->owner->customise($data);

		$template = array('BlockArea_'.$area);

		if (SSViewer::hasTemplate($template)) {
			return $data->renderWith($template);
		} else {
			return $data->renderWith('BlockArea');
		}
	}

	public function HasBlockArea($area)
	{
		if ($this->owner->canEdit() && isset($_REQUEST['block_preview']) && $_REQUEST['block_preview']) {
			return true;
		}

		$list = $this->getBlockList($area);

		foreach ($list as $block) {
			if (!$block->canView()) {
				$list->remove($block);
			}
		}

		return $list->Count() > 0;
	}

	/**
	 * Get a merged list of all blocks on this page and ones inherited from BlockSets.
	 *
	 * @param string|null $area            filter by block area
	 * @param bool        $includeDisabled Include blocks that have been explicitly excluded from this page
	 *                                     i.e. blocks from block sets added to the "disable inherited blocks" list
	 *
	 * @return ArrayList
	 * */
	public function getBlockList($area = null, $includeDisabled = false)
	{
		$includeSets = $this->blockManager->getUseBlockSets() && $this->owner->InheritBlockSets;
		$blocks = ArrayList::create();

		// get blocks directly linked to this page
		$nativeBlocks = $this->owner->Blocks()->sort('Sort');
		if ($area) {
			$nativeBlocks = $nativeBlocks->filter('BlockArea', $area);
		}

		if ($nativeBlocks->count()) {
			foreach ($nativeBlocks as $block) {
				$blocks->add($block);
			}
		}

		// get blocks from BlockSets
		if ($includeSets) {
			$blocksFromSets = $this->getBlocksFromAppliedBlockSets($area, $includeDisabled);
			if ($blocksFromSets->count()) {
				// merge set sources
				foreach ($blocksFromSets as $block) {
					if (!$blocks->find('ID', $block->ID)) {
						if ($block->AboveOrBelow == 'Above') {
							$blocks->unshift($block);
						} else {
							$blocks->push($block);
						}
					}
				}
			}
		}

		return $blocks;
	}

	/**
	 * Get Any BlockSets that apply to this page.
	 *
	 * @return ArrayList
	 * */
	public function getAppliedSets()
	{
		$list = ArrayList::create();
		if (!$this->owner->InheritBlockSets) {
			return $list;
		}

		$sets = BlockSet::get()->where("(PageTypesValue IS NULL) OR (PageTypesValue LIKE '%:\"{$this->owner->ClassName}%')");
		$ancestors = $this->owner->getAncestors()->column('ID');

		foreach ($sets as $set) {
			$restrictedToParerentIDs = $set->PageParents()->column('ID');
			if (count($restrictedToParerentIDs)) {
				// check whether the set should include selected parent, in which case check whether
				// it was in the restricted parents list. If it's not, or if include parentpage
				// wasn't selected, we check the ancestors of this page.
				if ($set->IncludePageParent && in_array($this->owner->ID, $restrictedToParerentIDs)) {
					$list->add($set);
				} else {
					if (count($ancestors)) {
						foreach ($ancestors as $ancestor) {
							if (in_array($ancestor, $restrictedToParerentIDs)) {
								$list->add($set);
								continue;
							}
						}
					}
				}
			} else {
				$list->add($set);
			}
		}

		return $list;
	}

	/**
	 * Get all Blocks from BlockSets that apply to this page.
	 *
	 * @return ArrayList
	 * */
	public function getBlocksFromAppliedBlockSets($area = null, $includeDisabled = false)
	{
		$blocks = ArrayList::create();
		$sets = $this->getAppliedSets();

		if (!$sets->count()) {
			return $blocks;
		}

		foreach ($sets as $set) {
			$setBlocks = $set->Blocks()->sort('Sort DESC');

			if (!$includeDisabled) {
				$setBlocks = $setBlocks->exclude('ID', $this->owner->DisabledBlocks()->column('ID'));
			}

			if ($area) {
				$setBlocks = $setBlocks->filter('BlockArea', $area);
			}

			$blocks->merge($setBlocks);
		}

		$blocks->removeDuplicates();

		return $blocks;
	}

	/**
	 * Gets the link for a block area preview button.
	 * Adds compatibility for subsites
	 * @return string
	 * */
	public function areasPreviewLink()
	{
		$url = Director::absoluteURL($this->owner->Link());
		// Add SubsiteID param if using Subsites
        if ($this->owner->getField('SubsiteID')) {
            $url = HTTP::setGetVar('SubsiteID', $this->owner->SubsiteID, $url);
        }
		// Add block_preview
        $url = HTTP::setGetVar('block_preview', 1, $url);
		return $url;
	}

	/**
	 * Gets html for a block area preview button.
	 *
	 * @return string
	 * */
	public function areasPreviewButton()
	{
		return "<a class='ss-ui-button ss-ui-button-small' style='font-style:normal;' href='".$this->areasPreviewLink()."' target='_blank'>"._t('BlocksSiteTreeExtension.PreviewBlockAreasLink', 'Preview Block Areas for this page').'</a>';
	}
	
	/**
	 * @see SiteTree::AbsoluteLink()
	 * @see SiteTreeSubsites::alternateAbsoluteLink()
	 * @return string
	 */
	public function alternateAbsoluteLink()
    {
        $url = Director::absoluteURL($this->owner->Link());
		$request = Controller::curr()->getRequest();
		// Preview mode
		if ($request && $request->getVar('CMSPreview')) {
			// Carry over the params so navigation will work
			$url = HTTP::setGetVar('CMSPreview', 1, $url);
			// Simulate subsite without changing domain
			if ($this->owner->SubsiteID) {
				$url = HTTP::setGetVar('SubsiteID', $this->owner->SubsiteID, $url);
				// Hack to fix bug - multiple uses of setGetVar method escape the ampersands again
				$url = str_replace('&amp;','&',$url); 
			}
        } 
		// Replace the domain with the subsite domain
		elseif($this->owner->SubsiteID) {
			$url = preg_replace('/\/\/[^\/]+\//', '//' .  $this->owner->Subsite()->domain() . '/', $url);
		}

        return $url;
    }
}
