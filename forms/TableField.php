<?php
/**
 * TableField behaves in the same manner as TableListField, however allows the addition of 
 * fields and editing of attributes specified, and filtering results.
 * 
 * Caution: If you insert DropdownFields in the fieldTypes-array, make sure they have an empty first option.
 * Otherwise the saving can't determine if a new row should really be saved.
 * 
 * Caution: TableField relies on {@FormResponse} to reload the field after it is saved.
 * A TableField-instance should never be saved twice without reloading, because otherwise it 
 * can't determine if a field is new (=create) or existing (=update), and will produce duplicates.
 * 
 * @param $name string The fieldname
 * @param $sourceClass string The source class of this field
 * @param $fieldList array An array of field headings of Fieldname => Heading Text (eg. heading1)
 * @param $fieldTypes array An array of field types of fieldname => fieldType (eg. formfield). Do not use for extra data/hiddenfields.
 * @param $filterField string The field to filter by.  Give the filter value in $sourceFilter.  The value will automatically be set on new records.
 * @param $sourceFilter string If $filterField has a value, then this is the value to filter by.  Otherwise, it is a SQL filter expression.
 * @param $editExisting boolean (Note: Has to stay on this position for legacy reasons)
 * @param $sourceSort string
 * @param $sourceJoin string
 * 
 * @todo We should refactor this to support a single FieldSet instead of evaluated Strings for building FormFields
 * 
 * @package forms
 * @subpackage fields-relational
 */
 
class TableField extends TableListField {
	
	protected $sourceClass;
	
	protected $sourceFilter;
	
	protected $fieldList;
	
	/**
	 * A "Field = Value" filter can be specified by setting $this->filterField and $this->filterValue.  This has the advantage of auto-populating
	 * new records
	 */
	protected $filterField = null;

	/**
	 * A "Field = Value" filter can be specified by setting $this->filterField and $this->filterValue.  This has the advantage of auto-populating
	 * new records
	 */
	protected $filterValue = null;
	
	/**
	 * @var $fieldTypes FieldSet
	 * Caution: Use {@setExtraData()} instead of manually adding HiddenFields if you want to 
	 * preset relations or other default data.
	 */
	protected $fieldTypes;
	
	protected $sourceSort;
	
	protected $sourceJoin;

	/**
	 * @var $template string Template-Overrides
	 */
	protected $template = "TableField";
	
	/**
	 * @var $extraData array Any extra data that need to be included, e.g. to retain
	 * has-many relations. Format: array('FieldName' => 'Value')
	 */
	protected $extraData;
	
	protected $tempForm;
	
	/**
	 * Influence output without having to subclass the template.
	 */
	protected $permissions = array(
		"edit",
		"delete",
		"add",
		//"export",
	);
	
	public $transformationConditions = array();
	
	/**
	 * @var $requiredFields array Required fields as a numerical array.
	 * Please use an instance of Validator on the including
	 * form.
	 */
	protected $requiredFields = null;
	
	/**
	 * Shows a row of empty fields for adding a new record
	 * (turned on by default). 
	 * Please use {@link TableField::$permissions} to control
	 * if the "add"-functionality incl. button is shown at all.
	 * 
	 * @param boolean $showAddRow
	 */
	public $showAddRow = true;
	
	/**
	 * Automatically detect a has-one relationship
	 * in the popup (=child-class) and save the relation ID.
	 *
	 * @var boolean
	 */
	protected $relationAutoSetting = true;

	function __construct($name, $sourceClass, $fieldList = null, $fieldTypes, $filterField = null, 
						$sourceFilter = null, $editExisting = true, $sourceSort = null, $sourceJoin = null) {
		
		$this->fieldTypes = $fieldTypes;
		$this->filterField = $filterField;
		
		$this->editExisting = $editExisting;

		// If we specify filterField, then an implicit source filter of "filterField = sourceFilter" is used.
		if($filterField) {
			$this->filterValue = $sourceFilter;
			$sourceFilter = "\"$filterField\" = '" . Convert::raw2sql($sourceFilter) . "'";
		}
		parent::__construct($name, $sourceClass, $fieldList, $sourceFilter, $sourceSort, $sourceJoin);
	}
	
