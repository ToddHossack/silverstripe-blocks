<?php

class BlockSubsiteExtension extends DataExtension {
	
	private static $has_one = array(
		'Subsite' => 'Subsite'		
	);
	
	public function updateCMSFields(FieldList $fields) {
		$fields->push(new HiddenField('SubsiteID','SubsiteID',Subsite::currentSubsiteID()));
	}
	
	/**
     * Update any requests to limit the results to the current site
     */
    public function augmentSQL(SQLQuery &$query)
    {
        if (Subsite::$disable_subsite_filter) {
            return;
        }

		// Filter by subsite
		$ids = array((int) Subsite::currentSubsiteID());
		// If configured to treat subsite 0 as global, include ID 0.
		if(Config::inst()->get('LeftAndMain','treats_subsite_0_as_global')) {
			$ids[] = 0;
		}
		$ids = implode(',',$ids);
		
        // The foreach is an ugly way of getting the first key :-)
        foreach ($query->getFrom() as $tableName => $info) {
            $where = "\"$tableName\".\"SubsiteID\" IN ($ids)";
            $query->addWhere($where);
            break;
        }

    }
	
}