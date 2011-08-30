<?php

/**
 * Decorates a DataObject, giving it the ability to be indexed and searched by 
 * Lucene.
 * 
 * To define custom columns, you should this line into your site _config.php:
 *
 * <code>
 * LuceneSearchable::enable(array());
 * </code>
 *
 * This sets up things without defining columns for any classes.
 *
 * After this, you can define non-default columns to index for each object by using:
 *
 * <code>
 * Object::add_extension(
 *      'SiteTree',
 *      "LuceneSearchable('Title,MenuTitle,Content,MetaTitle,MetaDescription,MetaKeywords')"
 * );
 * </code>
 *
 * See the docs for the __construct function for advanced config options.
 *
 * @package lucene-silverstripe-module
 * @author Darren Inwood <darren.inwood@chrometoaster.com>
 */

class LuceneSearchable extends DataObjectDecorator {
    
    /**
     * Fields which are also indexed in addition to content fields.
     * @static
     */
    protected static $extraSearchFields = array('ID','ClassName','LastEdited');

    /**
     * Fields which should be indexed as 'unstored' by default.
     * @access private
     * @static
     */
    protected static $unstored_fields = array(
        'MenuTitle', 'MetaTitle', 'MetaDescription', 'MetaKeywords'
    );

    /**
     * Fields which should be indexed as 'unindexed' by default.
     * @access private
     * @static
     */
    protected static $unindexed_fields = array(
        'LastEdited', 'Created'
    );

    /**
     * Fields which should be indexed as 'keyword' by default.
     * @access private
     * @static
     */    
    protected static $keyword_fields = array(
        'ID', 'ClassName'
    );

    /** 
     * The fields which can be searched for each DataObject class, and indexing
     * info for each field.  This is not public, and should be set by configuring 
     * the class via the Object::add_extension argument.
     * @access private
     */
    protected $fieldConfig = null;

    /**
     * The raw string used to configure the object's fields.  We have to store it and parse
     * it on first use at runtime, because the owner of the decorator isn't set in 
     * the constructor.
     */
    protected $_fieldConfig = null;

    /**
     * The config options for this DataObject class.  This is not public, and 
     * should be set by configuring the class via the Object::add_extension argument.
     */
    protected $classConfig = null;

    /**
     * The raw string used to configure the object.  We have to store it and parse
     * it on first use at runtime, because the owner of the decorator isn't set in 
     * the constructor.
     */
    protected $_classConfig = null;

    /**
     * Called by the system when initialising each instance of the decorated 
     * class.  The argument comes from the Object::add_extension call in the 
     * project's _config.php file.
     *
     * Field configuration can simply be a comma-separated list of fieldnames to scan,
     * or alternatively can be a json-encoded string containing extended config 
     * information.  Or you can leave it blank.
     * 
     * All keys are optional for each fieldname.  The format for $fieldConfig is:
     *
     * <code>
     * array(
     *    'FieldName' => array(
     *        'name' => 'StoredName',
     *        'type' => 'keyword|unindexed|unstored|text',
     *        'content_filter' => [callback]|false
     *     ),
     *     [...]
     * )
     * </code>
     * 
     * FieldName - the name of the field on the decorated class
     *
     * name - the name to store this as in the document.  Default is the same as
     * the FieldName.  The FieldName of 'ID' is a special case, this will always 
     * use a name of 'ObjectID' as this is used internally.
     * 
     * type - the type of indexing to use.  Default is 'text'.
     * 
     * content_filter - a callback that should be used to transform the field value
     * prior to being indexed.  The callback will be called with one argument, 
     * the field value as a string, and should return the transformed field value
     * also as a string.  Could be useful for eg. turning date strings into unix 
     * timestamps prior to indexing.  A value of false will indicate that there
     * should be no content filtering, which is the default.
     * 
     * Class-level configuration should be a json-encoded string containing 
     * key-value pairs for the class-level config options.  The format for 
     * $classConfig is:
     *
     * <code>
     * array(
     *     'index_filter' => 'WHERE clause'
     * )
     * </code>
     * 
     * index_filter - a string to be used as the second argument to 
     * DataObject::get().  An example of how this might be used in a complex 
     * situation:
     *
     * '"ID" IN ( SELECT "ID" FROM "This" LEFT JOIN "Other" ON "This"."ID" = "Other"."ThisID" WHERE "Other"."ThisID" IS NOT NULL )'
     */
	public function __construct($fieldConfig=null, $classConfig=null) {
		parent::__construct();
		$this->_fieldConfig = $fieldConfig;
		$this->_classConfig = $classConfig;
	}