	/** 
	 * Displays the headings on the template
	 * 
	 * @return DataObjectSet
	 */
	function Headings() {
		$i=0;
		foreach($this->fieldList as $fieldName => $fieldTitle) {
			$extraClass = "col".$i;
			$class = $this->fieldTypes[$fieldName];
			if(is_object($class)) $class = "";
			$class = $class." ".$extraClass;
			$headings[] = new ArrayData(array("Name" => $fieldName, "Title" => $fieldTitle, "Class" => $class));
			$i++;
		}
		return new DataObjectSet($headings);
	}
	
	/**
	 * Calculates the number of columns needed for colspans
	 * used in template
	 * 
	 * @return int
	 */
	function ItemCount() {
		return count($this->fieldList);
	}
	
	/**
	 * Returns the databased saved items, from DataObjects
	 * 
	 * @return DataObjectSet
	 */
	function sourceItems() {
		if($this->customSourceItems) {
			$items = $this->customSourceItems;
		} elseif($this->cachedSourceItems) {
			$items = $this->cachedSourceItems;
		} else {
			// get query
			$dataQuery = $this->getQuery();
			// get data
			$records = $dataQuery->execute();
			$items = singleton($this->sourceClass)->buildDataObjectSet($records);
		}

		return $items;
	}
	
	/**
	 * Displays the items from {@link sourceItems()} using the encapsulation object.
	 * If the field value has been set as an array (e.g. after a failed validation),
	 * it generates the rows from array data instead.
	 * Used in the formfield template to iterate over each row.
	 * 
	 * @return DataObjectSet Collection of {@link TableField_Item}
	 */
	function Items() {
		$output = new DataObjectSet();
		if($items = $this->sourceItems()) {
			foreach	($items as $item) {
				// Load the data in to a temporary form (for correct field types)
				$fieldset = $this->FieldSetForRow();
				if($fieldset){
					$form = new Form($this, null, $fieldset, new FieldSet());
					$form->loadDataFrom($item);
	 				// Add the item to our new DataObjectSet, with a wrapper class.
					$output->push(new TableField_Item($item, $this, $form, $this->fieldTypes));
				}
			}
		}
		// Create a temporary DataObject
		if($this->Can('add')) {
			if($this->showAddRow){
				$output->push(new TableField_Item(null, $this, null, $this->fieldTypes, true));
			}
		}
		return $output;
	}
	
	/**
	 * Get all fields for each row contained in the TableField.
	 * Does not include the empty row.
	 * 
	 * @return array
	 */
	function FieldSet() {
		$fields = array ();
		if($items = $this->sourceItems()) {
			foreach($items as $item) {
				// Load the data in to a temporary form (for correct field types)
				$fieldset = $this->FieldSetForRow();
				if ($fieldset)
				{
					// TODO Needs to be attached to a form existing in the DOM-tree
					$form = new Form($this, 'EditForm', $fieldset, new FieldSet());
					$form->loadDataFrom($item);
					$row = new TableField_Item($item, $this, $form, $this->fieldTypes);
					$fields = array_merge($fields, $row->Fields()->toArray());
				}
			}
		}

		return $fields;
	}
	
	function SubmittedFieldSet(&$sourceItems){
		$fields = array ();
		if(isset($_POST[$this->name])&&$rows = $_POST[$this->name]){
			if(count($rows)){
				foreach($rows as $idx => $row){
					if($idx == 'new'){
						$newitems = ArrayLib::invert($row);
						if(count($newitems)){
							$sourceItems = new DataObjectSet();
							foreach($newitems as $k => $newitem){
								$fieldset = $this->FieldSetForRow();
								if($fieldset){
									$newitem['ID'] = "new".$k;
									foreach($newitem as $k => $v){
										if($this->extraData && array_key_exists($k, $this->extraData)){
											unset($newitem[$k]);
										}
									}
									$sourceItem = new DataObject($newitem);
									if(!$sourceItem->isEmpty()){
										$sourceItems->push($sourceItem);
										$form = new Form($this, "EditForm", $fieldset, new FieldSet());
										$form->loadDataFrom($sourceItem);
										$item = new TableField_Item($sourceItem, $this, $form, $this->fieldTypes);
										$fields = array_merge($fields, $item->Fields()->toArray());
									}
								}
							}
						}
					}
				}
			}
		}
		return $fields;
	}
	
