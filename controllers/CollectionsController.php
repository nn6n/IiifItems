<?php

/**
 * Controller for IIIF collections
 * @package controllers
 */
class IiifItems_CollectionsController extends IiifItems_BaseController {
    protected $_browseRecordsPerPage = self::RECORDS_PER_PAGE_SETTING;
    
    /**
     * Marks the default model type for this controller.
     */
    public function init() {
        $this->_helper->db->setDefaultModelName('Collection');     
    }
    
    /**
     * The page for browsing submembers of a collection-type collection.
     * GET collections/:id/members
     */
    public function membersAction() {
        $db = get_db();
        $parentUuidElementId = get_option('iiifitems_collection_parent_element');
        $parentCollection = get_record_by_id('Collection', $this->getParam('id'));
        $parentUuid = raw_iiif_metadata($parentCollection, 'iiifitems_collection_uuid_element');
        $matches = $db->getTable('ElementText')->findBySql('element_texts.element_id = ? AND element_texts.record_type = ? AND element_texts.text = ?', array(
            $parentUuidElementId,
            'Collection',
            $parentUuid,
        ));
        $matchIds = array();
        foreach ($matches as $match) {
            $matchIds[] = $match->record_id;
        }
        if (empty($matchIds)) {
            $this->view->parentCollection = $parentCollection;
            $this->view->collections = array();
            $this->view->total_results = 0;
        } else {
            $query = 'collections.id IN (' . implode(',', array_fill(0, count($matches), '?')) . ')';
            $table = $db->getTable('Collection');
            $sortField = $this->_getParam('sort_field') ? $_GET['sort_field'] : 'added';
            $sortOrder = ($this->_getParam('sort_dir') ? (($_GET['sort_dir'] == 'd') ? 'DESC' : 'ASC') : 'ASC');
            $select = $table->getSelectForFindBy()->where($query, $matchIds);
            $recordsPerPage = $this->_getBrowseRecordsPerPage();
            $currentPage = $this->getParam('page', 1);
            $this->_helper->db->applySorting($select, $sortField, $sortOrder);
            $this->_helper->db->applyPagination($select, $recordsPerPage, $currentPage);
            $this->view->parentCollection = $parentCollection;
            $this->view->collections = $table->fetchObjects($select);
            $this->view->total_results = count($matches);
            $this->view->sort_field = $sortField;
            $this->view->sort_order = $sortOrder;
            // Add pagination data to the registry. Used by pagination_links().
            if ($recordsPerPage) {
                Zend_Registry::set('pagination', array(
                    'page' => $currentPage, 
                    'per_page' => $recordsPerPage, 
                    'total_results' => count($matchIds), 
                ));
            }
        }
        if (!is_admin_theme()) {
            $this->render('browse');
        }
    }
    
    /**
     * Renders a IIIF-compliant collection, in collection-manifest form.
     * GET oa/collections/:id/collection.json
     * 
     * @throws Omeka_Controller_Exception_404
     */
    public function collectionAction() {
        // Get and check the collection's existence
        $collection = get_record_by_id('Collection', $this->getParam('id'));
        if (empty($collection) || raw_iiif_metadata($collection, 'iiifitems_collection_type_element') != 'Collection') {
            throw new Omeka_Controller_Exception_404;
        }
        //Respond with JSON
        try {
            $jsonData = IiifItems_Util_Collection::buildCollection($collection);
            $this->__respondWithJson($jsonData);
        } catch (Exception $e) {
            $this->__respondWithJson(array(
                'message' => $e->getMessage(),
            ), 500);
        }
    }
    
    /**
     * Renders a IIIF explorer view for the subtree starting at this collection.
     * GET collections/:id/explorer
     * 
     * @throws Omeka_Controller_Exception_404
     */
    public function explorerAction() {
        // Get and check the collection's existence
        $collection = get_record_by_id('Collection', $this->getParam('id'));
        if (empty($collection) || raw_iiif_metadata($collection, 'iiifitems_collection_type_element') != 'Collection') {
            throw new Omeka_Controller_Exception_404;
        }
        // Pass collection to view
        $this->view->collection = $collection;
    }
    
    /**
     * Renders the top-level collection for the installation.
     * GET oa/top.json
     */
    public function topAction() {
        // Get parent-less collections
        $collections = array();
        foreach (IiifItems_Util_Collection::findTopCollections() as $collection) {
            $atId = public_full_url(array('things' => 'collections', 'id' => $collection->id, 'typeext' => 'collection.json'), 'iiifitems_oa_uri');
            $label = metadata($collection, array('Dublin Core', 'Title'), array('no_escape' => true));
            $collections[] = IiifItems_Util_Collection::bareTemplate($atId, $label);
        }
        // Get parent-less manifests
        $manifests = array();
        foreach (IiifItems_Util_Collection::findTopManifests() as $manifest) {
            $atId = public_full_url(array('things' => 'collections', 'id' => $manifest->id, 'typeext' => 'manifest.json'), 'iiifitems_oa_uri');
            $label = metadata($manifest, array('Dublin Core', 'Title'), array('no_escape' => true));
            $manifests[] = IiifItems_Util_Manifest::bareTemplate($atId, $label);
        }
        // Merge and serve
        $atId = public_full_url();
        $this->__respondWithJson(IiifItems_Util_Collection::blankTemplate($atId, get_option('site_title'), $manifests, $collections));
    }
}