    /**
     * Set up the config for each instantiated object for this class.
     * @access private
     */
    private function setLuceneFieldConfig($config) {
        $this->fieldConfig = array();
        if ( ! $config ) return;

        // Is it a JSON-encoded array?
        $json = json_decode($config, true);
        if ( is_array($json) ) $config = $json;

        // Is is a comma-separated string?
        if ( ! is_array($config) ) {
            $config = explode(',', $config);
            if ( is_array($config) ) $config = array_flip($config);
        }

        // Is the config bad?
	      if ( ! is_array($config) ) {
	          user_error('Your Lucene field config for the class '.$this->owner->class
	              .' was bad. It needs to be a JSON encoded array, or a comma-separated'
	              .' list of fieldnames.  See the documentation for details.');
	      }

        // Do we have ObjectID in there?  Users can't use that, as it's how we track
        // the ID field internally.
        if ( 
            array_key_exists('ObjectID', $config) 
            && (
                !is_array($config['ObjectID']) 
                || !isset($config['ObjectID']['name'])
                || $config['ObjectID']['name'] == 'ObjectID'
            )
        ) {
            user_error('ObjectID is reserved for internal Lucene use. Try configuring '
            .'that field to be indexed using a different name via the \'name\' config option. '
            .'See the documentation for details.');
            
        }

        // Also configure extra search fields using default config options
        $config = array_merge(array_flip($this->getExtraSearchFields()), $config);

	      // Set up default info for each field if nothing was provided.
	      foreach( $config as $fieldName => $data ) {
            if ( ! is_array($data) ) $data = array();
            $tmp = array(
                'name' => $fieldName,
                'type' => false,
                'boost' => '1.0',
                'content_filter' => false
            );
            $tmp = array_merge($tmp, $data);
            // Default to unstored indexing
            if ( !$tmp['type'] && in_array($fieldName, self::$unstored_fields) ) {
                $tmp['type'] = 'unstored';
            }
            // Default to unindexed indexing
            if ( !$tmp['type'] && in_array($fieldName, self::$unindexed_fields) ) {
                $tmp['type'] = 'unindexed';
            }
            // Default to keyword indexing
            if ( !$tmp['type'] && in_array($fieldName, self::$keyword_fields) ) {
                $tmp['type'] = 'keyword';
            }
            if ( !$tmp['type'] ) $tmp['type'] = 'unstored';
            $this->fieldConfig[$fieldName] = $tmp;
	      }
    }

    /**
     * Returns the field config array for a given field.  Throws a user_error if
     * the field doesn't exist in the config.
     *
     * <code>
     * array(
     *     'name' => 'FieldName',
     *     'type' => 'keyword|unindexed|unstored|text',
     *     'content_filter' => [callback]|false
     * )
     * </code>
     */
    public function getLuceneFieldConfig($fieldName) {
        if ( $this->fieldConfig === null ) $this->setLuceneFieldConfig($this->_fieldConfig);
        if ( array_key_exists($fieldName, $this->fieldConfig) ) {
            return $this->fieldConfig[$fieldName];
        }
        user_error("You asked for the config for field $fieldName, which isn't configured.");
    }

    /**
     * Set up search configuration for the class.
     * @access private
     */
    private function setLuceneClassConfig($config) {
        // Default to filtering out unpublished and unsearchable SiteTree objects
        $this->classConfig = array(
            'index_filter' => $this->owner->is_a('SiteTree') 
                ? "\"Status\" = 'Published' AND \"ShowInSearch\" = 1"
                : ''
        );
        if ( ! $config ) return;

        // Is it a JSON-encoded array?
        $json = json_decode($config, true);
        if ( is_array($json) ) $config = $json;

        // Is the config bad?
	      if ( ! is_array($config) ) {
	          user_error(
	              'Your Lucene class config for the class '.$this->owner->class
	              .' was bad. It needs to be a JSON encoded array.  See the '
	              .'documentation for details.'
	          );
	      }
    
        $this->classConfig = $config;
    }

    /**
     * Returns the class config array.
     * Should be called as singleton('ClassName')->getLuceneClassConfig().
     */
    public function getLuceneClassConfig() {
        if ( $this->classConfig === null ) $this->setLuceneClassConfig($this->_classConfig);
        return $this->classConfig;
    }

    /**
     * Indexes the object after it has been written to the database.
     */
    public function onAfterWrite() {
        parent::onAfterWrite();
        $lucene =& Lucene::singleton();        
        $lucene->index($this->owner);
    }

    /**
     * Removes the object from the search index after it has been deleted.
     */
    function onAfterDelete() {
        $lucene =& Lucene::singleton();        
        $lucene->delete($this->owner);
        parent::onAfterDelete();
    }

    /**
     * Return an array of search field names.
     * 
     * @return  Array   An array of strings, each being the name of a field that 
     *                  is searched.
     */
    public function getSearchedVars() {
        return self::$extraSearchFields + $this->getSearchFields() + array('Link');
    }

    /**
     * Get an array of the class' searched fieldnames from other classes.
     *
     * @return  Array   An array of strings, each being the name of a field that
     *                  is indexed.
     */
    public function getSearchFields() {
        if ( $this->fieldConfig === null ) $this->setLuceneFieldConfig($this->_fieldConfig);
        return array_keys($this->fieldConfig);
    }

    /**
     * Get an array of the class' extra search fieldnames that are indexed but 
     * not searched.
     *
     * @return  Array   An array of strings, each being the name of a field that 
     *                  is indexed but not searched.
     */
    public function getExtraSearchFields() {
        return self::$extraSearchFields;
    }

    /**
     * Rebuilds the search index whenever a dev/build is run.
     *
     * This can be turned off by adding the following to your _config.php:
     *
     * <code>
     * ZendSearchLuceneSearchable::$reindexOnDevBuild = false;
     * </code>
    public function requireDefaultRecords() {
        if ( ! self::$reindexOnDevBuild ) return;
        ZendSearchLuceneWrapper::rebuildIndex();
        echo '<li><em>'
            . _t('ZendSearchLucene.RebuildSuccessMessage', 'A Lucene search index rebuild job has been added to the Jobs queue.')
            .'</em></li>';
        // Only run once
        self::$reindexOnDevBuild = false;
    }
     */

}