	/**
	 * @return array
	 */
	function FieldList() {
		return $this->fieldList;
	}
	
	/** 
	 * Saves the Dataobjects contained in the field
	 */
	function saveInto(DataObject $record) {
		// CMS sometimes tries to set the value to one.
		if(is_array($this->value)){
			// Sort into proper array
			$value = ArrayLib::invert($this->value);
			$dataObjects = $this->sortData($value, $record->ID);
			
			// New fields are nested in their own sub-array, and need to be sorted separately
 			if(isset($dataObjects['new']) && $dataObjects['new']) {
 				$newFields = $this->sortData($dataObjects['new'], $record->ID);
 			}

			// Update existing fields
			// @todo Should this be in an else{} statement?
			$savedObjIds = $this->saveData($dataObjects, $this->editExisting);
			
			// Save newly added record
			if($savedObjIds || (isset($newFields) && $newFields)) {
				$savedObjIds = $this->saveData($newFields,false);
 			}

			// Optionally save the newly created records into a relationship
			// on $record. This assumes the name of this formfield instance
			// is set to a relationship name on $record.
			if($this->relationAutoSetting) {
				$relationName = $this->Name();
				if($record->has_many($relationName) || $record->many_many($relationName)) {
					if($savedObjIds) foreach($savedObjIds as $id => $status) {
						$record->$relationName()->add($id);
					}	
				}
			}

			// Update the internal source items cache
			$items = $this->sourceItems();
			
			FormResponse::update_dom_id($this->id(), $this->FieldHolder());
		}
	}
	
	/**
	 * Get all {@link FormField} instances necessary for a single row,
	 * respecting the casting set in {@link $fieldTypes}.
	 * Doesn't populate with any data. Optionally performs a readonly
	 * transformation if {@link $IsReadonly} is set, or the current user
	 * doesn't have edit permissions.
	 * 
	 * @return FieldSet
	 */
	function FieldSetForRow() {
		$fieldset = new FieldSet();
		if($this->fieldTypes){
			foreach($this->fieldTypes as $key => $fieldType) {
				if(isset($fieldType->class) && is_subclass_of($fieldType, 'FormField')) {
					// using clone, otherwise we would just add stuff to the same field-instance
					$field = clone $fieldType;
				} elseif(strpos($fieldType, '(') === false) {
					$field = new $fieldType($key);
				} else {
					$fieldName = $key;
					$fieldTitle = "";
					$field = eval("return new $fieldType;");
				}
				if($this->IsReadOnly || !$this->Can('edit')) {
					$field = $field->performReadonlyTransformation();
				}
				$fieldset->push($field);
			}
		}else{
			USER_ERROR("TableField::FieldSetForRow() - Fieldtypes were not specified",E_USER_WARNING);
		}

		return $fieldset;
	}
	
	function performReadonlyTransformation() {
		$clone = clone $this;
		$clone->permissions = array('show');
		$clone->setReadonly(true);
		return $clone;
	}

	function performDisabledTransformation() {
		$clone = clone $this;
		$clone->setPermissions(array('show'));
		$clone->setDisabled(true);
		return $clone;
	}
	
	/**
	 * Needed for Form->callfieldmethod.
	 */
	public function getField($fieldName, $combinedFieldName = null) {
		$fieldSet = $this->FieldSetForRow();
		$field = $fieldSet->dataFieldByName($fieldName);
		if(!$field) {
			return false;
		}
		
		if($combinedFieldName) {
			$field->Name = $combinedFieldName;
		}

		return $field;
	}
		
	/**
	 * Called on save, it creates the appropriate objects and writes them
	 * to the database.
	 * 
	 * @param DataObjectSet $dataObjects
	 * @param boolean $existingValues If set to TRUE, it tries to find existing objects
	 *  based on the database IDs passed as array keys in $dataObjects parameter.
	 *  If set to FALSE, it will always create new object (default: TRUE)
	 * @return array Array of saved object IDs in the key, and the status ("Updated") in the value
	 */
	function saveData($dataObjects, $existingValues = true) {
		if(!$dataObjects) return false;

		$savedObjIds = array();
		$fieldset = $this->FieldSetForRow();

		// add hiddenfields
		if($this->extraData) {
			foreach($this->extraData as $fieldName => $fieldValue) {
				$fieldset->push(new HiddenField($fieldName));
			}
		}

		$form = new Form($this, null, $fieldset, new FieldSet());

		foreach ($dataObjects as $objectid => $fieldValues) {
			// 'new' counts as an empty column, don't save it
			if($objectid === "new") continue;

			// extra data was creating fields, but
			if($this->extraData) {
				$fieldValues = array_merge( $this->extraData, $fieldValues );
			}

			// either look for an existing object, or create a new one
			if($existingValues) {
				$obj = DataObject::get_by_id($this->sourceClass, $objectid);
			} else {
				$sourceClass = $this->sourceClass;
				$obj = new $sourceClass();
			}

			// Legacy: Use the filter as a predefined relationship-ID 
			if($this->filterField && $this->filterValue) {
				$filterField = $this->filterField;
				$obj->$filterField = $this->filterValue;
			}

			// Determine if there is changed data for saving
			$dataFields = array();
			foreach($fieldValues as $type => $value) {
				// if the field is an actual datafield (not a preset hiddenfield)
				if(is_array($this->extraData)) { 
					if(!in_array($type, array_keys($this->extraData))) {
						$dataFields[$type] = $value;
					}
				// all fields are real 
				} else {  
					$dataFields[$type] = $value;
				}
			}
			$dataValues = ArrayLib::array_values_recursive($dataFields);
			// determine if any of the fields have a value (loose checking with empty())
			$hasData = false;
			foreach($dataValues as $value) {
				if(!empty($value)) $hasData = true;
			}

			if($hasData) {
				$form->loadDataFrom($fieldValues, true);
				$form->saveInto($obj);

				$objectid = $obj->write();

				$savedObjIds[$objectid] = "Updated";
			}

		}

		return $savedObjIds;
   }
	
	/** 
	 * Organises the data in the appropriate manner for saving
	 * 
	 * @param array $data 
	 * @param int $recordID
	 * @return array Collection of maps suitable to construct DataObjects
	 */
	function sortData($data, $recordID = null) {
		if(!$data) return false;
		
		$sortedData = array();
		
		foreach($data as $field => $rowData) {
			$i = 0;
			if(!is_array($rowData)) continue;
			
			foreach($rowData as $id => $value) {
				if($value == '$recordID') $value = $recordID;
				
				if($value) $sortedData[$id][$field] = $value;

				$i++;
			}
			
			// TODO ADD stuff for removing rows with incomplete data
		}
		
    	return $sortedData;
	}
	
	/**
	 * @param $extraData array
	 */
	function setExtraData($extraData) {
		$this->extraData = $extraData;
	}
	
	/**
	 * @return array
	 */
	function getExtraData() {
		return $this->extraData;
	}
	
	/**
	 * Sets the template to be rendered with
	 */
	function FieldHolder() {
		Requirements::javascript(THIRDPARTY_DIR . '/prototype.js');
		Requirements::javascript(THIRDPARTY_DIR . '/behaviour.js');
		Requirements::javascript(THIRDPARTY_DIR . '/prototype_improvements.js');
		Requirements::javascript(THIRDPARTY_DIR . '/scriptaculous/effects.js');
		Requirements::add_i18n_javascript(SAPPHIRE_DIR . '/javascript/lang');
		Requirements::javascript(SAPPHIRE_DIR . '/javascript/TableListField.js');
		Requirements::javascript(SAPPHIRE_DIR . '/javascript/TableField.js');
		Requirements::css(SAPPHIRE_DIR . '/css/TableListField.css');
		
		return $this->renderWith($this->template);
	}
	
	/**
	 * @return Int
	 */
	function sourceID() {
		return $this->filterField;
	}
		
	function setTransformationConditions($conditions) {
		$this->transformationConditions = $conditions;
	}
	
	function jsValidation() {
		$js = "";

		$fields = $this->FieldSet();
		$fields = new FieldSet($fields);
		// TODO doesn't automatically update validation when adding a row
		foreach($fields as $field) {
			//if the field type has some special specific specification for validation of itself
			$js .= $field->jsValidation($this->form->class."_".$this->form->Name()); 
		}
		
		// TODO Implement custom requiredFields
		$items = $this->sourceItems(); 
		if($items && $this->requiredFields && $items->count()) {
			foreach ($this->requiredFields as $field) {
				/*if($fields->dataFieldByName($field)) {
					$js .= "\t\t\t\t\trequire('$field');\n";
				}*/
				foreach($items as $item){
					$cellName = $this->Name().'['.$item->ID.']['.$field.']';
					$js .= "\n";
					if($fields->dataFieldByName($cellName)) {
						$js .= <<<JS
if(typeof fromAnOnBlur != 'undefined'){
	if(fromAnOnBlur.name == '$cellName')
		require(fromAnOnBlur);
}else{
	require('$cellName');
}
JS;
					}
				}
			}
		}

		return $js;
	}
	
	function php($data) {
		$valid = true;
		
		if($data['methodName'] != 'delete'){
			$fields = $this->FieldSet();
			$fields = new FieldSet($fields);
			foreach($fields as $field){
				$valid = $field->validate($this) && $valid;
			}
			return $valid;
		}else{
			return $valid;
		}
	}
	
	function validate($validator) {
		$errorMessage = '';
		$valid = true;
		$fields = $this->SubmittedFieldSet($sourceItemsNew);
		$fields = new FieldSet($fields);
		foreach($fields as $field){
			$valid = $field->validate($validator)&&$valid;
		}

		//debug::show($this->form->Message());
		if($this->requiredFields&&$sourceItemsNew&&$sourceItemsNew->count()) {
			foreach ($this->requiredFields as $field) {
				foreach($sourceItemsNew as $item){
					$cellName = $this->Name().'['.$item->ID.']['.$field.']';
					$cName =  $this->Name().'[new]['.$field.'][]';
					
					if($fieldObj = $fields->dataFieldByName($cellName)) {
						if(!trim($fieldObj->Value())){
							$title = $fieldObj->Title();
							$errorMessage .= sprintf(
								_t('TableField.ISREQUIRED', "In %s '%s' is required."),
								$this->name,
								$title
							);
							$errorMessage .= "<br />";
						}
					}
				}
			}
		}

		if($errorMessage){
			$messageType .= "validation";
			$message .= "<br />".$errorMessage;
		
			$validator->validationError($this->name, $message, $messageType);
		}

		return $valid;
	}
	
	function setRequiredFields($fields) {
		$this->requiredFields = $fields;
	}
	
	/**
	 * @param boolean $value 
	 */
	function setRelationAutoSetting($value) {
		$this->relationAutoSetting = $value;
	}
	
	/**
	 * @return boolean
	 */
	function getRelationAutoSetting() {
		return $this->relationAutoSetting;
	}
}

/**
 * Single record in a TableField.
 * @package forms
 * @subpackage fields-relational
 * @see TableField
 */ 
class TableField_Item extends TableListField_Item {
	
	/**
	 * @var FieldSet $fields
	 */
	protected $fields;
	
	protected $data;
	
	protected $fieldTypes;
	
	protected $isAddRow;
	
	protected $extraData;
	
	/** 
	 * Each row contains a dataobject with any number of attributes
	 * @param $ID int The ID of the record
	 * @param $form Form A Form object containing all of the fields for this item.  The data should be loaded in
	 * @param $fieldTypes array An array of name => fieldtype for use when creating a new field
	 * @param $parent TableListField The parent table for quick reference of names, and id's for storing values.
	 */
	function __construct($item = null, $parent, $form, $fieldTypes, $isAddRow = false) {
		$this->data = $form;
		$this->fieldTypes = $fieldTypes;
		$this->isAddRow = $isAddRow;
		$this->item = $item;

		parent::__construct(($this->item) ? $this->item : new DataObject(), $parent);
		
		$this->fields = $this->createFields();
	}
	/** 
	 * Represents each cell of the table with an attribute.
	 *
	 * @return FieldSet
	 */
	function createFields() {
		// Existing record
		if($this->item && $this->data) {
			$form = $this->data;
			$this->fieldset = $form->Fields();
			$this->fieldset->removeByName('SecurityID');
			if($this->fieldset) {
				$i=0;
				foreach($this->fieldset as $field) {
					$origFieldName = $field->Name();

					// set unique fieldname with id
					$combinedFieldName = $this->parent->Name() . "[" . $this->ID . "][" . $origFieldName . "]";
					if($this->isAddRow) $combinedFieldName .= '[]';
					
					// get value
					if(strpos($origFieldName,'.') === false) {
						$value = $field->dataValue();
					} else {					
						// this supports the syntax fieldName = Relation.RelatedField				
						$fieldNameParts = explode('.', $origFieldName)	;
						$tmpItem = $this->item;
						for($j=0;$j<sizeof($fieldNameParts);$j++) {
							$relationMethod = $fieldNameParts[$j];
							$idField = $relationMethod . 'ID';
							if($j == sizeof($fieldNameParts)-1) {
								$value = $tmpItem->$relationMethod;
							} else {
								$tmpItem = $tmpItem->$relationMethod();
							}
						}
					}
					
					$field->Name = $combinedFieldName;
					$field->setValue($field->dataValue());
					$field->addExtraClass('col'.$i);
					$field->setForm($this->data); 

					// transformation
					if(isset($this->parent->transformationConditions[$origFieldName])) {
						$transformation = $this->parent->transformationConditions[$origFieldName]['transformation'];
						$rule = str_replace("\$","\$this->item->", $this->parent->transformationConditions[$origFieldName]['rule']);
						$ruleApplies = null;
						eval('$ruleApplies = ('.$rule.');');
						if($ruleApplies) {
							$field = $field->$transformation();
						}
					}
					
					// formatting
					$item = $this->item;
					$value = $field->Value();
					if(array_key_exists($origFieldName, $this->parent->fieldFormatting)) {
						$format = str_replace('$value', "__VAL__", $this->parent->fieldFormatting[$origFieldName]);
						$format = preg_replace('/\$([A-Za-z0-9-_]+)/','$item->$1', $format);
						$format = str_replace('__VAL__', '$value', $format);
						eval('$value = "' . $format . '";');
						$field->dontEscape = true;
						$field->setValue($value);
					}
					
					$this->fields[] = $field;
					$i++;
				}
			}
		// New record
		} else {
			$list = $this->parent->FieldList();
			$i=0;
			foreach($list as $fieldName => $fieldTitle) {
				if(strpos($fieldName, ".")) {
					$shortFieldName = substr($fieldName, strpos($fieldName, ".")+1, strlen($fieldName));
				} else {
					$shortFieldName = $fieldName;
				}
				$combinedFieldName = $this->parent->Name() . "[new][" . $shortFieldName . "][]";
				$fieldType = $this->fieldTypes[$fieldName];
				if(isset($fieldType->class) && is_subclass_of($fieldType, 'FormField')) {
					$field = clone $fieldType; // we can't use the same instance all over, as we change names
					$field->Name = $combinedFieldName;
				} elseif(strpos($fieldType, '(') === false) {
					//echo ("<li>Type: ".$fieldType." fieldName: ". $filedName. " Title: ".$fieldTitle."</li>");
					$field = new $fieldType($combinedFieldName,$fieldTitle);
				} else {
					$field = eval("return new " . $fieldType . ";");
				}
				$field->addExtraClass('col'.$i);
				$this->fields[] = $field;
				$i++;
			}
		}
		return new FieldSet($this->fields);
	}
	
	function Fields() {
		return $this->fields;
	}
	
	function ExtraData() {
		$content = ""; 
		$id = ($this->item->ID) ? $this->item->ID : "new";
		if($this->parent->getExtraData()) {
			foreach($this->parent->getExtraData() as $fieldName=>$fieldValue) {
				$name = $this->parent->Name() . "[" . $id . "][" . $fieldName . "]";
				if($this->isAddRow) $name .= '[]';
				$field = new HiddenField($name, null, $fieldValue);
				$content .= $field->FieldHolder() . "\n";
			}
		}

		return $content;
	}
	
	/**
	 * Get the flag isAddRow of this item, 
	 * to indicate if the item is that blank last row in the table which is not in the database
	 * 
	 * @return boolean
	 */
	function IsAddRow(){
		return $this->isAddRow;
	}
	
}

?>